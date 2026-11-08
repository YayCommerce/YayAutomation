<?php
namespace YayMatik\Tests\Unit\Actions;

use PHPUnit\Framework\TestCase;
use YayMatik\Tests\Support\FakeWpdb;
use YayMatik\Workflow\Actions\StopWaitingRuns;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Steps\Step;

/** Stop waiting runs: its checks, and what it skips; the cancelling itself runs in check-catalog. */
final class StopWaitingRunsTest extends TestCase {

    protected function setUp(): void {
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the workflow list it reads.
        $GLOBALS['wpdb']         = new FakeWpdb();
        $GLOBALS['wpdb']->result = [
            [
                'id'   => '3',
                'name' => 'Win-back + discount',
            ],
            [
                'id'   => '5',
                'name' => 'Cart series',
            ],
        ];
    }

    protected function tearDown(): void {
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the bootstrap's empty one.
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    /**
     * @param array $raw
     * @return true|\WP_Error
     */
    private static function check( array $raw ) {
        $step = new StopWaitingRuns();
        return $step->validate( $step->sanitize( $raw ) );
    }

    public function test_offers_every_workflow_by_name(): void {
        $this->assertSame(
            [
                '3' => 'Win-back + discount',
                '5' => 'Cart series',
            ],
            ( new StopWaitingRuns() )->fields()[0]->options()
        );
    }

    public function test_needs_a_workflow_and_an_email(): void {
        $this->assertSame( '{{ customer.email }}', ( new StopWaitingRuns() )->fields()[1]->to_array()['default'], 'a new step starts with the customer\'s email' );
        $this->assertTrue(
            self::check(
                [
                    'workflows' => [ '3' ],
                    'email'     => '{{ customer.email }}',
                ]
            )
        );
        $this->assertSame( 'Stop waiting runs needs a workflow.', self::check( [] )->get_error_message() );
        $this->assertSame(
            'Stop waiting runs needs the customer’s email.',
            self::check(
                [
                    'workflows' => [ '3' ],
                    'email'     => '',
                ]
            )->get_error_message()
        );
    }

    public function test_a_deleted_workflow_is_an_issue_on_its_field(): void {
        $error = self::check( [ 'workflows' => [ '3', '14' ] ] );
        $this->assertSame( 'A chosen workflow was deleted. Remove it from the list.', $error->get_error_message() );
        $this->assertSame( 'workflows', $error->get_error_data()['field'] );
    }

    public function test_a_failure_lets_the_run_go_on(): void {
        $this->assertSame( Step::ON_ERROR_CONTINUE, ( new StopWaitingRuns() )->on_error() );
    }

    public function test_an_invalid_email_is_skipped(): void {
        $step   = new StopWaitingRuns();
        $result = $step->run(
            new Scope(),
            $step->sanitize(
                [
                    'workflows' => [ '3' ],
                    'email'     => 'not an email',
                ]
            )
        );
        $this->assertTrue( $result->is_skipped() );
        $this->assertSame( [], $result->outputs() );
    }
}
