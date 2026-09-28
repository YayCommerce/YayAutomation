<?php
namespace YayAutomation\Engine\Workflows;

use YayAutomation\Engine\Blocks\Action;
use YayAutomation\Engine\Blocks\BlockRegistry;
use YayAutomation\Engine\Blocks\Condition;
use YayAutomation\Engine\Blocks\OrderStatusChanged;
use YayAutomation\Engine\Blocks\Trigger;
use YayAutomation\Utils\Logger;
use YayAutomation\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Runs active workflows when an order changes status. Runs inside WooCommerce's status change,
 * so nothing here may throw: every failure is logged and swallowed.
 */
class Runner {

    use SingletonTrait;

    protected function __construct() {
        add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_changed' ], 20, 4 );
    }

    public function on_order_status_changed( $order_id, $from, $to, $order = null ) {
        try {
            $order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
            if ( ! $order instanceof \WC_Order ) {
                return;
            }
            $context   = [
                'order' => $order,
                'from'  => (string) $from,
                'to'    => (string) $to,
            ];
            $workflows = WorkflowRepository::active_for_trigger( OrderStatusChanged::ID );
        } catch ( \Throwable $e ) {
            Logger::error( 'Could not load workflows: ' . $e->getMessage(), [ 'order_id' => $order_id ] );
            return;
        }

        foreach ( $workflows as $workflow ) {
            try {
                $this->run( $workflow, $context );
            } catch ( \Throwable $e ) {
                $this->log( $workflow, $context, $e->getMessage() );
            }
        }
    }

    /**
     * Walks the graph from the trigger: actions run in order, conditions pick the `then` or `otherwise` edge.
     *
     * @param array $workflow Stored workflow.
     * @param array $context  Run context, see Block.
     */
    private function run( array $workflow, array $context ) {
        $nodes = array_column( $workflow['graph']['nodes'], null, 'id' );
        $edges = $workflow['graph']['edges'];

        $trigger = null;
        foreach ( $nodes as $node ) {
            if ( 'trigger' === $node['kind'] ) {
                $trigger = $node;
            }
        }
        $block = $trigger ? BlockRegistry::get( $trigger['data']['block'] ) : null;
        if ( ! $block instanceof Trigger || true !== $block->validate( $trigger['data']['config'] ) ) {
            return;
        }
        if ( ! $block->matches( $context, $trigger['data']['config'] ) ) {
            return;
        }

        $queue   = self::children( $edges, $trigger['id'] );
        $visited = [];
        while ( $queue ) {
            $id = array_shift( $queue );
            if ( isset( $visited[ $id ] ) || ! isset( $nodes[ $id ] ) ) {
                continue;
            }
            $visited[ $id ] = true;

            $node   = $nodes[ $id ];
            $config = $node['data']['config'];
            $block  = BlockRegistry::get( $node['data']['block'] );
            $valid  = $block ? $block->validate( $config ) : false;
            if ( true !== $valid ) {
                // Saved active only when valid; a failure here means the stored data changed underneath us.
                $this->log( $workflow, $context, "Skipped invalid step {$id}." );
                continue;
            }

            if ( $block instanceof Condition ) {
                $branch = $block->evaluate( $context, $config ) ? 'then' : 'otherwise';
                $queue  = array_merge( $queue, self::children( $edges, $id, $branch ) );
            } elseif ( $block instanceof Action ) {
                $result = $block->run( $context, $config );
                if ( is_wp_error( $result ) ) {
                    $this->log( $workflow, $context, "Step {$id}: " . $result->get_error_message() );
                }
                $queue = array_merge( $queue, self::children( $edges, $id ) );
            }
        }//end while
    }

    private static function children( array $edges, string $source, ?string $branch = null ): array {
        $children = [];
        foreach ( $edges as $edge ) {
            if ( $edge['source'] === $source && ( $edge['sourceHandle'] ?? null ) === $branch ) {
                $children[] = $edge['target'];
            }
        }
        return $children;
    }

    private function log( array $workflow, array $context, string $message ) {
        Logger::error(
            "Workflow {$workflow['id']} failed: {$message}",
            [
                'workflow_id' => $workflow['id'],
                'order_id'    => $context['order']->get_id(),
            ]
        );
    }
}
