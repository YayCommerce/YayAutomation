# Sourced with the target as $1: sets WP (the wp-cli command) and PLUGIN_FOLDER (the plugin's folder on that site).
case $1 in
  --wp-env)
    WP=(pnpm wp-env run cli wp)
    PLUGIN_FOLDER=$(basename "$PWD")
    ;;
  --staging)
    [[ -n $STAGING_SSH ]] || { echo "Set STAGING_SSH in dev/.env.local"; return 1; }
    WP=(wp --ssh="$STAGING_SSH")
    PLUGIN_FOLDER=yaymatik
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
