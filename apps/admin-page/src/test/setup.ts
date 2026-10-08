import meta from './fixtures/meta.json';

// Modules read the PHP meta on import (e.g. `routes/workflows/steps/core/pending.ts`).
// Regenerate the fixture with `./run.sh meta-fixture` when the PHP side changes.
window.yayAutomationMeta = meta as unknown as Window['yayAutomationMeta'];
