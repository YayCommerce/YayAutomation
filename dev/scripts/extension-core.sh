#!/bin/bash
# Write docs/extension-api-core.md and tests/fixtures/extension-api-core.json from the meta fixture
# (run ./run.sh meta-fixture first when core steps changed).
#   ./run.sh extension-core
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }

php -r '
define( "ABSPATH", "/" );
require "dev/scripts/lib/extension-core.php";
require "includes/Workflow/Core/Definition/ProCatalog.php";
$core = yaymatik_extension_core( json_decode( file_get_contents( "apps/admin-page/src/test/fixtures/meta.json" ), true ), YayMatik\Workflow\Core\Definition\ProCatalog::STEPS, YayMatik\Workflow\Core\Definition\ProCatalog::FIELDS );
file_put_contents( "tests/fixtures/extension-api-core.json", json_encode( $core, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
file_put_contents( "docs/extension-api-core.md", yaymatik_extension_core_md( $core ) );
' && echo "Wrote docs/extension-api-core.md and tests/fixtures/extension-api-core.json"
