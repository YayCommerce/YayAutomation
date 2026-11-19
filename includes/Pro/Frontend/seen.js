/**
 * Tells the store a signed-in person is here, also when a page cache served the page without PHP. One beacon per 15 minutes across tabs.
 *
 * @package YayMatik
 */

( function () {
    var key = 'yaymatik_seen';
    var now = Date.now();
    try {
        if ( now - ( Number( window.localStorage.getItem( key ) ) || 0 ) < 15 * 60 * 1000 ) {
            return;
        }
        window.localStorage.setItem( key, String( now ) );
    } catch ( e ) {
        // No storage (private mode): a beacon per page, the server still writes at most every 15 minutes.
    }
    if ( window.navigator.sendBeacon && window.yayMatikSeen ) {
        window.navigator.sendBeacon( window.yayMatikSeen.url );
    }
} )();
