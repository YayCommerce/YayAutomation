# Sourced with the target as $1: sets WP (the wp-cli command) and PLUGIN_FOLDER (the plugin's folder on that site).
case $1 in
  --wp-env)
    WP=(pnpm wp-env run cli wp)
    PLUGIN_FOLDER=$(basename "$PWD")
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
    echo "Unknown target: $1. Use --wp-env or --staging, or nothing for the local site."
    return 1
    ;;
esac
