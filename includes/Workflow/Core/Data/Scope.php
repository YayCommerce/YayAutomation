<?php
namespace YayMatik\Workflow\Core\Data;

use YayMatik\Workflow\Core\Steps\Trigger;
use YayMatik\Workflow\DataItems\SiteData;

defined( 'ABSPATH' ) || exit;

/**
 * What a run's steps read, by path: `trigger.*` (Refs or plain values), `steps.<id>.*`, `run.*`, `site.*`.
 */
final class Scope implements Values {

    public const SECTIONS = [ 'trigger', 'steps', 'run' ];

    /** @var array[] */
    private $data = [];

    /** @var RefResolver */
    private $resolver;

    /** @var DataItem[] By entity type id. */
    private $entities;

    /** @var callable|null fn( string $step_id, string $key, array $outputs, bool $html ): ?string */
    private $output_display = null;

    /** @var Trigger|null The run's trigger: it defines the `trigger.<fact>` values. */
    private $trigger = null;

    /** @var int The run-step row of the step running now; not stored. */
    private $row = 0;

    /**
     * @param array            $data     Section => values; values may hold Refs.
     * @param RefResolver|null $resolver Null = a new one (one per slice).
     * @param DataItem[]|null  $entities Null = `DataRegistry::entities()`.
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
     * @param array            $stored
     * @param RefResolver|null $resolver
     * @param DataItem[]|null  $entities
     */
    public static function from_array( array $stored, ?RefResolver $resolver = null, ?array $entities = null ): self {
        // Only the trigger holds refs. Elsewhere a value shaped like one stays plain data, so a stored
        // step output can never point the run at another entity.
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
     * @param string $step_id
     * @param array  $outputs
     */
    public function set_step_outputs( string $step_id, array $outputs ): void {
        $this->data['steps'][ $step_id ] = $outputs;
    }

    /**
     * Display text of step outputs that have their own (a coupon box), built when read, never stored.
     *
     * @param callable $display fn( string $step_id, string $key, array $outputs, bool $html ): ?string; null = none of its own.
     */
    public function set_output_display( callable $display ): void {
        $this->output_display = $display;
    }

    /**
     * The run's trigger.
     *
     * @param Trigger|null $trigger
     */
    public function set_trigger( ?Trigger $trigger ): void {
        $this->trigger = $trigger;
    }

    public function trigger(): ?Trigger {
        return $this->trigger;
    }

    /**
     * The run and the step running now, as `run.id` and `run.step`, and its run-step row.
     *
     * @param int    $run_id
     * @param string $step_id
     * @param int    $row
     */
    public function set_current_step( int $run_id, string $step_id, int $row ): void {
        $this->data['run']['id']   = $run_id;
        $this->data['run']['step'] = $step_id;
        $this->row                 = $row;
    }

    /** The run-step row of the step running now; 0 outside a run. */
    public function step_row(): int {
        return $this->row;
    }

    /**
     * Typed value at a path: a field's value, a Ref for a relation, a plain value. Null when missing.
     *
     * @param string $path
     * @return mixed
     * @throws GoneEntity When an entity on the path no longer exists.
     */
    public function get( string $path ) {
        $found = $this->resolve( $path );
        if ( ! $found ) {
            return null;
        }
        return $found['field'] ? ( $found['field']['value'] )( $found['object'] ) : self::fact_value( DataRegistry::field( $path, $this->trigger ), $found['value'] );
    }

    /**
     * A plain value as rules, tags and the run detail read it: a date fact stored as a Unix time is its `Y-m-d` in store time.
     *
     * @param array|null $fact
     * @param mixed      $value
     * @return mixed
     */
    public static function fact_value( ?array $fact, $value ) {
        return is_int( $value ) && DataItem::TYPE_DATE === ( $fact['type'] ?? null ) ? wp_date( 'Y-m-d', $value ) : $value;
    }

    /**
     * The exact moment a date path holds: a field's `timestamp`, or a date fact stored as a Unix time; null when only a day is known.
     *
     * @param string $path
     * @throws GoneEntity When an entity on the path no longer exists.
     */
    public function moment( string $path ): ?int {
        $found = $this->resolve( $path );
        if ( ! $found ) {
            return null;
        }
        if ( $found['field'] ) {
            return $found['field']['timestamp'] ? ( $found['field']['timestamp'] )( $found['object'] ) : null;
        }
        return $this->fact_moment( $path, $found['value'] );
    }

    /**
     * The moment a date field stands for: its `timestamp`, else 00:00 store time on its date; null when it has none.
     *
     * @param string $path
     * @throws GoneEntity When an entity on the path no longer exists.
     */
    public function timestamp( string $path ): ?int {
        $found = $this->resolve( $path );
        $field = $found['field'] ?? null;
        if ( $field && $field['timestamp'] ) {
            return ( $field['timestamp'] )( $found['object'] );
        }
        $moment = $found && ! $field ? $this->fact_moment( $path, $found['value'] ) : null;
        if ( null !== $moment ) {
            return $moment;
        }
        if ( $field ) {
            $day = (string) ( $field['value'] )( $found['object'] );
        } elseif ( DataItem::TYPE_DATE === ( DataRegistry::field( $path, $this->trigger )['type'] ?? null ) && is_string( $found['value'] ?? null ) ) {
            // A date fact of the run's trigger: a plain `Y-m-d` it stored.
            $day = $found['value'];
        } else {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $day, wp_timezone() );
        return $date ? $date->getTimestamp() : null;
    }

    /**
     * A custom field of the entity behind a meta field; '' when there's none.
     *
     * @param string $path
     * @param string $key
     * @throws GoneEntity When an entity on the path no longer exists.
     */
    public function meta( string $path, string $key ): string {
        $found = $this->resolve( $path );
        return empty( $found['field']['meta'] ) ? '' : (string) ( $found['field']['meta'] )( $found['object'], $key );
    }

    /**
     * The loaded entity a path points to, or null.
     *
     * @param string $path
     * @return object|null
     * @throws GoneEntity When the entity no longer exists.
     */
    public function entity( string $path ) {
        $value = $this->get( $path );
        return $value instanceof Ref ? $this->resolver->load( $value ) : null;
    }

    /**
     * A field's display text; a plain value as text.
     *
     * @param string $path
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
        $text = Template::to_text( self::fact_value( DataRegistry::field( $path, $this->trigger ), $found['value'] ) );
        return $html ? esc_html( $text ) : $text;
    }

    /**
     * A step output's own display text (`steps.<id>.<key>`), or null when it has none.
     *
     * @param string $path
     * @param bool   $html
     */
    private function output_text( string $path, bool $html ): ?string {
        $segments = explode( '.', $path );
        if ( ! $this->output_display || 3 !== count( $segments ) || 'steps' !== $segments[0] ) {
            return null;
        }
        $outputs = $this->data['steps'][ $segments[1] ] ?? null;
        // A step that didn't run has no outputs, so its display text reads empty.
        if ( ! is_array( $outputs ) ) {
            return null;
        }
        $text = ( $this->output_display )( $segments[1], $segments[2], $outputs, $html );
        return null === $text ? null : (string) $text;
    }

    /**
     * A date fact of the run's trigger stored as a Unix time, else null.
     *
     * @param string $path
     * @param mixed  $value
     */
    private function fact_moment( string $path, $value ): ?int {
        return is_int( $value ) && DataItem::TYPE_DATE === ( DataRegistry::field( $path, $this->trigger )['type'] ?? null ) ? $value : null;
    }

    /**
     * Walks a path; null when missing. An entity field comes back unread, with its object: text() may need only its display.
     *
     * @param string $path
     */
    private function resolve( string $path ): ?array {
        $segments = explode( '.', $path );
        $root     = $segments[0];
        // Anything the trigger supplied reads without `trigger.`: `order.total` is `trigger.order.total`.
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
     * @param mixed $value
     * @return mixed
     */
    private static function encode( $value ) {
        if ( $value instanceof Ref ) {
            return $value->to_json();
        }
        return is_array( $value ) ? array_map( [ self::class, 'encode' ], $value ) : $value;
    }

    /**
     * @param mixed $value
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
