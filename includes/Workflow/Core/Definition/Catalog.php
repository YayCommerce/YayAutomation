<?php
namespace YayMatik\Workflow\Core\Definition;

use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\Operators;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Extension\ExtensionModule;
use YayMatik\Workflow\ModuleRegistry;
use YayMatik\Workflow\Core\Email\EmailStep;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Extension\StepIndex;
use YayMatik\Workflow\Extension\TemplateBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * What Workflow Core knows, for the admin app (`yayMatikMeta.automation`).
 *
 * Shape must match apps/admin-page/src/types/localize.d.ts; after a change, `./run.sh meta-fixture`.
 */
final class Catalog {

    public static function to_client(): array {
        return [
            'plugins'        => ModuleRegistry::to_client(),
            'steps'          => self::steps( StepRegistry::all() ),
            'categories'     => self::categories(),
            'inactiveSteps'  => self::inactive_steps(),
            'data'           => DataRegistry::to_client(),
            'operators'      => Operators::BY_TYPE,
            'operatorLabels' => Operators::labels(),
            'always'         => DataRegistry::ALWAYS,
            'messages'       => Messages::all(),
            // What a workflow may use that isn't here without YayMatik Pro: drawn read-only as "Needs YayMatik Pro".
            'proMissing'     => ProCatalog::missing(),
            'templates'      => self::templates(),
        ];
    }

    /** Active plugins' templates minus the hidden ones; shape of `WorkflowTemplate` (packages/types/src/workflow.ts). */
    public static function templates(): array {
        $hidden = null;
        $list   = [];
        foreach ( ModuleRegistry::modules() as $module ) {
            if ( ! $module instanceof ExtensionModule ) {
                continue;
            }
            $hidden = $hidden ?? StepIndex::hidden_templates();
            foreach ( $module->templates() as $id => $args ) {
                $graph = isset( $hidden[ $id ] ) ? null : TemplateBuilder::graph( $args['steps'] );
                if ( ! is_array( $graph ) ) {
                    continue;
                }
                foreach ( $graph['nodes'] as $i => $node ) {
                    $graph['nodes'][ $i ]['data']['config'] = (object) $node['data']['config'];
                }
                $list[] = [
                    'id'          => $id,
                    'name'        => $args['label'],
                    'description' => $args['description'],
                    'category'    => $args['library_category'],
                    'workflow'    => $args['workflow'],
                    'createdAt'   => $args['created'],
                    'before'      => $args['before'],
                    'plugin'      => $module->id(),
                    'graph'       => $graph,
                ];
            }
        }
        return $list;
    }

    /**
     * Steps of other plugins that aren't active now, and YayMatik Pro's when it isn't, by id: what the editor shows for them (docs/extension-api.md §10).
     *
     * @return array[] `module`, `plugin` (label), `kind`, `label`, `outputs` ([ key, label ]), `facts` (keys).
     */
    public static function inactive_steps(): array {
        $steps = [];
        foreach ( array_keys( StepIndex::all() ) as $id ) {
            $entry = StepIndex::inactive( (string) $id );
            if ( ! $entry ) {
                continue;
            }
            $outputs = [];
            foreach ( $entry['outputs'] as $key => $label ) {
                $outputs[] = [
                    'key'   => (string) $key,
                    'label' => $label,
                ];
            }
            $steps[ $id ] = [
                'module'  => $entry['module'],
                'plugin'  => $entry['plugin'],
                'kind'    => $entry['kind'],
                'label'   => $entry['label'],
                'outputs' => $outputs,
                'facts'   => array_keys( $entry['facts'] ?? [] ),
            ];
        }
        foreach ( ProCatalog::missing()['steps'] as $id ) {
            $steps[ $id ] = ProCatalog::step( $id );
        }
        return $steps;
    }

    /**
     * Entries by step id.
     *
     * @param Step[] $steps By id.
     */
    public static function steps( array $steps ): array {
        $plugins = ModuleRegistry::plugins( ModuleRegistry::modules() );
        $meta    = [];
        foreach ( $steps as $id => $step ) {
            $meta[ $id ] = self::step( $step, $plugins[ $id ] );
        }
        return $meta;
    }

    /** Picker section labels by id: core's, then the ones extension modules declared. */
    public static function categories(): array {
        $categories = [
            'email'    => __( 'Email', 'yaymatik-automation-for-woocommerce' ),
            'order'    => __( 'Order', 'yaymatik-automation-for-woocommerce' ),
            'coupon'   => __( 'Coupons', 'yaymatik-automation-for-woocommerce' ),
            'flow'     => __( 'Flow', 'yaymatik-automation-for-woocommerce' ),
            'cart'     => __( 'Carts', 'yaymatik-automation-for-woocommerce' ),
            'customer' => __( 'Customers', 'yaymatik-automation-for-woocommerce' ),
            'review'   => __( 'Reviews', 'yaymatik-automation-for-woocommerce' ),
        ];
        foreach ( ModuleRegistry::modules() as $module ) {
            if ( $module instanceof ExtensionModule ) {
                $categories += $module->categories();
            }
        }
        return $categories;
    }

    /**
     * A step's catalog entry.
     *
     * @param Step   $step
     * @param string $plugin
     */
    public static function step( Step $step, string $plugin ): array {
        return [
            'kind'   => $step->kind(),
            'plugin' => $plugin,
        ] + $step->client_meta() + ( $step instanceof EmailStep ? [ 'email' => $step->email_audience() ] : [] ) + [
            'label'       => $step->label(),
            'description' => $step->description(),
            'icon'        => $step->icon(),
            'summary'     => $step->summary(),
            'onError'     => $step->on_error(),
            'fields'      => array_map( fn( Field $field ) => $field->to_array(), $step->fields() ),
            'outputs'     => self::outputs( $step->outputs() ),
        ];
    }

    /**
     * Declared outputs as a list.
     *
     * @param array $outputs Step `outputs()`.
     */
    private static function outputs( array $outputs ): array {
        $list = [];
        foreach ( $outputs as $key => $output ) {
            /** @var Type $type */
            $type  = $output['type'];
            $entry = [
                'key'   => (string) $key,
                'label' => $output['label'],
                'type'  => $type->to_string(),
            ];
            // A ready-made email block (e.g. a coupon box): rendered on the server, inserted on its own line.
            if ( ! empty( $output['block'] ) ) {
                $entry['block'] = true;
            }
            if ( isset( $output['options'] ) ) {
                $options          = is_callable( $output['options'] ) ? ( $output['options'] )() : $output['options'];
                $entry['options'] = self::options( (array) $options );
            }
            $list[] = $entry;
        }
        return $list;
    }

    /**
     * A value => label map as a list, so JSON keeps the order.
     *
     * @param array $options
     */
    public static function options( array $options ): array {
        $list = [];
        foreach ( $options as $value => $label ) {
            $list[] = [
                'value' => (string) $value,
                'label' => (string) $label,
            ];
        }
        return $list;
    }
}
