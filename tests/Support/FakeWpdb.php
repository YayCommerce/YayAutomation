<?php
namespace YayMatik\Tests\Support;

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

/** `$wpdb` that records what a store sends and returns canned results; it runs no SQL (check-runs tests the real tables). */
final class FakeWpdb {

    /** @var string */
    public $prefix = 'wp_';

    /** @var string */
    public $base_prefix = 'wp_';

    /** @var string */
    public $usermeta = 'wp_usermeta';

    /** @var int */
    public $insert_id = 0;

    /** @var string */
    public $last_error = '';

    /** @var array[] [ method, ...args ] per call. */
    public $calls = [];

    /** @var mixed What a get_results / get_row / get_var / get_col / query returns. */
    public $result = null;

    /** @var array What the next reads return, in order, before `$result`. */
    public $results = [];

    public function prepare( $query, ...$args ) {
        $args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
        $i    = 0;
        return preg_replace_callback(
            '/%[ds]/',
            function ( $m ) use ( $args, &$i ) {
                $value = $args[ $i++ ] ?? '';
                return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
            },
            $query
        );
    }

    public function insert( $table, $data, $format = null ) {
        $this->calls[] = [ 'insert', $table, $data, $format ];
        ++$this->insert_id;
        return 1;
    }

    public function update( $table, $data, $where, $format = null, $where_format = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as wpdb.
        $this->calls[] = [ 'update', $table, $data, $where ];
        return 1;
    }

    public function query( $sql ) {
        return $this->read( 'query', $sql );
    }

    public function get_results( $sql, $output = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as wpdb.
        return $this->read( 'get_results', $sql );
    }

    public function get_row( $sql, $output = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- same signature as wpdb.
        return $this->read( 'get_row', $sql );
    }

    public function get_var( $sql ) {
        return $this->read( 'get_var', $sql );
    }

    public function get_col( $sql ) {
        return $this->read( 'get_col', $sql );
    }

    /** The SQL of the last read call. */
    public function last_sql(): string {
        for ( $i = count( $this->calls ) - 1; $i >= 0; $i-- ) {
            if ( is_string( $this->calls[ $i ][1] ?? null ) && ! in_array( $this->calls[ $i ][0], [ 'insert', 'update' ], true ) ) {
                return $this->calls[ $i ][1];
            }
        }
        return '';
    }

    private function read( string $method, string $sql ) {
        $this->calls[] = [ $method, $sql ];
        return $this->results ? array_shift( $this->results ) : $this->result;
    }
}
