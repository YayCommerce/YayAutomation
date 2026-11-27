<?php
/**
 * YayMagic's fake adapter: sample designs, no YayMagic code.
 *
 * @package YayMagic
 */

defined( 'ABSPATH' ) || exit;

/** Stands in for YayMagic until its real adapter is written. */
final class YayMagic_YayMatik_Fake_Adapter {

    /** Design id => name. */
    public function design_options(): array {
        return [
            '1' => 'Welcome',
            '2' => 'We miss you',
            '3' => 'Milestone',
        ];
    }

    /**
     * Subject and HTML for any trigger, with or without an order; YayMatik fills the merge tags and wraps it.
     *
     * @param array              $config
     * @param \YayMatik\Api\Step $step
     */
    public function build_email( array $config, $step ): array {
        $design = $this->design_options()[ (string) ( $config['design_id'] ?? '' ) ] ?? 'Welcome';
        return [
            'subject' => (string) $config['subject'],
            'html'    => '<p>Hi {{ customer.first_name | default: "there" }},</p>'
                . '<p>' . esc_html( 'YayMagic design: ' . $design ) . '</p>'
                . '<p>{{ site.shop_button }}</p>',
            'wrap'    => true,
        ];
    }
}
