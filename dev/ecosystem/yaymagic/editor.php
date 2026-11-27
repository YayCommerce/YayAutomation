<?php
/**
 * YayMagic's fake editor page, as YayMatik opens it in its modal: postMessage v1 (README.md).
 *
 * @package YayMagic
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- another plugin: its own text domain.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only query args of our own page.
// phpcs:disable WordPress.WP.EnqueuedResources -- a dev-only page, printed as is.

/** @var YayMagic_YayMatik_Fake_Adapter $this Included from its `render_editor()`. */
$yaymagic_designs = $this->designs();
$yaymagic_id      = absint( $_GET['design'] ?? 0 );
$yaymagic_design  = $yaymagic_designs[ $yaymagic_id ] ?? null;
$yaymagic_saved   = absint( $_GET['saved'] ?? 0 );
?>
<style>
    #adminmenumain, #wpadminbar, #wpfooter, .notice { display: none; }
    html.wp-toolbar { padding-top: 0; }
    #wpcontent { margin-left: 0; }
    .yaymagic-editor { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 24px; }
    .yaymagic-editor pre { white-space: pre-wrap; background: #f6f7f7; padding: 8px; max-height: 160px; overflow: auto; }
    .yaymagic-variables { display: flex; flex-wrap: wrap; gap: 4px; max-height: 320px; overflow: auto; }
</style>
<div class="wrap">
    <h1><?php esc_html_e( 'YayMagic editor (fake)', 'yaymagic' ); ?></h1>
    <div class="yaymagic-editor">
        <form method="post" id="yaymagic-form">
            <?php wp_nonce_field( 'yaymagic_design' ); ?>
            <p>
                <label for="yaymagic-design"><?php esc_html_e( 'Design', 'yaymagic' ); ?></label><br>
                <select id="yaymagic-design" name="design">
                    <option value="0"><?php esc_html_e( 'New design', 'yaymagic' ); ?></option>
                    <?php foreach ( $yaymagic_designs as $yaymagic_key => $yaymagic_option ) : ?>
                        <option value="<?php echo esc_attr( $yaymagic_key ); ?>" <?php selected( $yaymagic_design ? $yaymagic_id : 0, $yaymagic_key ); ?>><?php echo esc_html( $yaymagic_option['name'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="yaymagic-name"><?php esc_html_e( 'Name', 'yaymagic' ); ?></label><br>
                <input id="yaymagic-name" name="name" class="regular-text" required value="<?php echo esc_attr( $yaymagic_design['name'] ?? '' ); ?>">
            </p>
            <p>
                <label for="yaymagic-body"><?php esc_html_e( 'Body (HTML; click a variable to insert it)', 'yaymagic' ); ?></label><br>
                <textarea id="yaymagic-body" name="body" rows="12" class="large-text code"><?php echo esc_textarea( $yaymagic_design['body'] ?? '<p>Hi {{ customer.first_name | default: "there" }},</p>' ); ?></textarea>
            </p>
            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'yaymagic' ); ?></button>
                <button type="button" class="button" id="yaymagic-cancel"><?php esc_html_e( 'Cancel', 'yaymagic' ); ?></button>
            </p>
        </form>
        <div>
            <h2><?php esc_html_e( 'From YayMatik', 'yaymagic' ); ?></h2>
            <p id="yaymagic-waiting"><?php esc_html_e( 'Waiting for init…', 'yaymagic' ); ?></p>
            <p><?php esc_html_e( 'Sample order', 'yaymagic' ); ?>: <code id="yaymagic-order">–</code> · <?php esc_html_e( 'Sample customer', 'yaymagic' ); ?>: <code id="yaymagic-user">–</code> · <?php esc_html_e( 'Locale', 'yaymagic' ); ?>: <code id="yaymagic-locale">–</code></p>
            <p><?php esc_html_e( 'Step config', 'yaymagic' ); ?></p>
            <pre id="yaymagic-config"></pre>
            <p><?php esc_html_e( 'Variables for this step', 'yaymagic' ); ?></p>
            <div class="yaymagic-variables" id="yaymagic-variables"></div>
        </div>
    </div>
</div>
<script>
( function () {
    var channel = new URLSearchParams( window.location.search ).get( 'yaymatik_channel' );
    var send = function ( type, payload ) {
        if ( ! channel ) {
            return;
        }
        var message = Object.assign( { source: 'yaymatik-editor', version: 1, channel: channel, type: type }, payload || {} );
        window.parent.postMessage( message, window.location.origin );
    };
    var form = document.getElementById( 'yaymagic-form' );
    var body = document.getElementById( 'yaymagic-body' );
    var changed = function () {
        send( 'change', { value: form.elements.design.value, dirty: true } );
    };
    var insert = function ( path ) {
        var tag = '{{ ' + path + ' }}';
        var start = body.selectionStart;
        body.setRangeText( tag, start, body.selectionEnd, 'end' );
        body.focus();
        changed();
    };

    window.addEventListener( 'message', function ( event ) {
        var data = event.data || {};
        if ( event.origin !== window.location.origin || event.source !== window.parent || data.source !== 'yaymatik-editor' || data.channel !== channel || data.type !== 'init' ) {
            return;
        }
        document.getElementById( 'yaymagic-waiting' ).hidden = true;
        document.getElementById( 'yaymagic-order' ).textContent = String( data.sample && data.sample.orderId );
        document.getElementById( 'yaymagic-user' ).textContent = String( data.sample && data.sample.userId );
        document.getElementById( 'yaymagic-locale' ).textContent = String( data.locale );
        document.getElementById( 'yaymagic-config' ).textContent = JSON.stringify( data.config, null, 2 );
        var list = document.getElementById( 'yaymagic-variables' );
        list.textContent = '';
        ( data.variables || [] ).forEach( function ( variable ) {
            var button = document.createElement( 'button' );
            button.type = 'button';
            button.className = 'button button-small';
            button.textContent = variable.label;
            button.title = variable.path;
            button.addEventListener( 'click', function () {
                insert( variable.path );
            } );
            list.appendChild( button );
        } );
    } );
    form.elements.design.addEventListener( 'change', function () {
        var url = new URL( window.location.href );
        url.searchParams.set( 'design', form.elements.design.value );
        url.searchParams.delete( 'saved' );
        window.location.href = url.toString();
    } );
    // Picking a design reloads the page: not an edit.
    form.addEventListener( 'input', function ( event ) {
        if ( event.target !== form.elements.design ) {
            changed();
        }
    } );
    document.getElementById( 'yaymagic-cancel' ).addEventListener( 'click', function () {
        send( 'cancel' );
    } );
    <?php if ( $yaymagic_saved ) : ?>
    send( 'save', { value: <?php echo wp_json_encode( (string) $yaymagic_saved ); ?>, label: form.elements.name.value } );
    // A reload of this page must not save again.
    var url = new URL( window.location.href );
    url.searchParams.delete( 'saved' );
    window.history.replaceState( null, '', url.toString() );
    <?php endif; ?>
    send( 'ready' );
}() );
</script>
