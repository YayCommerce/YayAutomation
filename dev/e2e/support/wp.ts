import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

const root = resolve(__dirname, '../../..');

function run(command: string, args: string[]) {
  execFileSync(command, args, { cwd: root, stdio: 'pipe' });
}

function wp(...args: string[]) {
  run('pnpm', ['--silent', 'wp-env', 'run', 'cli', 'wp', ...args]);
}

/** No workflows, runs or carts, and the store seeded. */
export function resetSite() {
  // A branch switch that removes dev/wp/mu-plugins leaves Docker mounting the deleted folder until a restart.
  wp('eval', 'has_filter( "pre_wp_mail", "yay_mail_log_capture" ) || WP_CLI::error( "Mail log mu-plugin is not loaded. Run: pnpm wp-env stop && pnpm wp-env start" );');
  run('./run.sh', ['reset-data', '--wp-env', '--yes']);
  run('./run.sh', ['seed', '--wp-env']);
}

/** Runs due jobs now: wp-env's loopback can't reach the mapped port, so Action Scheduler never runs them itself. */
export function runQueue() {
  wp('action-scheduler', 'run', '--force');
}

export function completeOrder(email: string) {
  wp('eval', `wc_get_orders( [ 'billing_email' => '${email}' ] )[0]->update_status( 'completed' );`);
}

/** Marks the cart abandoned, as if the shopper left two hours ago. */
export function abandonCart(email: string) {
  wp(
    'eval',
    `global $wpdb;
    $wpdb->update( $wpdb->prefix . 'yaymatik_carts', [ 'last_activity_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ], [ 'email' => '${email}' ] );
    do_action( 'yaymatik_cart_sweep' );`,
  );
}

/** Removes the daily cleanup job, as if it were lost: the Health check reports it. */
export function removeCleanupJob() {
  wp('eval', 'as_unschedule_all_actions( "yaymatik_cleanup", [], "yaymatik" );');
}

/** Ends every wait now: the run's own time, and the job that resumes it. */
export function endWaits() {
  wp(
    'eval',
    `global $wpdb;
    $past = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
    $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}yaymatik_runs SET wait_until = %s WHERE wait_until IS NOT NULL", $past ) );
    $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}actionscheduler_actions SET scheduled_date_gmt = %s, scheduled_date_local = %s WHERE hook = 'yaymatik_run' AND status = 'pending'", $past, $past ) );`,
  );
}
