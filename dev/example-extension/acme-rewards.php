<?php
/**
 * Plugin Name: Acme Rewards for YayMatik (example)
 * Description: Every piece of YayMatik's Extension API v1 in one imaginary loyalty plugin (docs/extension-api.md). Development only.
 * Version:     1.0.0
 * Requires Plugins: woocommerce
 * Text Domain: acme-rewards
 *
 * Acme Rewards gives customers points (user meta `acme_points`), lets them redeem rewards (post type `acme_reward`)
 * and designs emails in its own editor (post type `acme_design`). A completed order gives its customer
 * floor( total ) points. The YayMatik integration is `register()` and the methods it names: arrays, two
 * `do_action( 'yaymatik_fire', … )` lines and the `$run` object. No YayMatik class is extended and no JS is built.
 *
 * @package AcmeRewards
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.I18n.TextDomainMismatch, Squiz.Classes.ClassFileName -- another plugin: its own text domain and file name.

/** The plugin, with its YayMatik integration. */
final class Acme_Rewards {

    const DESIGN = 'acme_design';

    const REWARD = 'acme_reward';

    const DESIGNER = 'acme-rewards-designer';

    /** @var Acme_Rewards|null */
    private static $instance = null;

    public static function instance(): self {
        self::$instance = self::$instance ?? new self();
        return self::$instance;
    }

    public static function init() {
        $plugin = self::instance();
        add_action( 'init', [ $plugin, 'register_types' ] );
        // Fires only when YayMatik is active: no class_exists() check needed.
        add_action( 'yaymatik_register', [ $plugin, 'register' ] );
        // A real event to try it with: complete an order in WooCommerce › Orders.
        add_action( 'woocommerce_order_status_completed', [ $plugin, 'on_order_completed' ] );
        add_action( 'admin_menu', [ $plugin, 'add_designer' ] );
    }

    // ---------------------------------------------------------------------------------------------
    // The YayMatik integration.
    // ---------------------------------------------------------------------------------------------

    /**
     * @param \YayMatik\Api\Registrar $registrar
     */
    public function register( $registrar ) {
        if ( ! $registrar->supports( 'editor_iframe' ) ) {
            // An older YayMatik: register nothing rather than half.
            return;
        }

        // 1. Module = this plugin. Every id below starts with "acme_rewards_".
        $module = $registrar->module(
            [
                'id'               => 'acme_rewards',
                'label'            => 'Acme Rewards',
                'description'      => __( 'Reward points for your customers.', 'acme-rewards' ),
                'logo'             => plugins_url( 'logo.svg', __FILE__ ),
                'requires_api'     => '1.0',
                'privacy_subjects' => [ $this, 'privacy_subjects' ],
            ]
        );
        if ( ! $module->ok() ) {
            // The reason is in YayMatik › Settings › Health check › Extensions.
            return;
        }

        // 2. A data field on a core item: a condition ("Points balance is greater than 500") and a merge tag.
        $module->data_fields(
            'customer',
            [
                'acme_rewards_points' => [
                    'label'  => __( 'Points balance', 'acme-rewards' ),
                    'type'   => 'number',
                    'value'  => [ $this, 'customer_points' ],
                    'sample' => 340,
                ],
            ]
        );

        // 3. Our own data item: a redeemed reward. Declared before the trigger that provides it.
        $module->data_item(
            'acme_rewards_reward',
            [
                'label'       => __( 'Reward', 'acme-rewards' ),
                'description' => __( 'A reward a customer redeemed.', 'acme-rewards' ),
                'icon'        => 'gift',
                'load'        => [ $this, 'load_reward' ],
                'fields'      => [
                    'title' => [
                        'label' => __( 'Reward', 'acme-rewards' ),
                        'type'  => 'string',
                        'value' => [ $this, 'reward_title' ],
                    ],
                    'cost'  => [
                        'label' => __( 'Points spent', 'acme-rewards' ),
                        'type'  => 'number',
                        'value' => [ $this, 'reward_cost' ],
                    ],
                    'email' => [
                        'label' => __( 'Customer email', 'acme-rewards' ),
                        'type'  => 'string',
                        'value' => [ $this, 'reward_email' ],
                    ],
                ],
                'sample'      => [
                    'title' => 'Free shipping',
                    'cost'  => 200,
                    'email' => 'alex@example.com',
                ],
            ]
        );

        // 4. Triggers are descriptions: they start when earn() / redeem() call do_action( 'yaymatik_fire', … ).
        $module->trigger(
            'acme_rewards_points_earned',
            [
                'label'       => __( 'Customer earned points', 'acme-rewards' ),
                'description' => __( 'Starts when a customer gets reward points.', 'acme-rewards' ),
                'category'    => 'customer',
                'provides'    => [ 'customer' ],
                'facts'       => [
                    'points' => [
                        'label' => __( 'Points earned', 'acme-rewards' ),
                        'type'  => 'number',
                    ],
                ],
                'fields'      => [
                    'min_points' => [
                        'type'    => 'number',
                        'label'   => __( 'Only when at least', 'acme-rewards' ),
                        'min'     => 1,
                        'default' => 1,
                    ],
                ],
                'matches'     => [ $this, 'matches_min_points' ],
                'sample'      => [ 'points' => 25 ],
            ]
        );
        $module->trigger(
            'acme_rewards_reward_redeemed',
            [
                'label'       => __( 'Reward redeemed', 'acme-rewards' ),
                'description' => __( 'Starts when a customer redeems a reward.', 'acme-rewards' ),
                'category'    => 'customer',
                'provides'    => [ 'acme_rewards_reward' ],
                // Once per reward per workflow.
                'once_per'    => 'subject',
            ]
        );

        // 5. A plain action.
        $module->action(
            'acme_rewards_add_points',
            [
                'label'       => __( 'Add reward points', 'acme-rewards' ),
                'description' => __( 'Gives the customer reward points.', 'acme-rewards' ),
                'category'    => 'customer',
                'requires'    => [ 'customer' ],
                'fields'      => [
                    'points' => [
                        'type'     => 'number',
                        'label'    => __( 'Points', 'acme-rewards' ),
                        'min'      => 1,
                        'required' => true,
                    ],
                ],
                /* translators: {{ config.points }}: a number; keep {{ … }} as it is. */
                'summary'     => __( 'Add {{ config.points }} points', 'acme-rewards' ),
                'outputs'     => [
                    'balance' => [
                        'label' => __( 'New balance', 'acme-rewards' ),
                        'type'  => 'number',
                    ],
                ],
                /* translators: {{ output.balance }}: a number; keep {{ … }} as it is. */
                'result'      => __( 'New balance: {{ output.balance }}', 'acme-rewards' ),
                // Adding points fires our trigger: the editor warns when a workflow could start itself again.
                'emits'       => [ 'acme_rewards_points_earned' ],
                'run'         => [ $this, 'run_add_points' ],
            ]
        );

        // 6. An email action designed in our own editor. YayMatik draws Preview / Send test, sends, handles
        // unsubscribe + List-Unsubscribe, "Sent to …" and Skipped reasons.
        $module->action(
            'acme_rewards_send_email',
            [
                'label'       => __( 'Send rewards email', 'acme-rewards' ),
                'description' => __( 'Sends an email designed in Acme Rewards.', 'acme-rewards' ),
                'category'    => 'email',
                'fields'      => [
                    'subject'   => [
                        'type'      => 'text',
                        'label'     => __( 'Subject', 'acme-rewards' ),
                        'variables' => true,
                        'required'  => true,
                    ],
                    'design_id' => [
                        'type'     => 'select',
                        'label'    => __( 'Design', 'acme-rewards' ),
                        'options'  => [ $this, 'design_options' ],
                        'required' => true,
                    ],
                ],
                /* translators: {{ config.subject }}: the email's subject; keep {{ … }} as it is. */
                'summary'     => __( 'Email “{{ config.subject }}”', 'acme-rewards' ),
                'validate'    => [ $this, 'validate_design' ],
                'setup_issue' => [ $this, 'email_setup_issue' ],
                'email'       => [
                    'audience'  => 'customer',
                    'recipient' => 'customer',
                    'build'     => [ $this, 'build_email' ],
                ],
                'editor'      => [
                    'field'  => 'design_id',
                    'button' => __( 'Customize design', 'acme-rewards' ),
                    'url'    => [ $this, 'designer_url' ],
                ],
            ]
        );

        // 7. A ready-made workflow in Browse templates, in the "Acme Rewards" group.
        $module->template(
            'acme_rewards_welcome_bonus',
            [
                'name'        => __( 'Welcome bonus points', 'acme-rewards' ),
                'description' => __( 'Give new customers 100 points and tell them by email.', 'acme-rewards' ),
                'category'    => 'customer_lifecycle',
                'created'     => '2026-12-01',
                'before'      => [ __( 'Pick or design the email in Acme Rewards after you add the template.', 'acme-rewards' ) ],
                'steps'       => [
                    // A core trigger: public and semver'd, like every core step a template may use.
                    [
                        'id'   => 'trigger',
                        'step' => 'customer_account_created',
                    ],
                    [
                        'id'     => 'bonus',
                        'step'   => 'acme_rewards_add_points',
                        'config' => [ 'points' => 100 ],
                    ],
                    [
                        'id'     => 'wait',
                        'step'   => 'wait',
                        'config' => [
                            'delay' => [
                                'value' => 10,
                                'unit'  => 'minutes',
                            ],
                        ],
                    ],
                    [
                        'id'     => 'email',
                        'step'   => 'acme_rewards_send_email',
                        // The store owner picks the design with "Customize design".
                        'config' => [
                            'subject'   => __( 'You have {{ steps.bonus.balance }} points, {{ customer.first_name | default: "friend" }}!', 'acme-rewards' ),
                            'design_id' => '',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * "Customer earned points" settings: only when at least min_points were earned.
     *
     * @param array $payload
     * @param array $config
     */
    public function matches_min_points( array $payload, array $config ): bool {
        return (int) $payload['points'] >= (int) ( $config['min_points'] ?? 1 );
    }

    /**
     * The "Add reward points" action.
     *
     * @param array             $config
     * @param \YayMatik\Api\Run $run
     */
    public function run_add_points( array $config, $run ) {
        $user = $run->user();
        if ( ! $user ) {
            return $run->skip( __( 'Guests can’t collect points.', 'acme-rewards' ) );
        }
        return $run->done( [ 'balance' => $this->earn( $user->ID, (int) $config['points'] ) ] );
    }

    /**
     * "Points balance": the customer is the same object whatever the trigger (order, cart, account, review).
     *
     * @param \YayMatik\Api\CustomerView $customer
     */
    public function customer_points( $customer ): int {
        return $customer->user_id() ? (int) get_user_meta( $customer->user_id(), 'acme_points', true ) : 0;
    }

    /**
     * A reward by id; null when it's gone (the step reads "… no longer exists.").
     *
     * @param string $id
     */
    public function load_reward( string $id ) {
        $post = get_post( (int) $id );
        return $post && self::REWARD === $post->post_type ? $post : null;
    }

    /**
     * @param \WP_Post $reward
     */
    public function reward_title( $reward ): string {
        return $reward->post_title;
    }

    /**
     * @param \WP_Post $reward
     */
    public function reward_cost( $reward ): int {
        return (int) get_post_meta( $reward->ID, '_acme_cost', true );
    }

    /**
     * @param \WP_Post $reward
     */
    public function reward_email( $reward ): string {
        return (string) get_post_meta( $reward->ID, '_acme_email', true );
    }

    /**
     * Our runs about a person, for WordPress's export and erase: their rewards.
     *
     * @param string $email
     * @return string[]
     */
    public function privacy_subjects( string $email ): array {
        $ids = get_posts(
            [
                'post_type'   => self::REWARD,
                'post_status' => 'any',
                'numberposts' => -1,
                'fields'      => 'ids',
                'meta_key'    => '_acme_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_value'  => strtolower( $email ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            ]
        );
        return array_map( fn( $id ) => 'acme_rewards_reward:' . $id, $ids );
    }

    /** Why the email action can't run on this site yet; '' when it can. */
    public function email_setup_issue(): string {
        return $this->design_options() ? '' : __( 'Create a design in Acme Rewards first.', 'acme-rewards' );
    }

    /**
     * A design with order blocks only makes sense when the trigger has an order.
     *
     * @param array $config
     * @param array $available
     */
    public function validate_design( array $config, array $available ): array {
        if ( get_post_meta( (int) $config['design_id'], '_acme_uses_order', true ) && ! in_array( 'order', $available, true ) ) {
            return [ 'design_id' => __( 'This design shows order details, but this workflow’s trigger has no order.', 'acme-rewards' ) ];
        }
        return [];
    }

    /**
     * Subject + HTML; a real run builds it in a background job, so the customer and order come from $run, never globals.
     *
     * @param array             $config
     * @param \YayMatik\Api\Run $run
     */
    public function build_email( array $config, $run ): array {
        // YayMatik renders its {{ … }} tags in both afterwards. `wrap` false: a whole document of our own.
        return [
            'subject' => $config['subject'],
            'html'    => $this->render_design( (int) $config['design_id'], $run ),
            'wrap'    => false,
        ];
    }

    /**
     * Our designer, embedded: same site, so YayMatik opens it in its modal.
     *
     * @param array $context
     */
    public function designer_url( array $context ): string {
        return admin_url( 'admin.php?page=' . self::DESIGNER . '&design=' . rawurlencode( (string) $context['value'] ) );
    }

    // ---------------------------------------------------------------------------------------------
    // The plugin's own code (a real plugin has it already). Short, so the example runs.
    // ---------------------------------------------------------------------------------------------

    public function register_types() {
        register_post_type( self::DESIGN, [ 'public' => false ] );
        register_post_type( self::REWARD, [ 'public' => false ] );
    }

    /**
     * A completed order gives its customer (accounts only) floor( total ) points.
     *
     * @param int $order_id
     */
    public function on_order_completed( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order && $order->get_customer_id() ) {
            $this->earn( (int) $order->get_customer_id(), (int) floor( (float) $order->get_total() ) );
        }
    }

    /**
     * Adds points and tells YayMatik; the new balance.
     *
     * @param int $user_id
     * @param int $points
     */
    public function earn( int $user_id, int $points ): int {
        $balance = (int) get_user_meta( $user_id, 'acme_points', true ) + $points;
        update_user_meta( $user_id, 'acme_points', $balance );

        // Our own throttle: one YayMatik event per customer per hour (YayMatik has no dedupe of its own).
        $key = 'acme_ym_' . $user_id;
        if ( ! get_transient( $key ) ) {
            set_transient( $key, 1, HOUR_IN_SECONDS );
            // user_id → the run's customer and subject (user:ID); points → the fact {{ trigger.points }}.
            do_action(
                'yaymatik_fire',
                'acme_rewards_points_earned',
                [
                    'user_id' => $user_id,
                    'points'  => $points,
                ]
            );
        }
        return $balance;
    }

    /**
     * Spends points on a reward and tells YayMatik; the reward's id, 0 when it couldn't be saved.
     *
     * @param int    $user_id
     * @param string $title
     * @param int    $cost
     */
    public function redeem( int $user_id, string $title, int $cost ): int {
        $user = get_userdata( $user_id );
        $id   = (int) wp_insert_post(
            [
                'post_type'   => self::REWARD,
                'post_status' => 'publish',
                'post_title'  => $title,
                'post_author' => $user_id,
            ]
        );
        if ( ! $id ) {
            return 0;
        }
        update_post_meta( $id, '_acme_cost', $cost );
        update_post_meta( $id, '_acme_email', $user ? strtolower( $user->user_email ) : '' );
        update_user_meta( $user_id, 'acme_points', (int) get_user_meta( $user_id, 'acme_points', true ) - $cost );
        // <item>_id → the run's acme_rewards_reward and its subject (acme_rewards_reward:ID).
        do_action( 'yaymatik_fire', 'acme_rewards_reward_redeemed', [ 'acme_rewards_reward_id' => $id ] );
        return $id;
    }

    /** Design id => title. */
    public function design_options(): array {
        $options = [];
        foreach ( get_posts(
            [
                'post_type'   => self::DESIGN,
                'numberposts' => 100,
            ]
        ) as $post ) {
            $options[ (string) $post->ID ] = $post->post_title;
        }
        return $options;
    }

    /**
     * Our renderer: a design and the run's customer / order (our own sample in Preview with made-up data).
     *
     * @param int               $design_id
     * @param \YayMatik\Api\Run $run
     */
    private function render_design( int $design_id, $run ): string {
        $user  = $run->user();
        $order = $run->order();
        $name  = $user ? $user->first_name : 'Alex';
        $body  = wpautop( wp_kses_post( (string) get_post_field( 'post_content', $design_id ) ) );
        $extra = $order ? '<p>' . esc_html( sprintf( 'Order #%s', $order->get_order_number() ) ) . '</p>' : '';
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{font-family:sans-serif;color:#222}</style></head><body>'
            . '<h1>' . esc_html( 'Hi ' . $name ) . '</h1>' . $body . $extra
            . '<p><a href="' . esc_url( $run->unsubscribe_url() ) . '">Unsubscribe</a></p></body></html>';
    }

    public function add_designer() {
        // No menu entry: YayMatik opens it in its modal (and a real plugin would link it from its own menu).
        $hook = add_submenu_page( 'options.php', 'Acme designer', 'Acme designer', 'manage_woocommerce', self::DESIGNER, [ $this, 'render_designer' ] );
        add_action( "load-{$hook}", [ $this, 'save_design' ] );
        add_action( "admin_print_scripts-{$hook}", [ $this, 'designer_assets' ] );
    }

    public function render_designer() {
        require __DIR__ . '/designer.php';
    }

    public function designer_assets() {
        require __DIR__ . '/designer-assets.php';
    }

    /** The designer's form post: saves, then reloads the page with `saved`. */
    public function save_design() {
        if ( empty( $_POST ) ) {
            return;
        }
        check_admin_referer( 'acme_design' );
        // Only one of our designs is updated; anything else makes a new one.
        $design = absint( $_POST['design'] ?? 0 );
        $id     = (int) wp_insert_post(
            [
                'ID'           => self::DESIGN === get_post_type( $design ) ? $design : 0,
                'post_type'    => self::DESIGN,
                'post_status'  => 'publish',
                'post_title'   => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
                'post_content' => wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) ),
            ]
        );
        update_post_meta( $id, '_acme_uses_order', empty( $_POST['uses_order'] ) ? '' : '1' );
        $back = remove_query_arg( [ 'design', 'saved' ], esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) );
        wp_safe_redirect(
            add_query_arg(
                [
                    'design' => $id,
                    'saved'  => $id,
                ],
                $back
            )
        );
        exit;
    }
}

Acme_Rewards::init();
