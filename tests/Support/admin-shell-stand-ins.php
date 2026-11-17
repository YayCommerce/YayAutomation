<?php
/**
 * Admin-shell's license contracts and LicenseState when vendor-prefixed isn't built (CI).
 *
 * @package YayMatik
 */

// phpcs:disable Squiz.Classes.ClassFileName.NoMatch, Generic.Files.OneObjectStructurePerFile, Universal.Namespaces.DisallowCurlyBraceSyntax, Universal.Namespaces.OneDeclarationPerFile -- stand-ins for one package.

namespace YayMatikScoped\YayCommerce\AdminShell\Contracts {

    /** Stand-in. */
    interface PluginMenuAdapter {
    }
}

namespace YayMatikScoped\YayCommerce\AdminShell\License\Contracts {

    use YayMatikScoped\YayCommerce\AdminShell\Contracts\PluginMenuAdapter;

    /** Stand-in. */
    interface LicenseConfigAdapter extends PluginMenuAdapter {
    }
}

namespace YayMatikScoped\YayCommerce\AdminShell\License {

    /** Stand-in: a stored key and license info that isn't revoked. */
    final class LicenseState {

        /** @param string $slug */
        public static function allows_features( string $slug ): bool {
            $info = get_option( $slug . '_license_info', [] );
            return '' !== (string) get_option( $slug . '_license_key', '' ) && ! empty( $info ) && 'disabled' !== ( $info['license'] ?? 'valid' );
        }
    }
}
