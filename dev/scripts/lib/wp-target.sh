# Sourced with the target as $1: sets WP (the wp-cli command) and PLUGIN_FOLDER (the plugin's folder on that site).
# With `check` as $2 it refuses staging: check scripts write test rows.
# On wp-env the cli runs in the plugin folder, so `eval-file dev/scripts/…` finds the file in the container.
[[ $2 == check && $1 == --staging ]] && { echo "Refused: check scripts never run on staging."; return 1; }
case $1 in
  --wp-env)
    PLUGIN_FOLDER=$(basename "$PWD")
    WP=(pnpm wp-env run cli --env-cwd="wp-content/plugins/$PLUGIN_FOLDER" wp)
    ;;
  --wp-env-min)
    PLUGIN_FOLDER=$(basename "$PWD")
    # A separate folder is a separate wp-env site: WordPress, PHP and WooCommerce at the minimum versions.
    wp_env_min() { (cd dev/wp-env-min && ../../node_modules/.bin/wp-env "$@"); }
    WP=(wp_env_min run cli --env-cwd="wp-content/plugins/$PLUGIN_FOLDER" wp)
    ;;
  --staging)
    [[ -n $STAGING_SSH && -n $STAGING_URL ]] || { echo "Set STAGING_SSH (user@host[:port]/wp/path) and STAGING_URL in dev/.env.local"; return 1; }
    WP=(wp --ssh="$STAGING_SSH")
    PLUGIN_FOLDER=yaymatik
    # Never write to a site other than the one named in STAGING_URL.
    home=$("${WP[@]}" option get home) || return 1
    [[ ${home%/} == "${STAGING_URL%/}" ]] || { echo "Refused: $STAGING_SSH is $home, not $STAGING_URL"; return 1; }
    ;;
  '')
    command -v wp >/dev/null || { echo "Missing: wp-cli"; return 1; }
    WP=(wp ${WP_PATH:+--path="$WP_PATH"})
    PLUGIN_FOLDER=$(basename "$PWD")
    ;;
  *)
    echo "Unknown target: $1. Use --wp-env, --wp-env-min or --staging, or nothing for the local site."
    return 1
    ;;
esac
