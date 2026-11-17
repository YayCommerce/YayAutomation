<?php
/**
 * Admin-shell for the tests. AdminShell only records what registers. The license contracts and LicenseState are the
 * real ones when `./run.sh setup` built vendor-prefixed; CI can't reach that package, so stand-ins take their place
 * there (`STAND_IN`), and tests of the license policy itself are skipped.
 *
 * @package YayMatik
 */

// phpcs:disable Squiz.Classes.ClassFileName.NoMatch, Generic.Files.OneObjectStructurePerFile, Universal.Namespaces.DisallowCurlyBraceSyntax, Universal.Namespaces.OneDeclarationPerFile -- stand-ins for one package.

namespace YayMatikScoped\YayCommerce\AdminShell {

    use YayMatikScoped\YayCommerce\AdminShell\Contracts\PluginMenuAdapter;

    /** Stand-in: keeps the registered adapters. */
    class AdminShell {

        /** @var PluginMenuAdapter[] */
        public static $registered = [];

        /** @param PluginMenuAdapter $adapter */
        public static function register_plugin( PluginMenuAdapter $adapter ): void {
            self::$registered[] = $adapter;
        }
    }

    $yaymatik_admin_shell = dirname( __DIR__, 2 ) . '/vendor-prefixed/src/';
    if ( is_file( $yaymatik_admin_shell . 'License/LicenseState.php' ) ) {
        require_once $yaymatik_admin_shell . 'Contracts/PluginMenuAdapter.php';
        require_once $yaymatik_admin_shell . 'License/Contracts/LicenseConfigAdapter.php';
        require_once $yaymatik_admin_shell . 'License/LicenseState.php';
    } else {
        define( 'YayMatikScoped\YayCommerce\AdminShell\STAND_IN', true );
        require __DIR__ . '/admin-shell-stand-ins.php';
    }
}
