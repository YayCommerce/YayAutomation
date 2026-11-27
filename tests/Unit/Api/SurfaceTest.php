<?php
namespace YayMatik\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

/**
 * The public Extension API is frozen: a change to a class, constant, method, parameter or return type in
 * `includes/Api/`, or to a declared key, field type or type name, fails here. A deliberate change
 * updates tests/fixtures/extension-api-surface.json with the JSON this test prints, and the contract.
 */
final class SurfaceTest extends TestCase {

    /** @return array Class => kind, constants, public methods with their signatures. */
    public static function surface(): array {
        $surface = [];
        foreach ( glob( dirname( __DIR__, 3 ) . '/includes/Api/*.php' ) as $file ) {
            $class = new \ReflectionClass( 'YayMatik\\Api\\' . basename( $file, '.php' ) );
            $entry = [
                'kind'      => $class->isInterface() ? 'interface' : ( $class->isFinal() ? 'final class' : 'class' ),
                'constants' => [],
                'methods'   => [],
            ];
            foreach ( $class->getReflectionConstants() as $constant ) {
                if ( $constant->isPublic() ) {
                    $entry['constants'][ $constant->getName() ] = $constant->getValue();
                }
            }
            foreach ( $class->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
                $params                                 = array_map(
                    fn( \ReflectionParameter $param ) => trim(
                        ( $param->hasType() ? (string) $param->getType() . ' ' : '' )
                        . ( $param->isVariadic() ? '...' : '' ) . '$' . $param->getName()
                        . ( $param->isDefaultValueAvailable() ? ' = ' . var_export( $param->getDefaultValue(), true ) : '' ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
                    ),
                    $method->getParameters()
                );
                $entry['methods'][ $method->getName() ] = ( $method->isStatic() ? 'static ' : '' ) . '(' . implode( ', ', $params ) . ')'
                    . ( $method->hasReturnType() ? ': ' . $method->getReturnType() : '' );
            }
            ksort( $entry['methods'] );
            $surface[ $class->getName() ] = $entry;
        }
        ksort( $surface );
        $keys = [];
        foreach ( [ 'MODULE_KEYS', 'STEP_KEYS', 'TRIGGER_KEYS', 'ACTION_KEYS', 'EMAIL_KEYS', 'EDITOR_KEYS', 'TEMPLATE_KEYS', 'DATA_ITEM_KEYS', 'DATA_FIELD_KEYS' ] as $name ) {
            $keys[ strtolower( $name ) ] = self::constant( \YayMatik\Workflow\Extension\Declaration::class, $name );
        }
        $keys['field_types'] = array_keys( self::constant( \YayMatik\Workflow\Extension\Declaration::class, 'FIELD_TYPES' ) );
        $keys['type_names']  = array_keys( self::constant( \YayMatik\Workflow\Extension\FieldAdapter::class, 'CONDITION_TYPES' ) );
        $keys['fact_types']  = \YayMatik\Workflow\Extension\FieldAdapter::SCALAR_TYPES;
        return $surface + [ 'keys' => $keys ];
    }

    /**
     * @param string $class_name
     * @param string $name
     * @return mixed
     */
    private static function constant( string $class_name, string $name ) {
        return ( new \ReflectionClassConstant( $class_name, $name ) )->getValue();
    }

    public function test_the_public_surface_is_unchanged(): void {
        $expected = (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/extension-api-surface.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $this->assertSame( trim( $expected ), (string) wp_json_encode( self::surface(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }
}
