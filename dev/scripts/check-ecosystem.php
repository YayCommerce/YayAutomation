<?php
/**
 * One check-ecosystem.sh phase per wp-cli process: `setup`, `cell <source> <active products>`, `cleanup`; ids kept in an option between them.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- wp eval-file runs in global scope; dev script.

require_once __DIR__ . '/check-run.php';

use YayMatik\Workflow\Core\Email\DeliveryStatus;
use YayMatik\Workflow\Core\Storage\WorkflowStore;
use YayMatik\Workflow\ModuleRegistry;

const CHECK_ECO_STATE  = 'yaymatik_check_ecosystem';
const CHECK_ECO_PREFIX = 'Check ecosystem:';
const CHECK_ECO_EMAIL  = 'ecosystem.check@example.test';

/** Product folder => its module id and name. */
const CHECK_ECO_PRODUCTS = [
    'yaymail'  => 'YayMail',
    'yaymagic' => 'YayMagic',
    'yaysmtp'  => 'YaySMTP',
];

/** Design source => its email step. */
const CHECK_ECO_SOURCES = [
    'core'     => 'send_email',
    'yaymail'  => 'yaymail_send_email',
    'yaymagic' => 'yaymagic_send_email',
];

$phase = $args[0] ?? '';
$state = (array) get_option( CHECK_ECO_STATE, [] );
$fails = [];
$ok    = function ( $pass, string $what ) use ( &$fails ) {
    if ( ! $pass ) {
        $fails[] = $what;
    }
};
$admin = (int) get_users(
    [
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    ]
)[0];

switch ( $phase ) {
    case 'setup':
        $missing = array_diff_key( CHECK_ECO_PRODUCTS, ModuleRegistry::modules() );
        if ( $missing ) {
            fwrite( STDERR, 'FAIL setup: no module ' . implode( ', ', array_keys( $missing ) ) . " (YAYMATIK_IS_DEVELOPMENT off, or a product installed without its extension)\n" );
            exit( 1 );
        }
        yaymatik_check_remove_workflows( CHECK_ECO_PREFIX );
        $order = wc_create_order();
        $order->set_billing_email( CHECK_ECO_EMAIL );
        $order->set_billing_first_name( 'Ann' );
        $order->save();
        $state = [
            'order'     => $order->get_id(),
            'workflows' => [],
        ];
        wp_set_current_user( $admin );
        foreach ( CHECK_ECO_SOURCES as $source => $block ) {
            $config  = 'core' === $source
                ? [
                    'to'      => '{{ customer.email }}',
                    'subject' => 'Check ecosystem: {{ customer.first_name }}',
                    'heading' => '',
                    'message' => '<p>Hi {{ customer.first_name }}</p>',
                ]
                : [
                    'subject'   => 'Check ecosystem: {{ customer.first_name }}',
                    'design_id' => '1',
                ];
            $node    = fn( string $id, string $kind, int $y, string $step, array $step_config ) => [
                'id'       => $id,
                'kind'     => $kind,
                'position' => [
                    'x' => 0,
                    'y' => $y,
                ],
                'data'     => [
                    'block'  => $step,
                    'config' => (object) $step_config,
                ],
            ];
            $request = new WP_REST_Request( 'POST', '/yaymatik/v1/workflows' );
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body(
                (string) wp_json_encode(
                    [
                        'name'  => CHECK_ECO_PREFIX . " {$source} (temporary)",
                        'graph' => [
                            'nodes' => [ $node( 't', 'trigger', 0, 'order_created', [] ), $node( 'mail', 'action', 170, $block, $config ) ],
                            'edges' => [
                                [
                                    'id'     => 'e1',
                                    'source' => 't',
                                    'target' => 'mail',
                                ],
                            ],
                        ],
                    ]
                )
            );
            $id = (int) ( rest_do_request( $request )->get_data()['id'] ?? 0 );
            // Active, or the run cancels; the order was created before, so it starts no run itself.
            $activated = 200 === rest_do_request( new WP_REST_Request( 'POST', "/yaymatik/v1/workflows/{$id}/activate" ) )->get_status();
            $ok( $id && $activated, "setup: workflow on {$block} saved and active" );
            $state['workflows'][ $source ] = $id;
        }
        update_option( CHECK_ECO_STATE, $state, false );
        break;

    case 'cell':
        $source = (string) ( $args[1] ?? '' );
        $active = array_filter( explode( ',', (string) ( $args[2] ?? '' ) ) );
        foreach ( CHECK_ECO_PRODUCTS as $folder => $name ) {
            $ok( isset( ModuleRegistry::modules()[ $folder ] ) === in_array( $folder, $active, true ), "{$name} " . ( in_array( $folder, $active, true ) ? 'on' : 'off' ) );
        }

        // The mail log: what wp_mail() got after every plugin's filters. Nothing is sent: the log stands in for the
        // mailer, and like a mailer that sent the email it fires wp_mail_succeeded.
        $mails = [];
        add_filter(
            'wp_mail',
            function ( $atts ) use ( &$mails ) {
                $mails[] = $atts;
                return $atts;
            },
            PHP_INT_MAX
        );
        remove_filter( 'pre_wp_mail', 'yay_mail_log_capture', PHP_INT_MIN );
        add_filter(
            'pre_wp_mail',
            function ( $short_circuit, $atts ) {
                do_action( 'wp_mail_succeeded', $atts );
                return true;
            },
            PHP_INT_MIN,
            2
        );
        $reports = [];
        add_action(
            'yaymatik_email_status',
            function ( $mark, $status ) use ( &$reports ) {
                $reports[] = [ (string) $mark, $status ];
            },
            10,
            2
        );
        $smtp_log = class_exists( 'YaySMTP_YayMatik_Fake_Adapter' ) ? YaySMTP_YayMatik_Fake_Adapter::SENT_LOG : '';
        if ( $smtp_log ) {
            delete_option( $smtp_log );
        }

        $run  = yaymatik_check_run( (int) ( $state['workflows'][ $source ] ?? 0 ), 'order_created', [ 'order_id' => (int) ( $state['order'] ?? 0 ) ] );
        $step = array_column( (array) ( $run['steps'] ?? [] ), null, 'step_id' )['mail'] ?? null;
        $ok( null !== $step, 'the email step ran' );
        $mails = array_values( array_filter( $mails, fn( $mail ) => in_array( CHECK_ECO_EMAIL, (array) $mail['to'], true ) ) );

        if ( 'core' !== $source && ! in_array( $source, $active, true ) ) {
            $ok( ! $mails, 'removed: no email' );
            $why = CHECK_ECO_PRODUCTS[ $source ] . ' isn’t active.';
            $ok( 'skipped' === ( $step['status'] ?? null ) && ( $step['error'] ?? null ) === $why, "removed: run detail says Skipped, \"{$why}\"" );
        } else {
            $mail = $mails[0] ?? null;
            $mark = (string) ( $step['id'] ?? '' );
            $ok( 1 === count( $mails ), 'one email (' . count( $mails ) . ')' );
            $ok( $mail && '' !== $mark && DeliveryStatus::mark_of( $mail['headers'] ) === $mark, 'marked with its run-step row' );
            $ok( $mail && 1 === substr_count( strtolower( $mail['message'] ), '<body' ), 'wrapped once (' . ( $mail ? substr_count( strtolower( $mail['message'] ), '<body' ) : 0 ) . ' <body>)' );
            $ok( $mail && false !== strpos( $mail['message'], 'yaymatik_unsub=' ) && false !== stripos( (string) $mail['headers'], 'List-Unsubscribe: <' ), 'unsubscribe link and header' );
            $smtp   = in_array( 'yaysmtp', $active, true );
            $marked = array_values( array_filter( $reports, fn( $report ) => $report[0] === $mark ) );
            $ok( $smtp ? 1 === count( $marked ) : ! $marked, 'logged once: ' . count( $marked ) . ' report(s) from YaySMTP' );
            if ( $smtp && $smtp_log ) {
                $logged = array_column( (array) get_option( $smtp_log, [] ), 'mark' );
                $ok( [ $mark ] === $logged, 'logged once in the YaySMTP scaffold’s log' );
            }
            $ok( 'completed' === ( $step['status'] ?? null ) && CHECK_ECO_EMAIL === ( $step['output']['to'] ?? null ), 'run detail: Sent to ' . CHECK_ECO_EMAIL );
            $delivery = (string) ( $step['output']['delivery']['status'] ?? '' );
            $ok( $smtp ? in_array( $delivery, [ 'delivered', 'opened', 'clicked' ], true ) : 'sent' === $delivery, "run detail: delivery {$delivery}" );
        }
        if ( $smtp_log ) {
            delete_option( $smtp_log );
        }
        break;

    case 'cleanup':
        $left  = yaymatik_check_remove_workflows( CHECK_ECO_PREFIX );
        $order = wc_get_order( (int) ( $state['order'] ?? 0 ) );
        if ( $order ) {
            $order->delete( true );
        }
        delete_option( CHECK_ECO_STATE );
        $ok( ! $left, 'cleanup: temporary workflows removed' );
        break;

    default:
        fwrite( STDERR, "Unknown phase: {$phase}\n" );
        exit( 1 );
}

foreach ( $fails as $fail ) {
    fwrite( STDERR, "  {$fail}\n" );
}
exit( $fails ? 1 : 0 );
