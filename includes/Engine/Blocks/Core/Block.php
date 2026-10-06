<?php
namespace YayAutomation\Engine\Blocks\Core;

defined( 'ABSPATH' ) || exit;

/**
 * One workflow step type. Mirrors a block folder in apps/admin-page/src/routes/workflows/blocks/
 * (e.g. Triggers/OrderStatusChanged ↔ triggers/order-status-changed/). `validate()` must accept
 * exactly what that folder's `schema.ts` accepts.
 *
 * Run context: data item id => object (see Engine/Data), plus trigger-specific keys
 * (e.g. `from` / `to` for status changes).
 */
abstract class Block {

    public const KIND_TRIGGER   = 'trigger';
    public const KIND_CONDITION = 'condition';
    public const KIND_ACTION    = 'action';

    abstract public function id(): string;

    abstract public function kind(): string;

    /**
     * Coerces raw input into the stored config shape. Never fails; `validate()` decides if it's usable.
     *
     * @param mixed $config Raw config from the request.
     */
    abstract public function sanitize( $config ): array;

    /**
     * Whether a sanitized config is complete enough to run. Required before a workflow can be active.
     *
     * @param array $config Output of `sanitize()`.
     * @return true|\WP_Error
     */
    abstract public function validate( array $config );

    /**
     * Data items (see Engine/Data) this step reads with the given config. The trigger must supply
     * them; otherwise the workflow gets a `missing_data` issue.
     *
     * @param array $config Output of `sanitize()`.
     */
    public function uses( array $config ): array {
        return [];
    }

    /** Extra facts for the admin app, merged into this block's entry of `BlockRegistry::to_client()`. */
    public function client_meta(): array {
        return [];
    }

    /** A required field is blank. One message for all, like Shopify Flow's; mirrored by the TS `required()`. */
    protected function required(): \WP_Error {
        return $this->invalid( __( 'This field is required', 'yay-automation' ) );
    }

    protected function invalid( string $message ): \WP_Error {
        return new \WP_Error( 'yay_automation_invalid_config', $message );
    }
}
