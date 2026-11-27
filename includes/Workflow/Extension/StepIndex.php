<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Workflow\Core\Steps\Step;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\ModuleRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Other plugins' steps and modules as last seen, so a workflow keeps working while a plugin is off: entries are
 * added and updated when the registry is built, never removed (docs/extension-api.md §10).
 *
 * @internal
 */
final class StepIndex {

    public const OPTION = 'yaymatik_extension_steps';

    /** @var array|null The option, read once. */
    private static $stored = null;

    /**
     * Writes the index when the modules' steps changed, and checks their templates when those or the steps changed.
     *
     * @param ExtensionModule[] $modules
     */
    public static function refresh( array $modules ): void {
        // Nothing to add, and entries are never removed: no read either.
        if ( ! $modules ) {
            return;
        }
        $steps     = [];
        $plugins   = [];
        $templates = [];
        foreach ( $modules as $module ) {
            $plugins[ $module->id() ] = $module->label();
            foreach ( array_merge( $module->triggers(), $module->actions() ) as $step ) {
                $steps[ $step->id() ] = self::entry( $module, $step );
            }
            foreach ( $module->templates() as $id => $template ) {
                $templates[ $id ] = $template['steps'];
            }
        }
        $fingerprint = md5( (string) wp_json_encode( [ $steps, $plugins ] ) );
        // What a template's check reads, without translated text: core's version, the modules and their steps' shape, the chains as written.
        $checked = $templates ? md5( (string) wp_json_encode( [ YAYMATIK_VERSION, array_map( [ self::class, 'shape' ], $modules ), $templates ] ) ) : '';
        $stored  = self::stored();
        if ( $fingerprint === $stored['fingerprint'] && $checked === $stored['checked'] ) {
            return;
        }
        self::$stored = [
            'fingerprint' => $fingerprint,
            'steps'       => $steps + $stored['steps'],
            'plugins'     => $plugins + $stored['plugins'],
            'checked'     => $checked,
            'templates'   => $checked === $stored['checked'] ? $stored['templates'] : ( $templates ? TemplateCheck::hidden( $modules ) : [] ),
        ];
        // Autoloaded: the check above runs on every request that builds the registry.
        update_option( self::OPTION, self::$stored, true );
    }

    /**
     * The entry of a step whose plugin isn't active now: not registered, and its module isn't either.
     * A step a still-active plugin dropped is not one: it fails as an unknown step.
     *
     * @param string $step_id
     */
    public static function inactive( string $step_id ): ?array {
        $entry = self::get( $step_id );
        if ( ! $entry || StepRegistry::get( $step_id ) || isset( ModuleRegistry::modules()[ $entry['module'] ] ) ) {
            return null;
        }
        return $entry;
    }

    /**
     * The label of the plugin, seen before and off now, that declared a data key's item or field
     * (`order.yaypricing_discount_total`, `yaywholesale_request.status`): their ids start with its module id + `_`,
     * the longest such id when one module id starts another.
     *
     * @param string $key
     */
    public static function inactive_plugin( string $key ): ?string {
        $ids   = explode( '.', $key, 3 );
        $owner = '';
        foreach ( array_keys( self::stored()['plugins'] + ModuleRegistry::modules() ) as $module ) {
            $prefix = $module . '_';
            if ( strlen( (string) $module ) > strlen( $owner ) && ( 0 === strpos( $ids[0], $prefix ) || 0 === strpos( $ids[1] ?? '', $prefix ) ) ) {
                $owner = (string) $module;
            }
        }
        return '' === $owner || isset( ModuleRegistry::modules()[ $owner ] ) ? null : (string) self::stored()['plugins'][ $owner ];
    }

    /**
     * A step another plugin registered at some point: `module`, `plugin` (label), `kind`, `label`, `outputs` and
     * `facts` (key => label).
     *
     * @param string $step_id
     */
    public static function get( string $step_id ): ?array {
        return self::stored()['steps'][ $step_id ] ?? null;
    }

    /** @return array[] By step id. */
    public static function all(): array {
        return self::stored()['steps'];
    }

    /** @return string[] Hidden extension template id => why, from the last check. */
    public static function hidden_templates(): array {
        return self::stored()['templates'];
    }

    /**
     * A module's steps and data fields as the template check reads them: ids, field keys and rules, outputs, needs.
     *
     * @param ExtensionModule $module
     */
    private static function shape( ExtensionModule $module ): array {
        $steps = [];
        foreach ( array_merge( $module->triggers(), $module->actions() ) as $step ) {
            $steps[ $step->id() ] = [
                array_map( fn( $field ) => array_intersect_key( $field->to_array(), array_flip( [ 'key', 'type', 'required', 'variables', 'showIf' ] ) ), $step->fields() ),
                array_keys( $step->outputs() ),
                $step->kind(),
                $step instanceof Trigger ? array_keys( $step->facts() ) : $step->requires(),
            ];
        }
        $items = [];
        foreach ( $module->data_items() as $id => $item ) {
            $items[ $id ] = array_keys( $item->fields() );
        }
        return [ $module->id(), $steps, $items, $module->data_fields() ];
    }

    /**
     * @param ExtensionModule $module
     * @param Step            $step
     */
    private static function entry( ExtensionModule $module, Step $step ): array {
        return [
            'module'  => $module->id(),
            'plugin'  => $module->label(),
            'kind'    => $step->kind(),
            'label'   => $step->label(),
            'outputs' => array_map( fn( array $output ) => (string) $output['label'], $step->outputs() ),
            'facts'   => $step instanceof Trigger ? array_map( fn( array $fact ) => (string) $fact['label'], $step->facts() ) : [],
        ];
    }

    private static function stored(): array {
        if ( null === self::$stored ) {
            $stored       = get_option( self::OPTION, [] );
            self::$stored = [
                'fingerprint' => (string) ( $stored['fingerprint'] ?? '' ),
                'steps'       => is_array( $stored['steps'] ?? null ) ? $stored['steps'] : [],
                'plugins'     => is_array( $stored['plugins'] ?? null ) ? $stored['plugins'] : [],
                'checked'     => (string) ( $stored['checked'] ?? '' ),
                'templates'   => is_array( $stored['templates'] ?? null ) ? $stored['templates'] : [],
            ];
        }
        return self::$stored;
    }
}
