<?php
namespace YayMatik\Workflow\Core\Data;

use YayMatik\Workflow\Core\Definition\Catalog;
use YayMatik\Workflow\DataItems\SiteData;
use YayMatik\Workflow\ModuleRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Every data item, and fields by their full key (`order.total`); the admin app gets them through `to_client()`.
 */
final class DataRegistry {

    /** Items available in every workflow, whatever the trigger supplies. */
    public const ALWAYS = [ SiteData::ID ];

    /** @var DataItem[]|null By item id. */
    private static $items = null;

    /** @var DataItem[]|null By entity type id. */
    private static $entities = null;

    /** @return DataItem[] */
    public static function items(): array {
        if ( null === self::$items ) {
            self::$items = array_filter( self::entities(), fn( DataItem $item ) => $item->standalone() );
        }
        return self::$items;
    }

    /**
     * Every entity type a Scope can read: the items, plus those reached only through a relation.
     *
     * @return DataItem[]
     */
    public static function entities(): array {
        if ( null === self::$entities ) {
            self::$entities = ModuleRegistry::data( ModuleRegistry::modules() );
        }
        return self::$entities;
    }

    /**
     * Field definition by full key, or null when unknown.
     *
     * @param string $key
     */
    public static function field( string $key ): ?array {
        list( $item, $field ) = array_pad( explode( '.', $key, 2 ), 2, '' );
        $item                 = self::items()[ $item ] ?? null;
        return $item ? ( $item->fields()[ $field ] ?? null ) : null;
    }

    /**
     * The item a full key belongs to: `order.total` → `order`.
     *
     * @param string $key
     */
    public static function item_of( string $key ): string {
        return explode( '.', $key, 2 )[0];
    }

    /**
     * Shape must match `DataItemMeta` in apps/admin-page/src/types/localize.d.ts.
     */
    public static function to_client(): array {
        $items = [];
        foreach ( self::items() as $item ) {
            $fields    = [];
            $relations = [];
            foreach ( $item->fields() as $key => $field ) {
                /** @var Type $type */
                $type = $field['value_type'];
                // A relation to one entity isn't a field: its fields are the related item's.
                if ( $type->is( Type::ENTITY ) ) {
                    $relations[] = [
                        'key'   => $item->id() . '.' . $key,
                        'label' => $field['label'],
                        'item'  => $type->entity_type(),
                    ];
                    continue;
                }
                $client = [
                    'key'         => $item->id() . '.' . $key,
                    'label'       => $field['label'],
                    'type'        => $field['type'],
                    'valueType'   => $type->to_string(),
                    'conditional' => $field['conditional'],
                ];
                if ( $field['options'] ) {
                    $client['options'] = Catalog::options( ( $field['options'] )() );
                }
                if ( $field['block'] ) {
                    $client['block'] = true;
                }
                if ( ! $field['merge_tag'] ) {
                    $client['mergeTag'] = false;
                }
                $fields[] = $client;
            }
            $items[] = [
                'id'          => $item->id(),
                'label'       => $item->label(),
                'description' => $item->description(),
                'icon'        => $item->icon(),
                'fields'      => $fields,
                'relations'   => $relations,
            ];
        }
        return $items;
    }
}
