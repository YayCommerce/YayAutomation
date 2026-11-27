<?php
/**
 * One check-ecosystem.sh phase per wp-cli process; ids kept in an option between them. Email stack: `setup`,
 * `cell <source> <active products>`, `cleanup`. Value stack: `value-setup`, `value-cell <scenario> <active products>`, `value-cleanup`.
 *
 * @package YayMatik
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- wp eval-file runs in global scope; dev script.

require_once __DIR__ . '/check-run.php';

use YayMatik\Workflow\Core\Data\Ref;
use YayMatik\Workflow\Core\Data\Scope;
use YayMatik\Workflow\Core\Email\DeliveryStatus;
use YayMatik\Workflow\Core\Runtime\Runtime;
use YayMatik\Workflow\Core\Steps\StepRegistry;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\DataItems\CustomerData;
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

const CHECK_VALUE_STATE  = 'yaymatik_check_value_stack';
const CHECK_VALUE_PREFIX = 'Check value stack:';
const CHECK_VALUE_LOGIN  = 'yaymatik_check_value';
const CHECK_VALUE_POINTS = 50;

/** Product folder => its module name. */
const CHECK_VALUE_PRODUCTS = [
    'yaypricing'   => 'YayPricing',
    'yaywholesale' => 'YayWholesale',
    'yayloyalty'   => 'YayLoyalty',
];

/** Grant node => [ product folder, its step ]. */
const CHECK_VALUE_GRANTS = [
    'role'   => [ 'yaywholesale', 'yaywholesale_set_role' ],
    'points' => [ 'yayloyalty', 'yayloyalty_award_points' ],
];

/** Design source => its email step. */
const CHECK_ECO_SOURCES = [
    'core'     => 'send_email',
    'yaymail'  => 'yaymail_send_email',
    'yaymagic' => 'yaymagic_send_email',
];

$phase = $args[0] ?? '';
$state = (array) get_option( 0 === strpos( $phase, 'value-' ) ? CHECK_VALUE_STATE : CHECK_ECO_STATE, [] );
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

/**
 * A customer data field of the check's customer, as a condition or merge tag reads it.
 *
 * @param int    $user_id
 * @param string $field
 * @return mixed
 */
function yaymatik_check_value_field( int $user_id, string $field ) {
    return ( new Scope( [ 'trigger' => [ CustomerData::ID => new Ref( CustomerData::ID, 'user:' . $user_id ) ] ] ) )->get( 'trigger.' . CustomerData::ID . '.' . $field );
}

/**
 * What the products computed for the order: totals and each line's.
 *
 * @param int $order_id
 */
function yaymatik_check_value_prices( int $order_id ): array {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return [];
    }
    $lines = array_map( fn( $item ) => [ $item->get_subtotal(), $item->get_total() ], array_values( $order->get_items() ) );
    return [ $order->get_subtotal(), $order->get_discount_total(), $order->get_total(), $lines ];
}

/**
 * Each completed grant step's `handle` once more on its own run-step row, as a retry of the attempt would.
 *
 * @param int $run_id
 * @return string[] What went wrong.
 */
function yaymatik_check_value_retry( int $run_id ): array {
    $run    = RunStore::find( $run_id );
    $errors = [];
    foreach ( YayMatik\Workflow\Core\Storage\RunStepStore::for_run( $run_id ) as $row ) {
        if ( ! isset( CHECK_VALUE_GRANTS[ $row['step_id'] ] ) || 'completed' !== $row['status'] ) {
            continue;
        }
        $step  = StepRegistry::get( CHECK_VALUE_GRANTS[ $row['step_id'] ][1] );
        $scope = Scope::from_array( (array) $run['scope'] );
        $scope->set_current_step( $run_id, (string) $row['step_id'], (int) $row['id'] );
        if ( ! $step || ! $step->run( $scope, (array) $row['input'] )->is_ok() ) {
            $errors[] = "{$row['step_id']}: the retry did not complete";
        }
    }
    return $errors;
}

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
        // The scaffold: a design as its editor saves it, so the cell checks that what the editor saved is what's sent.
        if ( class_exists( 'YayMagic_YayMatik_Fake_Adapter' ) ) {
            $state['design'] = ( new YayMagic_YayMatik_Fake_Adapter() )->save_design( 0, CHECK_ECO_PREFIX . ' design', '<p>Order {{ order.number }} for {{ customer.first_name }}</p>' );
        }
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
                    'design_id' => (string) ( 'yaymagic' === $source && isset( $state['design'] ) ? $state['design'] : 1 ),
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
            if ( 'yaymagic' === $source && isset( $state['design'] ) ) {
                $number = wc_get_order( (int) $state['order'] )->get_order_number();
                $ok( $mail && false !== strpos( $mail['message'], "Order {$number} for Ann" ) && false === strpos( $mail['message'], '{{' ), 'the design saved in the editor, its variables rendered' );
            }
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
        if ( isset( $state['design'] ) && class_exists( 'YayMagic_YayMatik_Fake_Adapter' ) ) {
            $designs = (array) get_option( YayMagic_YayMatik_Fake_Adapter::DESIGNS, [] );
            unset( $designs[ $state['design'] ] );
            update_option( YayMagic_YayMatik_Fake_Adapter::DESIGNS, $designs, false );
        }
        delete_option( CHECK_ECO_STATE );
        $ok( ! $left, 'cleanup: temporary workflows removed' );
        break;

    case 'value-setup':
        $missing = array_diff_key( CHECK_VALUE_PRODUCTS, ModuleRegistry::modules() );
        if ( $missing ) {
            fwrite( STDERR, 'FAIL value-setup: no module ' . implode( ', ', array_keys( $missing ) ) . " (YAYMATIK_IS_DEVELOPMENT off, or a product installed without its extension)\n" );
            exit( 1 );
        }
        yaymatik_check_remove_workflows( CHECK_VALUE_PREFIX );
        $old = get_user_by( 'login', CHECK_VALUE_LOGIN );
        if ( $old ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( $old->ID );
        }
        $user_id = wp_insert_user(
            [
                'user_login' => CHECK_VALUE_LOGIN,
                'user_email' => 'value.check@example.test',
                'user_pass'  => wp_generate_password(),
                'first_name' => 'Ann',
                'role'       => 'customer',
            ]
        );
        $ok( ! is_wp_error( $user_id ), 'value-setup: customer created' );
        // Before the workflows are on, so it starts no run itself. A wholesale price and a rule's discount: 400 → 360.
        $order = wc_create_order( [ 'customer_id' => (int) $user_id ] );
        $item  = new WC_Order_Item_Product();
        $item->set_props(
            [
                'name'     => 'Crate (wholesale price)',
                'quantity' => 10,
                'subtotal' => 400,
                'total'    => 360,
            ]
        );
        $order->add_item( $item );
        $order->set_billing_email( 'value.check@example.test' );
        $order->calculate_totals( false );
        $order->save();
        $roles = [];
        $step  = StepRegistry::get( CHECK_VALUE_GRANTS['role'][1] );
        foreach ( $step ? $step->fields() : [] as $field ) {
            if ( 'role' === $field->key() ) {
                $roles = array_keys( $field->options() );
            }
        }
        $state = [
            'user'      => (int) $user_id,
            'order'     => $order->get_id(),
            'prices'    => yaymatik_check_value_prices( $order->get_id() ),
            'role'      => (string) ( $roles[0] ?? '' ),
            'workflows' => [],
        ];
        $ok( '' !== $state['role'], 'value-setup: a wholesale role to set' );
        wp_set_current_user( $admin );
        $node = fn( string $id, string $kind, int $y, string $step, array $step_config ) => [
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
        foreach ( [ 'A', 'B' ] as $name ) {
            $request = new WP_REST_Request( 'POST', '/yaymatik/v1/workflows' );
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body(
                (string) wp_json_encode(
                    [
                        'name'  => CHECK_VALUE_PREFIX . " {$name} (temporary)",
                        'graph' => [
                            'nodes' => [
                                $node( 't', 'trigger', 0, 'order_paid', [] ),
                                $node( 'role', 'action', 170, CHECK_VALUE_GRANTS['role'][1], [ 'role' => $state['role'] ] ),
                                $node( 'points', 'action', 340, CHECK_VALUE_GRANTS['points'][1], [ 'points' => CHECK_VALUE_POINTS ] ),
                                $node(
                                    'check',
                                    'condition',
                                    510,
                                    'condition',
                                    [
                                        'match' => 'all',
                                        'rules' => [
                                            [
                                                'field'    => 'order.yaypricing_discount_total',
                                                'operator' => 'greater_than',
                                                'value'    => '0',
                                                'valueType' => 'value',
                                            ],
                                        ],
                                    ]
                                ),
                            ],
                            'edges' => [
                                [
                                    'id'     => 'e1',
                                    'source' => 't',
                                    'target' => 'role',
                                ],
                                [
                                    'id'     => 'e2',
                                    'source' => 'role',
                                    'target' => 'points',
                                ],
                                [
                                    'id'     => 'e3',
                                    'source' => 'points',
                                    'target' => 'check',
                                ],
                            ],
                        ],
                    ]
                )
            );
            $id        = (int) ( rest_do_request( $request )->get_data()['id'] ?? 0 );
            $activated = 200 === rest_do_request( new WP_REST_Request( 'POST', "/yaymatik/v1/workflows/{$id}/activate" ) )->get_status();
            $ok( $id && $activated, "value-setup: workflow {$name} saved and active" );
            $state['workflows'][] = $id;
        }
        update_option( CHECK_VALUE_STATE, $state, false );
        break;

    case 'value-cell':
        $scenario = (string) ( $args[1] ?? '' );
        $active   = array_filter( explode( ',', (string) ( $args[2] ?? '' ) ) );
        foreach ( CHECK_VALUE_PRODUCTS as $folder => $name ) {
            $ok( isset( ModuleRegistry::modules()[ $folder ] ) === in_array( $folder, $active, true ), "{$name} " . ( in_array( $folder, $active, true ) ? 'on' : 'off' ) );
        }
        $user_id   = (int) ( $state['user'] ?? 0 );
        $wholesale = in_array( 'yaywholesale', $active, true );
        $loyalty   = in_array( 'yayloyalty', $active, true );
        $pricing   = in_array( 'yaypricing', $active, true );
        $before    = $loyalty ? (int) yaymatik_check_value_field( $user_id, 'yayloyalty_points' ) : 0;
        // The scaffold's role from an earlier cell, so this cell's check proves this cell set it.
        delete_user_meta( $user_id, '_yaywholesale_fake_role' );
        $ledgers   = [
            'role'   => class_exists( 'YayWholesale_YayMatik_Fake_Adapter' ) ? YayWholesale_YayMatik_Fake_Adapter::GRANTS : '',
            'points' => class_exists( 'YayLoyalty_YayMatik_Fake_Adapter' ) ? YayLoyalty_YayMatik_Fake_Adapter::GRANTS : '',
        ];
        $workflows = array_slice( (array) ( $state['workflows'] ?? [] ), 0, 'two workflows' === $scenario ? 2 : 1 );
        $runs      = [];
        foreach ( $workflows as $workflow_id ) {
            $runs[] = yaymatik_check_run(
                (int) $workflow_id,
                'order_paid',
                [ 'order_id' => (int) ( $state['order'] ?? 0 ) ],
                function ( int $run_id ) use ( $scenario, $ok ) {
                    if ( 'retried' === $scenario ) {
                        foreach ( yaymatik_check_value_retry( $run_id ) as $error ) {
                            $ok( false, $error );
                        }
                    }
                    if ( 'replayed' === $scenario ) {
                        RunStore::save(
                            $run_id,
                            [
                                'status'   => RunStore::STATUS_QUEUED,
                                'position' => null,
                            ]
                        );
                        Runtime::worker()->run( $run_id );
                    }
                }
            );
        }

        $keys = [];
        foreach ( $runs as $n => $run ) {
            $ok( 'completed' === ( $run['status'] ?? null ), "run {$n}: completed (" . ( $run['status'] ?? 'none' ) . ')' );
            $steps = array_column( (array) ( $run['steps'] ?? [] ), null, 'step_id' );
            $ok( 3 === count( (array) ( $run['steps'] ?? [] ) ), "run {$n}: one row per step" );
            foreach ( CHECK_VALUE_GRANTS as $node => list( $folder ) ) {
                $row  = $steps[ $node ] ?? [];
                $name = CHECK_VALUE_PRODUCTS[ $folder ];
                if ( in_array( $folder, $active, true ) ) {
                    $ok( 'completed' === ( $row['status'] ?? null ), "run {$n}: {$node} completed (" . ( $row['error'] ?? '' ) . ')' );
                    $keys[ $node ][] = (string) ( $row['id'] ?? '' );
                } else {
                    $ok( 'skipped' === ( $row['status'] ?? null ) && "{$name} isn’t active." === ( $row['error'] ?? null ), "run {$n}: {$node} Skipped, \"{$name} isn’t active.\"" );
                }
            }
            $check = $steps['check'] ?? [];
            if ( $pricing ) {
                $ok( 'completed' === ( $check['status'] ?? null ), "run {$n}: the YayPricing check ran" );
                if ( class_exists( 'YayPricing_YayMatik_Fake_Adapter' ) ) {
                    $ok( 'yes' === ( $check['output']['result'] ?? null ), "run {$n}: the scaffold reads the rule's discount" );
                }
            } else {
                $ok( 'skipped' === ( $check['status'] ?? null ) && 'YayPricing isn’t active.' === ( $check['error'] ?? null ), "run {$n}: the YayPricing check Skipped, \"YayPricing isn’t active.\"" );
            }
        }

        if ( $wholesale ) {
            $role = yaymatik_check_value_field( $user_id, 'yaywholesale_role' );
            $ok( $state['role'] === $role, "role {$state['role']} set (" . wp_json_encode( $role ) . ')' );
        }
        if ( $loyalty ) {
            $added = (int) yaymatik_check_value_field( $user_id, 'yayloyalty_points' ) - $before;
            $ok( CHECK_VALUE_POINTS * count( $runs ) === $added, 'points awarded once per run (' . $added . ' added)' );
        }
        // The scaffolds' ledgers: one grant per run, then this cell's rows are gone.
        foreach ( $ledgers as $node => $option ) {
            if ( ! $option || ! isset( $keys[ $node ] ) ) {
                continue;
            }
            $ledger = (array) get_option( $option, [] );
            $ok( count( $keys[ $node ] ) === count( array_intersect_key( $ledger, array_flip( $keys[ $node ] ) ) ), "{$node}: one ledger entry per run" );
            update_option( $option, array_diff_key( $ledger, array_flip( $keys[ $node ] ) ), false );
        }
        $ok( yaymatik_check_value_prices( (int) ( $state['order'] ?? 0 ) ) === ( $state['prices'] ?? null ), 'prices as the products computed them' );
        break;

    case 'value-cleanup':
        $left  = yaymatik_check_remove_workflows( CHECK_VALUE_PREFIX );
        $order = wc_get_order( (int) ( $state['order'] ?? 0 ) );
        if ( $order ) {
            $order->delete( true );
        }
        if ( ! empty( $state['user'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( (int) $state['user'] );
        }
        delete_option( CHECK_VALUE_STATE );
        $ok( ! $left, 'value-cleanup: temporary workflows removed' );
        break;

    default:
        fwrite( STDERR, "Unknown phase: {$phase}\n" );
        exit( 1 );
}

foreach ( $fails as $fail ) {
    fwrite( STDERR, "  {$fail}\n" );
}
exit( $fails ? 1 : 0 );
