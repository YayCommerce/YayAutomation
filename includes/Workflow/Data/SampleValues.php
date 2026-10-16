<?php
namespace YayMatik\Workflow\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Made-up values for previews when the store has no real data yet: the data items' `sample()`,
 * followed through relations (`order.customer.first_name`). Everything else (`steps.*`, `site.*`)
 * reads from a Scope, so step outputs and their display texts work as in a run.
 */
final class SampleValues implements Values {

    /** @var Scope */
    private $scope;

    /** @var DataItem[] Items the trigger supplies, by id. */
    private $items;

    /**
     * @param Scope      $scope Step outputs and the site.
     * @param DataItem[] $items Items the trigger supplies, by id.
     */
    public function __construct( Scope $scope, array $items ) {
        $this->scope = $scope;
        $this->items = $items;
    }

    public function get( string $path ) {
        $sample = $this->sample( $path );
        return $sample ? $sample['value'] : $this->scope->get( $path );
    }

    public function text( string $path, bool $html ): string {
        $sample = $this->sample( $path );
        if ( ! $sample ) {
            return $this->scope->text( $path, $html );
        }
        if ( ! $html ) {
            return $sample['text'];
        }
        return null !== $sample['html'] ? $sample['html'] : esc_html( $sample['text'] );
    }

    /**
     * `[ 'value', 'text', 'html' ]` for a path into the trigger's items; null when it isn't one.
     *
     * @param string $path E.g. `order.total`, `trigger.order.customer.email`.
     */
    private function sample( string $path ): ?array {
        $segments = explode( '.', $path );
        if ( 'trigger' === $segments[0] ) {
            array_shift( $segments );
        }
        $entity = $this->items[ (string) array_shift( $segments ) ] ?? null;
        if ( ! $entity || ! $segments ) {
            return null;
        }
        $last = count( $segments ) - 1;
        foreach ( $segments as $i => $key ) {
            $field = $entity->fields()[ $key ] ?? null;
            if ( ! $field ) {
                return null;
            }
            /** @var Type $type */
            $type = $field['value_type'];
            if ( $type->is( Type::ENTITY ) ) {
                $entity = DataRegistry::entities()[ $type->entity_type() ] ?? null;
                if ( ! $entity || $i === $last ) {
                    return null;
                }
                continue;
            }
            if ( $i !== $last ) {
                return null;
            }
            $raw = $entity->sample()[ $key ] ?? null;
            if ( is_array( $raw ) && array_key_exists( 'value', $raw ) ) {
                return [
                    'value' => $raw['value'],
                    'text'  => (string) ( $raw['text'] ?? Template::to_text( $raw['value'] ) ),
                    'html'  => $raw['html'] ?? null,
                ];
            }
            return [
                'value' => $raw,
                'text'  => Template::to_text( $raw ),
                'html'  => null,
            ];
        }//end foreach
        return null;
    }
}
