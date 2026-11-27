<?php
namespace YayMatik\Engine\Privacy;

use YayMatik\Utils\Logger;
use YayMatik\Utils\SingletonTrait;
use YayMatik\Workflow\Core\Email\UnsubscribeLink;
use YayMatik\Workflow\Core\Runtime\PersonSubjects;
use YayMatik\Workflow\Core\Storage\CartStore;
use YayMatik\Workflow\Core\Storage\RunStepStore;
use YayMatik\Workflow\Core\Storage\RunStore;
use YayMatik\Workflow\Core\Storage\Settings;
use YayMatik\Workflow\Core\Storage\TriggerClaimStore;
use YayMatik\Workflow\Core\Storage\UnsubscribeStore;

defined( 'ABSPATH' ) || exit;

/** WordPress's personal data exporter and eraser for carts, the unsubscribe record and runs, and the policy text. */
class PersonalData {

    use SingletonTrait;

    private const KEY = 'yaymatik';

    private const PER_PAGE = 50;

    protected function __construct() {
        add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'exporters' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'erasers' ] );
        add_action( 'admin_init', [ $this, 'policy_text' ] );
    }

    /**
     * @param array $exporters
     */
    public function exporters( $exporters ): array {
        $exporters              = (array) $exporters;
        $exporters[ self::KEY ] = [
            'exporter_friendly_name' => __( 'YayMatik', 'yaymatik-automation-for-woocommerce' ),
            'callback'               => [ $this, 'export' ],
        ];
        return $exporters;
    }

    /**
     * @param array $erasers
     */
    public function erasers( $erasers ): array {
        // First: WordPress's comments eraser blanks a review's email and user id, which is how this one finds review runs.
        return [
            self::KEY => [
                'eraser_friendly_name' => __( 'YayMatik', 'yaymatik-automation-for-woocommerce' ),
                'callback'             => [ $this, 'erase' ],
            ],
        ] + (array) $erasers;
    }

    /**
     * Carts and the unsubscribe record on page 1; runs 50 per page.
     *
     * @param string $email
     * @param int    $page 1-based.
     */
    public function export( $email, $page = 1 ): array {
        $email = UnsubscribeLink::normalize( (string) $email );
        $page  = max( 1, (int) $page );
        if ( '' === $email ) {
            return [
                'data' => [],
                'done' => true,
            ];
        }
        $data = [];
        if ( 1 === $page ) {
            foreach ( CartStore::for_email( $email ) as $cart ) {
                $data[] = $this->cart_item( $cart );
            }
            $unsubscribe = UnsubscribeStore::find( $email );
            if ( $unsubscribe ) {
                $data[] = [
                    'group_id'    => 'yaymatik-unsubscribe',
                    'group_label' => __( 'Unsubscribe', 'yaymatik-automation-for-woocommerce' ),
                    'item_id'     => 'yaymatik-unsubscribe',
                    'data'        => [
                        $this->value( __( 'Email', 'yaymatik-automation-for-woocommerce' ), $unsubscribe['email'] ),
                        $this->value( __( 'Unsubscribed', 'yaymatik-automation-for-woocommerce' ), get_date_from_gmt( $unsubscribe['created_at'] ) ),
                    ],
                ];
            }
            $milestones = TriggerClaimStore::find_keys( $email );
            if ( $milestones ) {
                $data[] = [
                    'group_id'    => 'yaymatik-milestones',
                    'group_label' => __( 'Customer milestones', 'yaymatik-automation-for-woocommerce' ),
                    'item_id'     => 'yaymatik-milestones',
                    'data'        => [
                        $this->value( __( 'Workflows started for this email', 'yaymatik-automation-for-woocommerce' ), (string) count( $milestones ) ),
                        $this->value( __( 'Last started', 'yaymatik-automation-for-woocommerce' ), get_date_from_gmt( (string) end( $milestones )['claimed_at'] ) ),
                    ],
                ];
            }
        }
        $runs = RunStore::for_export( $email, PersonSubjects::for_email( $email ), self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
        foreach ( $runs as $run ) {
            $data[] = $this->run_item( $run, $email );
        }
        return [
            'data' => $data,
            'done' => count( $runs ) < self::PER_PAGE,
        ];
    }

    /**
     * Stateless: every page re-queries the first 50 runs erase still changes; carts go on the last page.
     *
     * @param string $email
     * @param int    $page 1-based.
     */
    public function erase( $email, $page = 1 ): array {
        $email  = UnsubscribeLink::normalize( (string) $email );
        $result = [
            'items_removed'  => false,
            'items_retained' => false,
            'messages'       => [],
            'done'           => true,
        ];
        if ( '' === $email ) {
            return $result;
        }
        // WordPress merges the pages' results, so the unsubscribe message is added once.
        if ( 1 === max( 1, (int) $page ) && UnsubscribeStore::has( $email ) ) {
            $result['items_retained'] = true;
            $result['messages'][]     = __( 'The unsubscribe record is kept so this address gets no more workflow emails.', 'yaymatik-automation-for-woocommerce' );
        }
        // Read on every page: the carts, whose ids are run subjects, stay until the last one.
        $subjects  = PersonSubjects::for_email( $email );
        $runs      = RunStore::ids_mentioning( $email, $subjects, self::PER_PAGE );
        $ids       = array_keys( $runs );
        $waiting   = array_keys( $runs, RunStore::STATUS_WAITING, true );
        $cancelled = 0;
        // A waiting run would resume with `[deleted]` as its data, or still email the person.
        if ( $waiting ) {
            $reason    = __( "The customer's personal data was erased.", 'yaymatik-automation-for-woocommerce' );
            $cancelled = RunStore::cancel_waiting( $waiting, $reason );
            if ( $cancelled ) {
                RunStepStore::cancel_waiting( $waiting, $reason );
            }
        }
        $changed = $cancelled + RunStore::anonymize( $ids, $email );
        if ( $ids && ! $changed ) {
            Logger::error( 'Erase found runs but changed none; stopped.', [ 'run_ids' => $ids ] );
            $result['items_retained'] = true;
            $result['messages'][]     = __( 'Some workflow runs could not be erased. See WooCommerce → Status → Logs (yaymatik).', 'yaymatik-automation-for-woocommerce' );
        } else {
            $result['items_removed'] = $changed > 0;
            $result['done']          = count( $ids ) < self::PER_PAGE;
        }
        if ( ! $result['done'] ) {
            return $result;
        }
        // Carts stay while a run about them is in progress, so erasing again still finds it by its subject.
        if ( RunStore::in_progress_mentioning( $email, $subjects ) ) {
            $result['items_retained'] = true;
            $result['messages'][]     = __( 'A workflow run is in progress; erase again in a few minutes.', 'yaymatik-automation-for-woocommerce' );
            return $result;
        }
        $result['items_removed'] = CartStore::delete_for_email( $email ) > 0 || $result['items_removed'];
        $claims                  = TriggerClaimStore::delete_keys( $email );
        if ( false === $claims ) {
            $result['items_retained'] = true;
            $result['messages'][]     = __( 'Some customer milestone records could not be erased. See WooCommerce → Status → Logs (yaymatik).', 'yaymatik-automation-for-woocommerce' );
        }
        $result['items_removed'] = $claims > 0 || $result['items_removed'];
        return $result;
    }

    public function policy_text(): void {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }
        $paragraphs = [
            __( 'When you add products to your cart, we save the cart: its products, total and when it last changed. If you are logged in, we save your account email and first name with it. As a guest, the email address and first name you type at checkout are saved as you type them, before you place the order.', 'yaymatik-automation-for-woocommerce' ),
            __( 'We use this to send you cart reminder emails. Each reminder holds a link that puts the saved products back in your cart.', 'yaymatik-automation-for-woocommerce' ),
            sprintf(
                /* translators: 1: days a cart is kept, 2: days a workflow record is kept */
                __( 'A cart is deleted %1$d days after its last change. Records of the automated emails and other steps we ran for you are deleted %2$d days after they finish.', 'yaymatik-automation-for-woocommerce' ),
                (int) Settings::get( 'cart_retention_days' ),
                (int) Settings::get( 'run_retention_days' )
            ),
            __( 'Some automated emails are sent once per customer, for example when your orders reach a number. To remember that one was sent to a guest, we keep a one-way hash of your email address, never the address itself.', 'yaymatik-automation-for-woocommerce' ),
            __( 'When you ask us to erase your personal data, your carts and those hashes are deleted, and your email address is removed from those records; the records stay without it. Automated emails still scheduled for you are cancelled. If you unsubscribed from our emails, that record is kept so you get no more emails from us.', 'yaymatik-automation-for-woocommerce' ),
        ];
        $html       = implode( '', array_map( fn( string $text ) => '<p>' . esc_html( $text ) . '</p>', $paragraphs ) );
        wp_add_privacy_policy_content( 'YayMatik', wp_kses_post( $html ) );
    }

    /**
     * @param array $cart A `carts` row.
     */
    private function cart_item( array $cart ): array {
        $lines    = json_decode( (string) $cart['items'], true );
        $products = array_map(
            fn( $line ) => sprintf( '%s × %d', (string) ( $line['name'] ?? '' ), (int) ( $line['quantity'] ?? 0 ) ),
            is_array( $lines ) ? $lines : []
        );
        return [
            'group_id'    => 'yaymatik-carts',
            'group_label' => __( 'Carts', 'yaymatik-automation-for-woocommerce' ),
            'item_id'     => 'yaymatik-cart-' . (int) $cart['id'],
            'data'        => [
                $this->value( __( 'Products', 'yaymatik-automation-for-woocommerce' ), implode( ', ', $products ) ),
                $this->value( __( 'Email', 'yaymatik-automation-for-woocommerce' ), (string) $cart['email'] ),
                $this->value( __( 'First name', 'yaymatik-automation-for-woocommerce' ), (string) $cart['first_name'] ),
                $this->value( __( 'Created', 'yaymatik-automation-for-woocommerce' ), get_date_from_gmt( $cart['created_at'] ) ),
                $this->value( __( 'Last activity', 'yaymatik-automation-for-woocommerce' ), get_date_from_gmt( $cart['last_activity_at'] ) ),
            ],
        ];
    }

    /**
     * @param array  $run `RunStore::for_export()` row.
     * @param string $email
     */
    private function run_item( array $run, string $email ): array {
        $data = [
            $this->value( __( 'Workflow', 'yaymatik-automation-for-woocommerce' ), null === $run['workflow_name'] ? __( 'Deleted workflow', 'yaymatik-automation-for-woocommerce' ) : (string) $run['workflow_name'] ),
            $this->value( __( 'Started', 'yaymatik-automation-for-woocommerce' ), get_date_from_gmt( $run['started_at'] ) ),
            $this->value( __( 'Status', 'yaymatik-automation-for-woocommerce' ), (string) $run['status'] ),
        ];
        // Only this address: a step may also have emailed other people.
        foreach ( RunStepStore::for_run( (int) $run['id'] ) as $step ) {
            $to = array_filter( (array) ( $step['output']['to'] ?? [] ), 'is_string' );
            if ( in_array( $email, array_map( [ UnsubscribeLink::class, 'normalize' ], $to ), true ) ) {
                $data[] = $this->value( __( 'Sent to', 'yaymatik-automation-for-woocommerce' ), $email );
                break;
            }
        }
        return [
            'group_id'    => 'yaymatik-runs',
            'group_label' => __( 'Workflow runs', 'yaymatik-automation-for-woocommerce' ),
            'item_id'     => 'yaymatik-run-' . (int) $run['id'],
            'data'        => $data,
        ];
    }

    /**
     * @param string $name
     * @param string $value
     */
    private function value( string $name, string $value ): array {
        return [
            'name'  => $name,
            'value' => $value,
        ];
    }
}
