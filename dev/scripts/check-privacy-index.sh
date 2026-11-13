#!/bin/bash
# Check the privacy index (wp-cli): every lookup against the old full scan, before and after an erase and a delete. Removes its runs.
#   ./run.sh check-privacy-index [--wp-env|--wp-env-min]
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1

# A module with `privacy_subjects`, registered as a plugin would; inside the checkout so wp-env sees it.
early=$(mktemp dev/.privacy-module.XXXXXX) && chmod 644 "$early" || exit 1
trap 'rm -f "$early"' EXIT
cat > "$early" <<'PHP'
<?php
WP_CLI::add_wp_hook(
    'yaymatik_register',
    fn( $registrar ) => $registrar->module(
        [
            'id'               => 'pidx_ext',
            'label'            => 'Privacy check module',
            'privacy_subjects' => fn( string $email ) => 0 === strpos( $email, 'ext@' ) ? [ 'pidx_ext_thing:7', 'order:1' ] : [],
        ]
    )->data_item(
        'pidx_ext_thing',
        [
            'label'       => 'Thing',
            'description' => 'A thing about a person.',
            'load'        => fn( string $id ) => (object) [ 'id' => $id ],
        ]
    )
);
PHP

"${WP[@]}" --require="$early" eval-file dev/scripts/check-privacy-index.php
