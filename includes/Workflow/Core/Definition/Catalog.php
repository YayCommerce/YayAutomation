<?php
namespace YayMatik\Workflow\Core\Definition;

use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\Operators;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\ModuleRegistry;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Core\Steps\StepRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * What Workflow Core knows, for the admin app (`yayMatikMeta.automation`): plugins, steps
 * with their fields and outputs, data items with their types, operators with their labels. PHP is the one source;
 * the app's parity test (apps/admin-page/src/test/parity.test.ts) checks the TS side against it.
 *
 * Shape must match apps/admin-page/src/types/localize.d.ts: keys in camelCase, option lists as
 * `{ value, label }` in order. Keys are only ever added. A change here → `./run.sh meta-fixture`.
 */
final class Catalog {

    public static function to_client(): array {
        return [
            'plugins'        => ModuleRegistry::to_client(),
            'steps'          => self::steps( StepRegistry::all() ),
            'data'           => DataRegistry::to_client(),
            'operators'      => Operators::BY_TYPE,
            'operatorLabels' => Operators::labels(),
            'always'         => DataRegistry::ALWAYS,
            'messages'       => Messages::all(),
        ];
    }

    /**
     * Entries by step id.
     *
     * @param Step[] $steps Keyed by id.
     */
    public static function steps( array $steps ): array {
        $plugins = ModuleRegistry::plugins( ModuleRegistry::modules() );
        $meta    = [];
        foreach ( $steps as $id => $step ) {
            $meta[ $id ] = self::step( $step, $plugins[ $id ] );
        }
        return $meta;
    }

    /**
     * One step: the keys the app has read since v0.1 (kind, plugin, category, requires, provides),
     * then the step contract.
     *
     * @param Step   $step
     * @param string $plugin
     */
    public static function step( Step $step, string $plugin ): array {
        return [
            'kind'   => $step->kind(),
            'plugin' => $plugin,
        ] + $step->client_meta() + [
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
     * Declared outputs as a list: key, label, type in the same text form as data fields'
     * `valueType` (e.g. `enum`, `list<string>`), and options for enums.
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
     * @param array $options Value => label.
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
