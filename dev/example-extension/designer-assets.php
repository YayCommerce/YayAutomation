<?php
/**
 * The designer's style and script when YayMatik embeds it: `ready`, `change`, `save` (design id and name), `cancel`.
 *
 * @package AcmeRewards
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- another plugin: its own text domain.

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only query args of our own page.
$acme_embedded = ! empty( $_GET['yaymatik_embed'] );
$acme_saved    = absint( $_GET['saved'] ?? 0 );
if ( ! $acme_embedded ) {
    return;
}
// phpcs:disable WordPress.WP.EnqueuedResources -- a dev-only example page, printed as is.
?>
<style>
    #adminmenumain, #wpadminbar, #wpfooter, .notice { display: none; }
    html.wp-toolbar { padding-top: 0; }
    #wpcontent { margin-left: 0; }
</style>
<script>
document.addEventListener( 'DOMContentLoaded', function () {
    var params = new URLSearchParams( window.location.search );
    var channel = params.get( 'yaymatik_channel' );
    var send = function ( type, payload ) {
        var message = Object.assign( { source: 'yaymatik-editor', version: 1, channel: channel, type: type }, payload || {} );
        window.parent.postMessage( message, window.location.origin );
    };
    var form = document.getElementById( 'acme-design-form' );
    var saved = <?php echo (int) $acme_saved; ?>;

    window.addEventListener( 'message', function ( event ) {
        var data = event.data || {};
        if ( event.origin !== window.location.origin || event.source !== window.parent || data.source !== 'yaymatik-editor' || data.channel !== channel ) {
            return;
        }
        // `init` carries value, config, sample, locale and variables; this simple designer needs none of them.
    } );
    form.addEventListener( 'input', function () {
        send( 'change', { value: form.elements.design.value, dirty: true } );
    } );
    document.getElementById( 'acme-cancel' ).addEventListener( 'click', function () {
        send( 'cancel' );
    } );
    if ( saved ) {
        send( 'save', { value: String( saved ), label: form.elements.title.value } );
    }
    send( 'ready' );
} );
</script>
