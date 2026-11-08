<?php
namespace YayMatik\Workflow\Actions;

use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;
use YayMatik\Workflow\Core\Runtime\Cancellation;
use YayMatik\Workflow\Core\Runtime\PersonSubjects;
use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\Core\Storage\WorkflowStore;

defined( 'ABSPATH' ) || exit;

/** Cancels one person's waiting runs of the chosen workflows: their orders, carts, account and reviews. */
class StopWaitingRuns extends Action {

    public const ID = 'stop_waiting_runs';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Stop waiting runs', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Stop this customer’s waiting runs of other workflows.', 'yaymatik' );
    }

    public function icon(): string {
        return 'power';
    }

    public function category(): string {
        return self::CATEGORY_FLOW;
    }

    public function requires(): array {
        return [];
    }

    /** A deleted workflow among the chosen ones fails this step only; the run goes on. */
    public function on_error(): string {
        return self::ON_ERROR_CONTINUE;
    }

    public function fields(): array {
        return [
            Field::multiselect( 'workflows', __( 'Workflows', 'yaymatik' ), [ WorkflowStore::class, 'names' ] )
                /* translators: %s: step name, e.g. "Stop waiting runs" */
                ->required( __( '%s needs a workflow.', 'yaymatik' ) )
                ->placeholder( __( 'Choose workflows', 'yaymatik' ) ),
            Field::text( 'email', __( 'Customer email', 'yaymatik' ) )
                ->variables()
                ->default( '{{ customer.email }}' )
                /* translators: %s: step name, e.g. "Stop waiting runs" */
                ->required( __( '%s needs the customer’s email.', 'yaymatik' ) ),
        ];
    }

    public function summary(): string {
        /* translators: %workflows%: workflow names; keep the placeholder as it is. */
        return __( 'Stop %workflows%', 'yaymatik' );
    }

    public function outputs(): array {
        return [
            'stopped' => [
                'label' => __( 'Runs stopped', 'yaymatik' ),
                'type'  => Type::number(),
            ],
        ];
    }

    /**
     * A chosen workflow deleted since gets its own message, before the field's generic one.
     *
     * @param array $config
     */
    public function validate( array $config ) {
        if ( array_diff( (array) $config['workflows'], array_keys( WorkflowStore::names() ) ) ) {
            return $this->invalid( self::deleted_message(), 'workflows' );
        }
        $error = $this->field_error( $config );
        return $error ? $error : true;
    }

    public function run( Scope $scope, array $config ): StepResult {
        $email = UnsubscribeLink::normalize( self::render( $config['email'], $scope ) );
        if ( '' === $email ) {
            return StepResult::skipped( __( 'There is no valid customer email.', 'yaymatik' ) );
        }
        $names   = WorkflowStore::names();
        $current = Runtime::worker() ? Runtime::worker()->current() : null;
        /* translators: %s: name of the workflow whose step stopped the runs */
        $reason  = sprintf( __( 'Stopped by workflow “%s”.', 'yaymatik' ), $names[ (string) ( $current['workflow_id'] ?? '' ) ] ?? '' );
        $stopped = Cancellation::for_subjects( PersonSubjects::for_email( $email ), array_map( 'intval', (array) $config['workflows'] ), $reason );
        return StepResult::ok( [ 'stopped' => $stopped ] );
    }

    private static function deleted_message(): string {
        return __( 'A chosen workflow was deleted. Remove it from the list.', 'yaymatik' );
    }
}
