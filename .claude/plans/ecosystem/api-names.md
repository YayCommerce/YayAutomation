# Plan: Extension API names, settled before any extension ships

## Goal
Every public name of the Extension API says one thing, before the scaffolds are written and copied to six repos. After this, a rename costs a MAJOR version.

## Approach
No extension has been released, so the PHP surface can change. YayMatik 1.0 is released, so **nothing it has stored changes**: step ids, config keys, output keys, data item and field keys, fact keys, template ids, template category values, option names, tables and columns stay as they are. A saved step is `{ block, config }`; every rename below is a key of a PHP declaration array, a class or a method, never a stored value.

Renames (D26), in `includes/Api`, `includes/Workflow/Extension` (`Declaration`, `RunAdapter` and the classes that read declarations), `dev/example-extension`, `tests/Unit/Extension`, `tests/Unit/Api` (`SurfaceTest`), `docs/extension-api.md` and `docs/extension-api-core.md`, plus anything in the admin app that reads these keys from the catalog:

| Before | After | Why |
|---|---|---|
| interface `Run`, its `$run` argument | interface `Step`, `$step` | `done()` / `skip()` / `fail()` finish the step; "run" keeps meaning a workflow run |
| action key `run` (the callable) | `handle` | one word per thing, with `Step` |
| trigger / action key `fields` (the form) | `settings` | `fields` keeps one meaning: data fields (§6) |
| action key `requires` | `needs` | pairs with the trigger's `provides`; `requires_api` (a version) keeps its meaning |
| trigger key `sample` | `sample_facts` | names what it previews, as `sample_outputs` does |
| template key `name` | `label` | the key for "the text people see" everywhere |
| template key `category` | `library_category` | the template library's list, apart from `$m->category()` step categories |
| `Registrar::supports()` and its feature list | removed | the API is the same in Free and Pro; additions are MINOR versions, so `requires_api` (whole module) and `api_version()` (an optional part) answer it |

Kept on purpose: `module()` / `Module` (a module is what core, Pro and an extension each register), `yaymatik_register`, `yaymatik_fire`, `Registrar`, `CustomerView`, `CartView`, `Api`, the action key `editor`.

Docs in `extension-api.md`, written as what exists:
- §1: the edition rule. The API is the same in Free and Pro; an extension's own license is its own business (it registers what it covers); core gates its own Pro steps (`tier`), and a template that uses one shows only with Pro.
- §4.3 `Step::is_test()` (D27): true whenever the step must do nothing real. Today that is Send test; the test run and dry run of V11.5 will set it too. `is_preview()`: sample or made-up data. An action with a side effect (award, role, any write) skips it when `is_test()`.
- §3: `Api::VERSION` stays `1.0.0`; §16 Changelog records the renames as made before any extension shipped.

## Checkpoints
1. Renames, docs, example, tests — Risk: 2 — `pnpm test:php` passes with `SurfaceTest` and the core-steps snapshot updated to the new names (the core-steps snapshot of stored ids and config keys does not change); `./run.sh check-extension` passes with the example on the new names; `pnpm build` passes; a search for each old name in `includes/`, `dev/`, `docs/extension-api*.md` and the admin app finds none outside the changelog.

## Out of Scope
- Any stored id, key, option or table (see Approach).
- Internal names that extensions never see (`ModuleRegistry`, `ExtensionModule`), unless a renamed key passes through them.
- The scaffolds (next row, written on these names).

## Decisions
Confirmed: D26 (N1–N3, N5, N7–N9, N11), D27 (N4).
- `Api::VERSION` stays `1.0.0`, with a changelog entry — confirmed (auto)
- Catalog's template shape (`name`, `category`, TS `WorkflowTemplate`) stays: it is the admin app's type, shared with core templates; only the declaration keys change — confirmed (auto)
- Internal `RunAdapter` and core `Step::fields()` / `requires()` keep their names (extensions never see them) — confirmed (auto)
- Refusal messages for a step's form say "Setting" (`Setting "role" needs …`), so "field" means a data field only — confirmed (auto)
- The example's "Add reward points" skips when `is_test()`, as §4.3 asks of a side-effect action — confirmed (auto)

## Meta
- Target branch: feature/ecosystem-api-names
- Base branch: feature/lane-a
- Unattended: yes
