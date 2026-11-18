<?php
namespace YayMatik\Tests\Support;

use YayMatik\Workflow\Core\Definition\ProCatalog;

/** Expectations written for YayMatik Pro, as the free edition sees them. */
final class Edition {

    /**
     * Without Pro's data keys, also through a relation (`order.customer.total_spent`), when the run is the free edition.
     *
     * @param string[] $keys
     * @return string[]
     */
    public static function keys( array $keys ): array {
        if ( ! YAYMATIK_TEST_LITE ) {
            return $keys;
        }
        $pro = fn( string $key ) => in_array( $key, ProCatalog::FIELDS, true ) || in_array( substr( $key, strpos( $key, '.' ) + 1 ), ProCatalog::FIELDS, true );
        return array_values( array_filter( $keys, fn( string $key ) => ! $pro( $key ) ) );
    }
}
