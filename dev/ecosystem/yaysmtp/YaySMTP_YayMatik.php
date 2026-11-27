<?php
/**
 * YaySMTP's YayMatik extension: declarations only; the adapter does the work.
 *
 * @package YaySMTP
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- another plugin: its own text domain.

/** Registers YaySMTP's module on `yaymatik_register`. */
final class YaySMTP_YayMatik {

    /** @var YaySMTP_YayMatik_Fake_Adapter */
    private $adapter;

    /**
     * @param YaySMTP_YayMatik_Fake_Adapter $adapter
     */
    public function __construct( $adapter ) {
        $this->adapter = $adapter;
    }

    /**
     * @param \YayMatik\Api\Registrar $registrar
     */
    public function register( $registrar ) {
        $module = $registrar->module(
            [
                'id'               => 'yaysmtp',
                'label'            => 'YaySMTP',
                'description'      => __( 'Email delivery and its log.', 'yaysmtp' ),
                'logo'             => plugins_url( 'logo.svg', __FILE__ ),
                'requires_api'     => '1.0',
                'privacy_subjects' => [ $this->adapter, 'privacy_subjects' ],
            ]
        );
        if ( ! $module->ok() ) {
            return;
        }

        $module->data_item(
            'yaysmtp_email',
            [
                'label'       => __( 'Failed email', 'yaysmtp' ),
                'description' => __( 'An email YaySMTP couldn’t deliver, from its log.', 'yaysmtp' ),
                'icon'        => 'mail',
                'load'        => [ $this->adapter, 'load_email' ],
                'fields'      => [
                    'to'      => [
                        'label' => __( 'Recipient', 'yaysmtp' ),
                        'type'  => 'string',
                        'value' => [ $this->adapter, 'email_to' ],
                    ],
                    'subject' => [
                        'label' => __( 'Subject', 'yaysmtp' ),
                        'type'  => 'string',
                        'value' => [ $this->adapter, 'email_subject' ],
                    ],
                    'error'   => [
                        'label' => __( 'Error', 'yaysmtp' ),
                        'type'  => 'string',
                        'value' => [ $this->adapter, 'email_error' ],
                    ],
                ],
                'sample'      => [
                    'to'      => 'alex@example.com',
                    'subject' => 'Your order is on its way',
                    'error'   => 'SMTP connect() failed.',
                ],
            ]
        );

        $module->trigger(
            'yaysmtp_email_failed',
            [
                'label'       => __( 'Email delivery failed', 'yaysmtp' ),
                'description' => __( 'Starts when YaySMTP couldn’t deliver an email.', 'yaysmtp' ),
                'icon'        => 'mail',
                'provides'    => [ 'yaysmtp_email' ],
            ]
        );

        $module->template(
            'yaysmtp_failed_alert',
            [
                'label'            => __( 'Email delivery failed alert', 'yaysmtp' ),
                'description'      => __( 'Tell the store team when YaySMTP couldn’t deliver an email, so customers don’t miss it.', 'yaysmtp' ),
                'library_category' => 'alerts',
                'created'          => '2026-11-27',
                'steps'            => [
                    [
                        'id'   => 'trigger',
                        'step' => 'yaysmtp_email_failed',
                    ],
                    [
                        'id'     => 'alert',
                        'step'   => 'send_internal_email',
                        'config' => [
                            'recipients' => '',
                            'subject'    => __( 'An email to {{ yaysmtp_email.to }} failed', 'yaysmtp' ),
                            'message'    => __( '<p>YaySMTP couldn’t deliver an email from {{ site.name }}.</p><p>To: {{ yaysmtp_email.to }}<br>Subject: {{ yaysmtp_email.subject }}<br>Error: {{ yaysmtp_email.error }}</p>', 'yaysmtp' ),
                        ],
                    ],
                ],
            ]
        );
    }
}
