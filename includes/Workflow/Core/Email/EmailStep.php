<?php
namespace YayMatik\Workflow\Core\Email;

use YayMatik\Workflow\Core\Data\Values;

defined( 'ABSPATH' ) || exit;

/**
 * A step that sends an email: Preview, Send test and run detail read this, not step ids.
 */
interface EmailStep {

    public const AUDIENCE_CUSTOMER = 'customer';

    public const AUDIENCE_STAFF = 'staff';

    /** `customer`: unsubscribe link and headers; `staff`: neither. */
    public function email_audience(): string;

    /**
     * The email as it goes out.
     *
     * @param array       $config
     * @param Values      $values
     * @param string|null $unsubscribe The link; null = Preview or Send test (`#`, no List-Unsubscribe); '' = a staff send.
     * @param bool        $test        Send test.
     * @return array [ 'subject', 'heading', 'body' (HTML), 'headers', 'raw' (bool: sent as built, no WooCommerce styles inlined) ]
     */
    public function compose_email( array $config, Values $values, ?string $unsubscribe, bool $test = false ): array;

    /**
     * Who it goes to, as Preview shows it.
     *
     * @param array  $config
     * @param Values $values
     */
    public function email_recipient( array $config, Values $values ): string;
}
