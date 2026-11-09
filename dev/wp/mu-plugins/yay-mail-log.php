<?php
/**
 * Plugin Name: Yay Mail Log
 * Description: Stops all outgoing mail and keeps it under Tools > Mail log. For staging and wp-env only.
 *
 * @package YayMatik
 */

defined( 'ABSPATH' ) || exit;

// First, so a mailer that sends from this filter sees the mail already handled. deploy.sh refuses a site with another callback here.
add_filter( 'pre_wp_mail', 'yay_mail_log_capture', PHP_INT_MIN, 2 );
add_action( 'admin_menu', 'yay_mail_log_menu' );

/**
 * Stores the mail and reports whether that worked, so wp_mail never sends.
 *
 * @param mixed $short_circuit
 * @param array $atts
 */
function yay_mail_log_capture( $short_circuit, $atts ) {
    $headers = implode( "\n", (array) $atts['headers'] );
    // wp_mail applies this filter after pre_wp_mail, and WC_Email sets its type through it around the send.
    $is_html = false !== stripos( $headers, 'text/html' ) || 'text/html' === apply_filters( 'wp_mail_content_type', 'text/plain' );

    // Cron and customer requests lack unfiltered_html, and kses would strip the email's styles and layout.
    kses_remove_filters();
    $stored = wp_insert_post(
        [
            // A set slug: otherwise WordPress runs one query per earlier mail with this subject.
            'post_name'      => wp_generate_uuid4(),
            'post_type'      => 'yay_mail_log',
            'post_status'    => 'private',
            'post_mime_type' => $is_html ? 'text/html' : 'text/plain',
            'meta_input'     => [ 'headers' => wp_slash( $headers ) ],
            'post_title'     => wp_slash( (string) $atts['subject'] ),
            'post_excerpt'   => wp_slash( implode( ', ', (array) $atts['to'] ) ),
            'post_content'   => wp_slash( (string) $atts['message'] ),
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
    // False fails the step that sent it, so a mail the log lost never looks delivered.
    return (bool) $stored;
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
    $body = 'text/html' === $mail->post_mime_type ? $mail->post_content : '<pre>' . esc_html( $mail->post_content ) . '</pre>';
    printf(
        '<p><a href="%s">&larr; All mail</a></p><p><strong>To:</strong> %s<br><strong>Subject:</strong> %s</p><pre>%s</pre>',
        esc_url( admin_url( 'tools.php?page=yay-mail-log' ) ),
        esc_html( $mail->post_excerpt ),
        esc_html( $mail->post_title ),
        esc_html( get_post_meta( $mail->ID, 'headers', true ) )
    );
    printf(
        '<iframe title="Mail body" sandbox="allow-popups allow-popups-to-escape-sandbox" style="width:100%%;height:70vh;background:#fff" srcdoc="%s"></iframe>',
        // Not esc_attr: it keeps existing entities, so srcdoc would decode the mail's &lt; back into a tag.
        htmlspecialchars( '<base target="_blank">' . $body, ENT_QUOTES, 'UTF-8' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    );
}
