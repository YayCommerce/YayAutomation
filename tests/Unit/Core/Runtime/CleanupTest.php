<?php
namespace YayMatik\Tests\Unit\Core\Runtime;

use PHPUnit\Framework\TestCase;
use YayMatik\Workflow\Core\Cart\CartSweep;
use YayMatik\Workflow\Core\Runtime\ActionSchedulerQueue;
use YayMatik\Workflow\Core\Runtime\Cleanup;
use YayMatik\Workflow\Core\Runtime\InactiveSweep;

/** Deactivation in yaymatik.php names Cleanup's jobs and option itself, since it loads no class. */
final class CleanupTest extends TestCase {

    public function test_deactivate_unschedules_every_recurring_job_and_forgets_the_check(): void {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
        $bootstrap = (string) file_get_contents( __DIR__ . '/../../../../yaymatik.php' );
        $this->assertSame( 1, preg_match( '/function deactivate\(\) \{(.*?)\n    \}/s', $bootstrap, $match ) );
        $body = $match[1];

        $this->assertStringContainsString( sprintf( "[ '%s', '%s', '%s' ]", Cleanup::HOOK, CartSweep::HOOK, InactiveSweep::HOOK ), $body );
        $this->assertStringContainsString( sprintf( "as_unschedule_all_actions( \$hook, [], '%s' )", ActionSchedulerQueue::GROUP ), $body );
        $this->assertStringContainsString( sprintf( "delete_option( '%s' )", Cleanup::CHECKED_OPTION ), $body );
        $this->assertStringContainsString( "register_deactivation_hook( __FILE__, 'YayMatik\\\\deactivate' )", $bootstrap );
    }
}
