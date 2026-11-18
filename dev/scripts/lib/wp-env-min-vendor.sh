# Sourced by wp-target.sh for --wp-env-min, from the plugin root, after wp_env_min and PLUGIN_FOLDER are set.
# The PHP 7.4 site can't load the checkout's vendor/: its dev packages need PHP 8.1 (thecodingmachine/safe 3). So it gets
# a vendor/ without them, built in dev/wp-env-min/build and mapped over the plugin's vendor/ by .wp-env.override.json.
min_build=dev/wp-env-min/build
min_stamp=$(cat composer.json composer.lock | cksum)
if [[ $(cat "$min_build/stamp" 2>/dev/null) != "$min_stamp" ]]; then
    echo "wp-env-min: building vendor/ without dev packages..."
    rm -rf "$min_build" && mkdir -p "$min_build" || return 1
    # The classmap lists vendor-prefixed/src, read from beside composer.json.
    cp composer.json composer.lock "$min_build/" && cp -R vendor-prefixed "$min_build/" || return 1
    composer install --no-dev --no-scripts --quiet --working-dir="$min_build" || return 1
    echo "$min_stamp" > "$min_build/stamp"
fi
min_override="{ \"mappings\": { \"wp-content/plugins/$PLUGIN_FOLDER/vendor\": \"./build/vendor\" } }"
if [[ $(cat dev/wp-env-min/.wp-env.override.json 2>/dev/null) != "$min_override" ]]; then
    echo "$min_override" > dev/wp-env-min/.wp-env.override.json
    echo "wp-env-min: restarting with the vendor/ mapping..."
    wp_env_min start || return 1
fi
