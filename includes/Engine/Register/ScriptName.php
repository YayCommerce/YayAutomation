<?php
namespace YayMatik\Engine\Register;

defined( 'ABSPATH' ) || exit;

/** Asset handles. */
class ScriptName {

    public const STYLE_ADMIN = 'yaymatik/style/admin';

    public const MODULE_PREFIX = 'yaymatik/module/';

    public const PAGE_ADMIN = 'yaymatik/module/page-admin';

    /** WordPress handles of the admin script's externals (apps/admin-page/vite.config.ts). */
    public const ADMIN_DEPS = [ 'react', 'react-dom', 'react-jsx-runtime', 'wp-hooks', 'wp-i18n', 'wp-date' ];
}
