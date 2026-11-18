<?php
namespace YayMatik\Pro\Workflow\Actions;

use YayMatik\Workflow\Core\Data\MetaKeys;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Data\Type;
use YayMatik\Workflow\Core\Steps\Action;
use YayMatik\Workflow\Core\Steps\Field;
use YayMatik\Workflow\Core\Steps\StepResult;
use YayMatik\Workflow\DataItems\CustomerData;

defined( 'ABSPATH' ) || exit;

/** Sets a user meta value on the customer's account; an empty value is stored as is. */
class UpdateCustomerMeta extends Action {

    public const ID = 'update_customer_meta';

    public function id(): string {
        return self::ID;
    }

    public function label(): string {
        return __( 'Update customer custom field', 'yaymatik' );
    }

    public function description(): string {
        return __( 'Set a custom field on the customer’s account.', 'yaymatik' );
    }

    public function icon(): string {
        return 'list';
    }

    public function category(): string {
        return self::CATEGORY_CUSTOMER;
    }

    public function requires(): array {
        return [ CustomerData::ID ];
    }

    public function fields(): array {
        return [
            Field::text( 'key', __( 'Field name', 'yaymatik' ) )
                /* translators: {{ step }}: step name; keep {{ … }} as it is. */
                ->required( __( '{{ step }} needs a field name.', 'yaymatik' ) )
                ->suggest( 'customer-meta-keys' ),
            Field::text( 'value', __( 'Value', 'yaymatik' ) )->variables(),
        ];
    }

    public function summary(): string {
        /* translators: {{ config.key }}: custom field name, {{ config.value }}: its value; keep {{ … }} as it is. */
        return __( 'Set {{ config.key }} to {{ config.value }}', 'yaymatik' );
    }

    public function outputs(): array {
        return [
            'key'     => [
                'label' => __( 'Field name', 'yaymatik' ),
                'type'  => Type::string(),
            ],
            'changed' => [
                'label' => __( 'Value changed', 'yaymatik' ),
                'type'  => Type::boolean(),
            ],
        ];
    }

    /**
     * Refuses the fields that decide a user's access or sessions, and YayMatik's own.
     *
     * @param array $config
     */
    public function validate( array $config ) {
        $error = $this->field_error( $config );
        if ( $error ) {
            return $error;
        }
        return MetaKeys::user_blocked( $config['key'] ) ? $this->invalid( self::blocked_message(), 'key' ) : true;
    }

    public function run( Scope $scope, array $config ): StepResult {
        $customer = $scope->entity( CustomerData::ID );
        $user_id  = $customer ? CustomerData::user_id( $customer ) : 0;
        // An order keeps the id of an account deleted since.
        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            return StepResult::skipped( __( 'This customer has no account.', 'yaymatik' ) );
        }
        $key = $config['key'];
        // A stored config may never have passed `validate()`.
        if ( MetaKeys::user_blocked( $key ) || MetaKeys::user_collides( $user_id, $key ) ) {
            return StepResult::failed( self::blocked_message() );
        }
        $stored = get_user_meta( $user_id, $key, true );
        $new    = self::render( $config['value'], $scope );
        if ( metadata_exists( 'user', $user_id, $key ) && is_scalar( $stored ) && (string) $stored === $new ) {
            return StepResult::ok(
                [
                    'key'     => $key,
                    'changed' => false,
                ]
            );
        }
        // update_user_meta() unslashes its key and value: slashed here, the stored key is the one checked above.
        if ( false === update_user_meta( $user_id, wp_slash( $key ), wp_slash( $new ) ) ) {
            return StepResult::failed( __( 'Couldn’t save the custom field.', 'yaymatik' ) );
        }
        // Runs keep refs only: no values in the outputs.
        return StepResult::ok(
            [
                'key'     => $key,
                'changed' => true,
            ]
        );
    }

    private static function blocked_message(): string {
        return __( 'This custom field is protected and can’t be changed.', 'yaymatik' );
    }
}
