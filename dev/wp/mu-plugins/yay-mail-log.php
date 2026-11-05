<?php
/**
 * Plugin Name: Yay Mail Log
 * Description: Stops all outgoing mail and keeps it under Tools > Mail log. For staging and wp-env only.
 *
 * @package YayMatik
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'pre_wp_mail', 'yay_mail_log_capture', PHP_INT_MAX, 2 );
add_action( 'admin_menu', 'yay_mail_log_menu' );

/**
 * Stores the mail and reports success, so wp_mail never sends and callers carry on as if it had.
 *
 * @param mixed $short_circuit
 * @param array $atts
 */
function yay_mail_log_capture( $short_circuit, $atts ) {
    // Cron and customer requests lack unfiltered_html, and kses would strip the email's styles and layout.
    kses_remove_filters();
    wp_insert_post(
        [
            'post_type'    => 'yay_mail_log',
            'post_status'  => 'private',
            'post_title'   => wp_slash( (string) $atts['subject'] ),
            'post_excerpt' => wp_slash( implode( ', ', (array) $atts['to'] ) ),
            'post_content' => wp_slash( (string) $atts['message'] ),
        ]
    );
    kses_init();

    $old = get_posts(
        [
            'post_type'   => 'yay_mail_log',
            'post_status' => 'private',
            'fields'      => 'ids',
            'offset'      => 500,
            'numberposts' => 50,
        ]
    );
    foreach ( $old as $id ) {
        wp_delete_post( $id, true );
    }
    return true;
}

/** Adds Tools > Mail log. */
function yay_mail_log_menu() {
    add_management_page( 'Mail log', 'Mail log', 'manage_options', 'yay-mail-log', 'yay_mail_log_page' );
}

/** Lists the logged mail, or shows one. */
function yay_mail_log_page() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $mail = isset( $_GET['mail'] ) ? get_post( absint( $_GET['mail'] ) ) : null;
    echo '<div class="wrap"><h1>Mail log</h1>';
    if ( $mail && 'yay_mail_log' === $mail->post_type ) {
        yay_mail_log_show( $mail );
    } else {
        yay_mail_log_list();
    }
    echo '</div>';
}

/** Prints the table of logged mail, newest first. */
function yay_mail_log_list() {
    $mails = get_posts(
        [
            'post_type'   => 'yay_mail_log',
            'post_status' => 'private',
            'numberposts' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts
        ]
    );
    echo '<table class="widefat striped"><thead><tr><th>Sent</th><th>To</th><th>Subject</th></tr></thead><tbody>';
    foreach ( $mails as $mail ) {
        printf(
            '<tr><td>%s</td><td>%s</td><td><a href="%s">%s</a></td></tr>',
            esc_html( $mail->post_date ),
            esc_html( $mail->post_excerpt ),
            esc_url( admin_url( 'tools.php?page=yay-mail-log&mail=' . $mail->ID ) ),
            esc_html( '' === $mail->post_title ? '(no subject)' : $mail->post_title )
        );
    }
    echo '</tbody></table>';
}

/**
 * Prints one mail. The sandbox keeps its HTML from running in wp-admin; links open in a tab so testers can follow them.
 *
 * @param WP_Post $mail
 */
function yay_mail_log_show( $mail ) {
    $body = wp_strip_all_tags( $mail->post_content ) === $mail->post_content
        ? '<pre>' . esc_html( $mail->post_content ) . '</pre>'
        : $mail->post_content;
    printf(
        '<p><a href="%s">&larr; All mail</a></p><p><strong>To:</strong> %s<br><strong>Subject:</strong> %s</p>',
        esc_url( admin_url( 'tools.php?page=yay-mail-log' ) ),
        esc_html( $mail->post_excerpt ),
        esc_html( $mail->post_title )
    );
    printf(
        '<iframe title="Mail body" sandbox="allow-popups allow-popups-to-escape-sandbox" style="width:100%%;height:70vh;background:#fff" srcdoc="%s"></iframe>',
        esc_attr( '<base target="_blank">' . $body )
    );
}
