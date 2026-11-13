<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Api\Run;
use YayMatik\Workflow\Core\Cart\SavedCart;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Template;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\DataItems\CartData;
use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\DataItems\OrderData;

defined( 'ABSPATH' ) || exit;

/**
 * A run's Scope as the public `Run`: no Ref or other internal object gets out. An entity deleted
 * since the event throws `GoneEntity`, which skips the step as in core steps.
 *
 * @internal
 */
final class RunAdapter implements Run {

    /** @var Scope */
    private $scope;

    /**
     * @param Scope $scope
     */
    public function __construct( Scope $scope ) {
        $this->scope = $scope;
    }

    public function order() {
        return $this->entity( OrderData::ID );
    }

    public function user() {
        $customer = $this->entity( CustomerData::ID );
        $user_id  = $customer ? CustomerData::user_id( $customer ) : 0;
        if ( ! $user_id ) {
            $user_id = (int) $this->scope->get( 'trigger.user_id' );
        }
        $user = $user_id > 0 ? get_userdata( $user_id ) : false;
        return $user ? $user : null;
    }

    public function customer_email(): string {
        return (string) $this->scope->get( 'trigger.' . CustomerData::ID . '.email' );
    }

    public function cart() {
        $cart = $this->entity( CartData::ID );
        return $cart instanceof SavedCart ? new CartViewAdapter( $cart ) : null;
    }

    /**
     * Only another plugin's own data item: core items have their own getters.
     *
     * @param string $id
     */
    public function item( string $id ) {
        return ( DataRegistry::entities()[ $id ] ?? null ) instanceof ExtensionDataItem ? $this->entity( $id ) : null;
    }

    /**
     * A value, never an entity: a path to one (`order`) is null, and so is each entity in a list.
     *
     * @param string $path
     */
    public function get( string $path ) {
        return self::plain( $this->scope->get( $path ) );
    }

    /**
     * @param string $text
     * @param bool   $html
     */
    public function render( string $text, bool $html = false ): string {
        static $template = null;
        $template        = $template ?? new Template();
        return $template->render( $text, $this->scope, $html );
    }

    /**
     * The loaded entity of a trigger data item, or null.
     *
     * @param string $id
     * @return object|null
     */
    private function entity( string $id ) {
        return $this->scope->entity( 'trigger.' . $id );
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function plain( $value ) {
        if ( $value instanceof Ref ) {
            return null;
        }
        return is_array( $value ) ? array_map( [ self::class, 'plain' ], $value ) : $value;
    }

    public function is_preview(): bool {
        return false;
    }

    /**
     * @param array $outputs
     */
    public function done( array $outputs = [] ) {
        return StepResult::ok( $outputs );
    }

    /**
     * @param string $reason
     */
    public function skip( string $reason ) {
        return StepResult::skipped( $reason );
    }

    /**
     * @param string $reason
     */
    public function fail( string $reason ) {
        return StepResult::failed( $reason );
    }
}
