<?php
/**
 * Acme's designer page. Opened by YayMatik in its modal with `yaymatik_embed=1&yaymatik_channel=…`; the
 * script in designer-assets.php talks to YayMatik (postMessage v1, docs/extension-api.md §5.6).
 *
 * @package AcmeRewards
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- another plugin: its own text domain.

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only query args of our own page.
$acme_design  = get_post( absint( $_GET['design'] ?? 0 ) );
$acme_design  = $acme_design && Acme_Rewards::DESIGN === $acme_design->post_type ? $acme_design : null;
$acme_designs = Acme_Rewards::instance()->design_options();
?>
<div class="wrap acme-designer">
    <h1><?php esc_html_e( 'Acme email designer', 'acme-rewards' ); ?></h1>
    <form method="post" id="acme-design-form">
        <?php wp_nonce_field( 'acme_design' ); ?>
        <p>
            <label for="acme-design"><?php esc_html_e( 'Design', 'acme-rewards' ); ?></label><br>
            <select id="acme-design" name="design">
                <option value="0"><?php esc_html_e( 'New design', 'acme-rewards' ); ?></option>
                <?php foreach ( $acme_designs as $acme_id => $acme_title ) : ?>
                    <option value="<?php echo esc_attr( $acme_id ); ?>" <?php selected( $acme_design ? $acme_design->ID : 0, (int) $acme_id ); ?>><?php echo esc_html( $acme_title ); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="acme-title"><?php esc_html_e( 'Name', 'acme-rewards' ); ?></label><br>
            <input id="acme-title" name="title" class="regular-text" required value="<?php echo esc_attr( $acme_design ? $acme_design->post_title : '' ); ?>">
        </p>
        <p>
            <label for="acme-content"><?php esc_html_e( 'Message (HTML, YayMatik merge tags work)', 'acme-rewards' ); ?></label><br>
            <textarea id="acme-content" name="content" rows="10" class="large-text"><?php echo esc_textarea( $acme_design ? $acme_design->post_content : '<p>You have {{ customer.acme_rewards_points }} points.</p>' ); ?></textarea>
        </p>
        <p>
            <label><input type="checkbox" name="uses_order" value="1" <?php checked( $acme_design && get_post_meta( $acme_design->ID, '_acme_uses_order', true ) ); ?>> <?php esc_html_e( 'Shows order details', 'acme-rewards' ); ?></label>
        </p>
        <p>
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'acme-rewards' ); ?></button>
            <button type="button" class="button" id="acme-cancel"><?php esc_html_e( 'Cancel', 'acme-rewards' ); ?></button>
        </p>
    </form>
</div>
