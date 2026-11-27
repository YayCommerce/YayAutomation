#!/bin/bash
# Write stubs/yaymatik-extension-api.php, the PHPStan stubs of includes/Api, for extensions' own CI.
#   ./run.sh extension-stubs
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }

mkdir -p stubs
php -r '
define( "ABSPATH", "/" );
require "dev/scripts/lib/extension-stubs.php";
file_put_contents( "stubs/yaymatik-extension-api.php", yaymatik_extension_stubs( "includes/Api" ) );
' && echo "Wrote stubs/yaymatik-extension-api.php"
