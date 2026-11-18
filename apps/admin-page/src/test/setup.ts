import meta from '@test-meta';

// Modules read the PHP meta on import (e.g. `routes/workflows/steps/core/data.ts`).
// Regenerate the fixtures (meta.json for Pro, meta-lite.json) with `./run.sh meta-fixture` when the PHP side changes.
window.yayMatikMeta = meta as unknown as Window['yayMatikMeta'];

// Pro runs licensed in the tests (PHPUnit sets the same, see tests/bootstrap.php).
window.yayMatikPro = { licensed: true };
