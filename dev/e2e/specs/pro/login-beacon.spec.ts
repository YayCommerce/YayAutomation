import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, test, type BrowserContext } from '@playwright/test';

import { resetSite } from '../../support/wp';

const root = resolve(__dirname, '../../../..');
// The plugin folder is mapped into the site, so a file here is served as is: a cached page, without PHP.
const staticDir = resolve(root, 'dev/e2e/test-results/login-beacon');
const plugin = root.split('/').pop();
const staticUrl = `/wp-content/plugins/${plugin}/dev/e2e/test-results/login-beacon/shop.html`;
const PASSWORD = 'Check-beacon-1!';

let calls = 0;

/**
 * What the code echoes. From a container (Playwright's image on a Mac) `wp-env run` relays no output, so the code
 * writes it to a file in the plugin folder, which both sides mount; the file can show up there a few seconds later.
 */
function php(code: string): string {
  const name = `out-${++calls}.txt`;
  mkdirSync(staticDir, { recursive: true });
  spawnSync(
    'pnpm',
    [
      '--silent',
      'wp-env',
      'run',
      'cli',
      'wp',
      'eval',
      `ob_start(); ${code}; file_put_contents( WP_PLUGIN_DIR . '/${plugin}/dev/e2e/test-results/login-beacon/${name}', ob_get_clean() );`,
    ],
    { cwd: root, stdio: 'ignore' },
  );
  const file = resolve(staticDir, name);
  for (let waited = 0; waited < 20_000 && !existsSync(file); waited += 250) {
    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 250);
  }
  return readFileSync(file, 'utf8').trim();
}

/** A customer with a paid order, and an active User logged in workflow on sign-in and return. */
function setUp(): { shopper: number; other: number } {
  return JSON.parse(
    php(`
      require_once ABSPATH . 'wp-admin/includes/user.php';
      $ids = [];
      foreach ( [ 'shopper', 'other' ] as $name ) {
        $old = get_user_by( 'login', $name );
        if ( $old ) {
          wp_delete_user( $old->ID );
        }
        $ids[ $name ] = wp_insert_user( [ 'user_login' => $name, 'user_email' => "{$name}@beacon.test", 'user_pass' => '${PASSWORD}', 'role' => 'customer' ] );
      }
      $trigger = [ 'id' => 'trigger', 'kind' => 'trigger', 'type' => 'trigger', 'position' => [ 'x' => 0, 'y' => 0 ], 'data' => [ 'block' => 'user_logged_in', 'config' => [ 'when' => [ 'sign_in', 'return' ], 'days' => 7, 'roles' => [] ] ] ];
      $wait    = [ 'id' => 'wait', 'kind' => 'action', 'type' => 'action', 'position' => [ 'x' => 0, 'y' => 100 ], 'data' => [ 'block' => 'wait', 'config' => [ 'delay' => [ 'value' => 1, 'unit' => 'days' ] ] ] ];
      $flow    = YayMatik\\Workflow\\Core\\Storage\\WorkflowStore::create( 'beacon', [ 'nodes' => [ $trigger, $wait ], 'edges' => [ [ 'id' => 'e1', 'source' => 'trigger', 'target' => 'wait', 'sourceHandle' => null ] ] ] );
      YayMatik\\Workflow\\Core\\Storage\\WorkflowStore::activate( (int) $flow['id'] );
      echo wp_json_encode( $ids );
    `),
  );
}

const runsFor = (user: number) =>
  Number(
    php(
      `global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}yaymatik_runs WHERE subject = %s", 'user:${user}' ) );`,
    ),
  );

/** Signs in on wp-login.php without following the redirect, so no PHP page is loaded after it. */
async function signIn(context: BrowserContext, login: string) {
  await context.request.get('/wp-login.php');
  const response = await context.request.post('/wp-login.php', {
    form: { log: login, pwd: PASSWORD, 'wp-submit': 'Log In', testcookie: '1' },
    maxRedirects: 0,
  });
  expect(response.status()).toBe(302);
}

let ids: { shopper: number; other: number };

test.beforeAll(async ({ browser }) => {
  resetSite();
  ids = setUp();
  // The shop page as a signed-in person gets it, saved as a static file: what a page cache would serve.
  const context = await browser.newContext({ storageState: undefined });
  await signIn(context, 'other');
  const html = await (await context.request.get('/shop/')).text();
  expect(html).toContain('includes/Pro/Frontend/seen.js');
  mkdirSync(staticDir, { recursive: true });
  writeFileSync(resolve(staticDir, 'shop.html'), html);
  await context.close();
});

// The site's other checks refuse to run beside an active login workflow.
test.afterAll(() => {
  php(`
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ( YayMatik\\Workflow\\Core\\Storage\\WorkflowStore::all() as $flow ) {
      if ( 'beacon' === $flow['name'] ) {
        YayMatik\\Workflow\\Core\\Storage\\WorkflowStore::deactivate( (int) $flow['id'] );
        YayMatik\\Workflow\\Core\\Storage\\WorkflowStore::delete( (int) $flow['id'] );
      }
    }
    foreach ( [ 'shopper', 'other' ] as $name ) {
      $user = get_user_by( 'login', $name );
      if ( $user ) {
        wp_delete_user( $user->ID );
      }
    }
  `);
  rmSync(staticDir, { recursive: true, force: true });
});

test('T14, T13: on a cached page the beacon confirms a sign-in, then a return', async ({ browser }) => {
  const context = await browser.newContext({ storageState: undefined });
  await signIn(context, 'shopper');
  expect(runsFor(ids.shopper)).toBe(0);

  const page = await context.newPage();
  const beacon = page.waitForRequest((request) => request.url().includes('action=yaymatik_seen'));
  await page.goto(staticUrl);
  await beacon;
  await expect.poll(() => runsFor(ids.shopper), { timeout: 15_000 }).toBe(1);

  // T13: back after 13 hours on the cached page; the claim goes, so only last seen decides.
  php(
    `global $wpdb; $wpdb->delete( $wpdb->prefix . 'yaymatik_trigger_claims', [ 'subject' => 'user:${ids.shopper}' ] ); update_user_meta( ${ids.shopper}, '_yaymatik_last_seen', time() - 13 * HOUR_IN_SECONDS );`,
  );
  await page.evaluate(() => window.localStorage.clear());
  const second = page.waitForRequest((request) => request.url().includes('action=yaymatik_seen'));
  await page.goto(staticUrl);
  await second;
  await expect.poll(() => runsFor(ids.shopper), { timeout: 15_000 }).toBe(2);

  // The 15-minute throttle: the next cached page sends no beacon.
  let sent = false;
  page.on('request', (request) => {
    if (request.url().includes('action=yaymatik_seen')) sent = true;
  });
  await page.goto(staticUrl);
  await page.waitForLoadState('networkidle');
  expect(sent).toBe(false);
  await context.close();
});
