<?php
/**
 * YayMail's fake adapter: sample designs, no YayMail code.
 *
 * @package YayMail
 */

defined( 'ABSPATH' ) || exit;

/** Stands in for YayMail until its real adapter is written. */
final class YayMail_YayMatik_Fake_Adapter {

    /** Design id => name. */
    public function design_options(): array {
        return [
            '1' => 'Thank you',
            '2' => 'Review request',
            '3' => 'Cart reminder',
        ];
    }

    /**
     * Subject and HTML; YayMatik fills the merge tags and wraps it.
     *
     * @param array              $config
     * @param \YayMatik\Api\Step $step
     */
    public function build_email( array $config, $step ): array {
        $design = $this->design_options()[ (string) ( $config['design_id'] ?? '' ) ] ?? 'Thank you';
        $order  = $step->order();
        return [
            'subject' => (string) $config['subject'],
            'html'    => '<p>Hi {{ customer.first_name | default: "there" }},</p>'
                . '<p>' . esc_html( 'YayMail design: ' . $design ) . '</p>'
                . ( $order ? '<p>' . esc_html( 'Order #' . $order->get_order_number() ) . '</p>' : '' ),
            'wrap'    => true,
        ];
    }
}
