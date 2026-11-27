<?php
namespace YayMatik\Workflow\Extension;

use YayMatik\Api\Step;
use YayMatik\Workflow\Core\Cart\SavedCart;
use YayMatik\Workflow\Core\Data\DataRegistry;
use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Template;
use YayMatik\Workflow\Core\Data\Values;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\DataItems\CartData;
use YayMatik\Workflow\DataItems\CustomerData;
use YayMatik\Workflow\DataItems\OrderData;

defined( 'ABSPATH' ) || exit;

/**
 * A run's values as the public `Step`: no Ref or other internal object gets out.
 *
 * @internal
 */
final class RunAdapter implements Step {

    /** @var Values */
    private $values;

    /** @var array [ 'preview' => bool, 'test' => bool, 'unsubscribe' => string ] */
    private $email;

    /** @var bool */
    private $asked_for_unsubscribe = false;

    /**
     * @param Values $values
     * @param array  $email  For an email's `build`: `preview`, `test`, `unsubscribe` (the link, `#` when not sending).
     */
    public function __construct( Values $values, array $email = [] ) {
        $this->values = $values;
        $this->email  = $email + [
            'preview'     => false,
            'test'        => false,
            'unsubscribe' => '',
        ];
    }

    public function order() {
        return $this->entity( OrderData::ID );
    }

    public function user() {
        $customer = $this->entity( CustomerData::ID );
        $user_id  = $customer ? CustomerData::user_id( $customer ) : 0;
        if ( ! $user_id ) {
            $user_id = (int) $this->values->get( 'trigger.user_id' );
        }
        $user = $user_id > 0 ? get_userdata( $user_id ) : false;
        return $user ? $user : null;
    }

    public function customer_email(): string {
        return (string) $this->values->get( 'trigger.' . CustomerData::ID . '.email' );
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
        return self::plain( $this->values->get( $path ) );
    }

    /**
     * @param string $text
     * @param bool   $html
     */
    public function render( string $text, bool $html = false ): string {
        static $template = null;
        $template        = $template ?? new Template();
        return $template->render( $text, $this->values, $html );
    }

    /**
     * The loaded entity of a trigger data item, or null.
     *
     * @param string $id
     * @return object|null
     */
    private function entity( string $id ) {
        // Preview's made-up values have no entities. A deleted one throws `GoneEntity`: the step is skipped.
        return $this->values instanceof Scope ? $this->values->entity( 'trigger.' . $id ) : null;
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
        return $this->email['preview'];
    }

    public function unsubscribe_url(): string {
        $this->asked_for_unsubscribe = true;
        return $this->email['unsubscribe'];
    }

    /** Whether the email's `build` read `unsubscribe_url()`. */
    public function asked_for_unsubscribe(): bool {
        return $this->asked_for_unsubscribe;
    }

    public function is_test(): bool {
        return $this->email['test'];
    }

    /** The run-step row, as the email's mark. */
    public function key(): string {
        $row = $this->values instanceof Scope ? $this->values->step_row() : 0;
        return $row ? (string) $row : '';
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
