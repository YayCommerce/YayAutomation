<?php
namespace YayAutomation\Engine\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Every data item, and fields by their full key (`order.total`). The admin app gets the same
 * list through `to_client()`, so field labels, types and options live only here.
 */
final class DataRegistry {

    /** Items available in every workflow, whatever the trigger supplies. */
    public const ALWAYS = [ SiteData::ID ];

    /** @var DataItem[]|null Keyed by item id. */
    private static $items = null;

    /** @return DataItem[] */
    public static function items(): array {
        if ( null === self::$items ) {
            self::$items = [];
            foreach ( [ new OrderData(), new CustomerData(), new SiteData() ] as $item ) {
                self::$items[ $item->id() ] = $item;
            }
        }
        return self::$items;
    }

    /**
     * Field definition by full key, or null when unknown.
     *
     * @param string $key E.g. `order.total`.
     */
    public static function field( string $key ): ?array {
        list( $item, $field ) = array_pad( explode( '.', $key, 2 ), 2, '' );
        $item                 = self::items()[ $item ] ?? null;
        return $item ? ( $item->fields()[ $field ] ?? null ) : null;
    }

    /**
     * The item a full key belongs to: `order.total` → `order`.
     *
     * @param string $key Full field key.
     */
    public static function item_of( string $key ): string {
        return explode( '.', $key, 2 )[0];
    }

    /**
     * Typed value for conditions. Null when the field is unknown or the context lacks its item.
     *
     * @param string $key     Full field key.
     * @param array  $context Run context: item id => object.
     * @return mixed
     */
    public static function value( string $key, array $context ) {
        $field = self::field( $key );
        if ( ! $field || ! self::in_context( $key, $context ) ) {
            return null;
        }
        return ( $field['value'] )( $context[ self::item_of( $key ) ] ?? null );
    }

    /**
     * Merge tag text. Empty when the field is unknown or the context lacks its item.
     *
     * @param string $key     Full field key.
     * @param array  $context Run context.
     * @param bool   $html    Escaped HTML instead of plain text.
     */
    public static function display( string $key, array $context, bool $html ): string {
        $field = self::field( $key );
        if ( ! $field || ! self::in_context( $key, $context ) ) {
            return '';
        }
        return DataItem::display( $field, $context[ self::item_of( $key ) ] ?? null, $html );
    }

    /** Shape must match `DataItemMeta` in apps/admin-page/src/types/localize.d.ts. */
    public static function to_client(): array {
        $items = [];
        foreach ( self::items() as $item ) {
            $fields = [];
            foreach ( $item->fields() as $key => $field ) {
                $client = [
                    'key'         => $item->id() . '.' . $key,
                    'label'       => $field['label'],
                    'type'        => $field['type'],
                    'conditional' => $field['conditional'],
                ];
                if ( $field['options'] ) {
                    $client['options'] = [];
                    foreach ( ( $field['options'] )() as $value => $label ) {
                        $client['options'][] = [
                            'value' => (string) $value,
                            'label' => $label,
                        ];
                    }
                }
                $fields[] = $client;
            }
            $items[] = [
                'id'          => $item->id(),
                'label'       => $item->label(),
                'description' => $item->description(),
                'fields'      => $fields,
            ];
        }//end foreach
        return $items;
    }

    private static function in_context( string $key, array $context ): bool {
        $item = self::item_of( $key );
        return in_array( $item, self::ALWAYS, true ) || isset( $context[ $item ] );
    }
}
