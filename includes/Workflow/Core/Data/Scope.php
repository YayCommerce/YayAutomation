<?php
namespace YayMatik\Workflow\Core\Data;

use YayMatik\Workflow\DataItems\SiteData;

defined( 'ABSPATH' ) || exit;

/**
 * Everything a run's steps can read, by path. Steps read data only through it, never by keeping
 * objects, so a run reads fresh data after a wait.
 *
 * - `trigger.*`: what the trigger supplied. Entities are Refs (`trigger.order`), loaded when read;
 *   facts only true at trigger time (status from / to, event payload) are kept as plain values.
 *   Anything the trigger supplied can be read without `trigger.`: `order.total` is `trigger.order.total`.
 * - `steps.<step id>.*`: outputs of the steps that ran. `vars.*`, `workflow.*`, `run.*`: plain values.
 * - `site.*`: always there.
 *
 * Plain values work wherever Refs do, so sample data (previews, Send test) is the same shape.
 * Stored in `runs.scope` as JSON: `to_array()` / `from_array()`.
 */
final class Scope implements Values {

    public const SECTIONS = [ 'trigger', 'steps', 'vars', 'workflow', 'run' ];

    /** @var array[] Section => values. */
    private $data = [];

    /** @var RefResolver */
    private $resolver;

    /** @var DataItem[] Keyed by entity type id. */
    private $entities;

    /** @var callable|null fn( string $step_id, string $key, array $outputs, bool $html ): ?string */
    private $output_display = null;

    /**
     * @param array            $data     Section => values; values may hold Refs.
     * @param RefResolver|null $resolver Loads Refs; null = a new one (one per slice).
     * @param DataItem[]|null  $entities Entity types by id; null = `DataRegistry::entities()`.
     */
    public function __construct( array $data = [], ?RefResolver $resolver = null, ?array $entities = null ) {
        foreach ( self::SECTIONS as $section ) {
            $this->data[ $section ] = is_array( $data[ $section ] ?? null ) ? $data[ $section ] : [];
        }
        $this->resolver = $resolver ?? new RefResolver();
        $this->entities = $entities ?? DataRegistry::entities();
    }

    /**
     * A scope stored by `to_array()`.
     *
     * @param array            $stored   Decoded JSON.
     * @param RefResolver|null $resolver Loads Refs.
     * @param DataItem[]|null  $entities Entity types by id.
     */
    public static function from_array( array $stored, ?RefResolver $resolver = null, ?array $entities = null ): self {
        // Only the trigger holds refs. Elsewhere a value shaped like one stays plain data, so stored
        // input (a step output, a variable) can never point the run at another entity.
        if ( isset( $stored['trigger'] ) ) {
            $stored['trigger'] = self::decode( $stored['trigger'] );
        }
        return new self( $stored, $resolver, $entities );
    }

    /** JSON-safe form: Refs become `{ "__ref": { type, id } }`; `from_array()` reads them back in the trigger only. */
    public function to_array(): array {
        return self::encode( $this->data );
    }

    /**
     * What a step gave later steps.
     *
     * @param string $step_id Step id.
     * @param array  $outputs Output key => value.
     */
    public function set_step_outputs( string $step_id, array $outputs ): void {
        $this->data['steps'][ $step_id ] = $outputs;
    }

    /**
     * How step outputs with a display text of their own read (a formatted date, a coupon box): built
     * from the step's other outputs when read, never stored. Set by whoever knows the graph.
     *
     * @param callable $display fn( string $step_id, string $key, array $outputs, bool $html ): ?string —
     *                          null when the output has no display text of its own.
     */
    public function set_output_display( callable $display ): void {
        $this->output_display = $display;
    }

    /**
     * The run and the step running now, as `run.id` and `run.step`, for steps that record where
     * something came from (e.g. the coupon they make).
     *
     * @param int    $run_id  Run id.
     * @param string $step_id Graph node id.
     */
    public function set_current_step( int $run_id, string $step_id ): void {
        $this->data['run']['id']   = $run_id;
        $this->data['run']['step'] = $step_id;
    }

    /**
     * @param string $name  Variable name.
     * @param mixed  $value Value.
     */
    public function set_var( string $name, $value ): void {
        $this->data['vars'][ $name ] = $value;
    }

    /**
     * Typed value at a path: a field's value, a Ref for a relation, a plain value. Null when missing.
     *
     * @param string $path E.g. `order.customer.first_name`.
     * @return mixed
     * @throws GoneEntity When an entity on the path no longer exists.
     */
    public function get( string $path ) {
        $found = $this->resolve( $path );
        if ( ! $found ) {
            return null;
        }
        return $found['field'] ? ( $found['field']['value'] )( $found['object'] ) : $found['value'];
    }

    /**
     * The loaded entity a path points to, e.g. `order` → the order object. For steps that act on it.
     * Null when the path holds no entity.
     *
     * @param string $path E.g. `order` (shortcut of `trigger.order`).
     * @return object|null
     * @throws GoneEntity When the entity no longer exists.
     */
    public function entity( string $path ) {
        $value = $this->get( $path );
        return $value instanceof Ref ? $this->resolver->load( $value ) : null;
    }

    /**
     * A field's display text (a price with its currency, an option's label); a plain value as text.
     *
     * @param string $path E.g. `order.total`.
     * @param bool   $html Safe HTML instead of plain text.
     * @throws GoneEntity When an entity on the path no longer exists.
     */
    public function text( string $path, bool $html ): string {
        $output = $this->output_text( $path, $html );
        if ( null !== $output ) {
            return $output;
        }
        $found = $this->resolve( $path );
        if ( ! $found ) {
            return '';
        }
        if ( $found['field'] ) {
            return DataItem::display( $found['field'], $found['object'], $html );
        }
        $text = Template::to_text( $found['value'] );
        return $html ? esc_html( $text ) : $text;
    }

    /**
     * A step output's own display text (`steps.<id>.<key>`), or null when it has none. A step that
     * didn't run has no outputs, so its display text isn't asked for and reads empty.
     *
     * @param string $path Dot path.
     * @param bool   $html Safe HTML instead of plain text.
     */
    private function output_text( string $path, bool $html ): ?string {
        $segments = explode( '.', $path );
        if ( ! $this->output_display || 3 !== count( $segments ) || 'steps' !== $segments[0] ) {
            return null;
        }
        $outputs = $this->data['steps'][ $segments[1] ] ?? null;
        if ( ! is_array( $outputs ) ) {
            return null;
        }
        $text = ( $this->output_display )( $segments[1], $segments[2], $outputs, $html );
        return null === $text ? null : (string) $text;
    }

    /**
     * Walks a path. Null when missing. When the last segment is an entity field: that field and
     * the object to read it from, not yet read (text() may only need its display). Otherwise the
     * plain value.
     *
     * @param string $path Dot path.
     */
    private function resolve( string $path ): ?array {
        $segments = explode( '.', $path );
        $root     = $segments[0];
        if ( SiteData::ID !== $root && ! in_array( $root, self::SECTIONS, true ) && array_key_exists( $root, $this->data['trigger'] ) ) {
            array_unshift( $segments, 'trigger' );
        }
        $root = array_shift( $segments );

        $value  = null;
        $entity = null;
        $object = null;
        if ( SiteData::ID === $root ) {
            $entity = $this->entities[ SiteData::ID ] ?? null;
        } elseif ( in_array( $root, self::SECTIONS, true ) ) {
            $value = $this->data[ $root ];
        }
        if ( ! $entity && null === $value ) {
            return null;
        }

        $last = count( $segments ) - 1;
        foreach ( $segments as $i => $segment ) {
            if ( $value instanceof Ref ) {
                $entity = $this->entities[ $value->type() ] ?? null;
                if ( ! $entity ) {
                    return null;
                }
                $object = $this->resolver->load( $value );
            }
            if ( $entity ) {
                $field = $entity->fields()[ $segment ] ?? null;
                if ( ! $field ) {
                    return null;
                }
                // Found even when its value is empty, so the field's display text still applies.
                if ( $i === $last ) {
                    return [
                        'value'  => null,
                        'field'  => $field,
                        'object' => $object,
                    ];
                }
                $value  = ( $field['value'] )( $object );
                $entity = null;
                continue;
            }
            if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
                return null;
            }
            $value = $value[ $segment ];
        }

        if ( null === $value ) {
            return null;
        }
        return [
            'value'  => $value,
            'field'  => null,
            'object' => null,
        ];
    }

    /**
     * @param mixed $value Value that may hold Refs.
     * @return mixed
     */
    private static function encode( $value ) {
        if ( $value instanceof Ref ) {
            return $value->to_json();
        }
        return is_array( $value ) ? array_map( [ self::class, 'encode' ], $value ) : $value;
    }

    /**
     * @param mixed $value Decoded JSON.
     * @return mixed
     */
    private static function decode( $value ) {
        $ref = Ref::from_json( $value );
        if ( $ref ) {
            return $ref;
        }
        return is_array( $value ) ? array_map( [ self::class, 'decode' ], $value ) : $value;
    }
}
