# Task: Incremental migration path from gorriecoe LinkField to silverstripe/linkfield

## Context

This project is `nswdpc/silverstripe-linkfield` (fork of `gorriecoe/silverstripe-linkfield`, `ss6` branch), with the following installed as vendor modules:

- `gorriecoe/silverstripe-link` — the fork at `nswdpc/silverstripe-link` (`ss6` branch), providing
  `gorriecoe\Link\Models\Link`, a single flat DataObject with a `Type` column (`URL`/`Email`/`Phone`/`File`/`SiteTree`).
- `silverstripe/linkfield` (core CMS 6 module) — provides `SilverStripe\LinkField\Models\Link` (abstract base)
  and per-type subclasses (`ExternalLink`, `EmailLink`, `PhoneLink`, `FileLink`, `SiteTreeLink`), each Versioned,
  owned via a polymorphic multi-relational `has_one` (`Owner`/`OwnerClass`/`OwnerRelation`) that owning
  DataObjects point at using `has_many` dot notation (`Link::class . '.Owner'`).

**Goal:** let existing gorriecoe `LinkField` usages migrate to core `silverstripe/linkfield` incrementally
(one relation/record at a time, no project-wide "flag day"), without ever modifying `silverstripe/linkfield`
itself, and without depending on core's own deprecated `GorriecoeMigrationTask` (it's a one-shot, whole-database,
destructive operation — drops the shared old `Link` table in the same transaction — which is exactly the
all-or-nothing behaviour we're trying to avoid).

## Decided direction

Build our own additive, idempotent, per-record migration entirely inside the module:

1. Add a
   `LinkMigrationExtension` (`SilverStripe\Core\Extension`) applied to `gorriecoe\Link\Models\Link` that adds
   `IsMigrated` (boolean) and a `MigratedLink` has_one. Add a `LinkMigrator` service that, given an old `Link` +
   its owner DataObject + relation name, creates the matching core `Link` subclass, copies field data across,
   sets `Owner`/`OwnerClass`/`OwnerRelation` on it, publishes it if Versioned, and flags the old record
   `IsMigrated = 1` with a pointer to the new record. Never deletes or drops the old table/rows. Add a
   `ModelAdmin` for tracking migration status (list + filter by `IsMigrated`, per-row and bulk "migrate now"
   actions).

   Implemented as: `src/Migration/LinkMigrationExtension.php`, `LinkMigrator.php`, `LinkOwnerLocator.php`,
   `LinkMigrationAdmin.php`, `GridFieldMigrateLinkButton.php`, `GridFieldMigrateAllLinksButton.php`, plus the
   `SelectedStyle` carry-forward extensions (`MigratedLinkStyleExtension.php`, `LinkMigratorStyleExtension.php`.

2. In `src/LinkField.php`, add a migration-aware hook in the constructor
   that checks a config allow-list (`relation` must be explicitly listed — nothing migrates by default) and,
   if the underlying old Link(s) for this owner+relation are already migrated, builds a delegate instance of
   core's actual field/form-factory and defers `Field()`, `handleRequest()`, and `validate()` to it. Leave
   `getHasOneField()`, `getManyField()`, `isOneOrMany()`, sort-column, and link-config logic untouched for the
   non-migrated path. Note: this fork has **one** `LinkField` class that branches on `isOneOrMany()`
   (`has_one`/`belongs_to` → single, `has_many`/`many_many`/`belongs_many_many` → multi) — there is no separate
   `MultiLinkField` class to handle.

   Implemented as: `LinkField::$migrated_relations` config + `getMigratedDelegate()` /
   `isRelationMigrationAllowed()`, checked from `Field()`, `handleRequest()` and `validate()`. Delegation only
   ever triggers for `has_one`/`belongs_to` relations (see "many_many is refused entirely" below) whose
   relation name is allow-listed **and** whose Link is already flagged migrated.

3. A Extension on the owning DataObject side (applied generically, config-gated) that uses
   `get_extra_config()` to add the shadow `has_many` (`{relation}Migrated => SilverStripe\LinkField\Models\Link::class . '.Owner'`)
   needed for the core field to resolve its relation, without requiring any PHP change in consuming projects'
   model classes.

   **Descoped for this pass** (see "many_many is refused entirely" below): this shadow-has_many mechanism was
   designed to let core's field resolve a *replacement* many-relation. Since many_many/belongs_many_many/has_many
   are never eligible for migration (has_one/belongs_to only), there is no "many" migrated-rendering path left
   that needs it. If has_many support is added later for a project that genuinely uses it safely (single owner
   per Link, no sharing), this extension is the natural next piece to build.

   Instead, a **required config-only change was found and documented**: for core's
   `SilverStripe\LinkField\Controllers\LinkFieldController` to recognise a migrated has_one relation at all
   (`getOwnerFromRequest()` matches by checking the owner's *configured* has_one target class against
   `SilverStripe\LinkField\Models\Link`), the owning class's own `has_one` entry for that relation must be
   repointed at core's Link class in the *project's own* config — no PHP change to the model class, just YAML:

   ```yaml
   MyPage:
     has_one:
       Button: SilverStripe\LinkField\Models\Link
   ```

   This is documented on `LinkField::$migrated_relations` and enforced by `LinkMigrator::syncOwnerRelationID()`,
   which deliberately does **not** mirror the migrated Link's ID onto the owner's own `{relation}ID` field until
   this repoint has happened — doing so earlier would silently break the *old* gorriecoe relation (which still
   uses that same ID field) for every not-yet-migrated row on that relation.

## Resolved decisions

All five "unresolved facts" below were investigated directly against the installed vendor source (not assumed).
Findings, and the two items that needed a human decision, are recorded here.

1. **Core's actual field/form-factory entry point.**
   `SilverStripe\LinkField\Models\Link::scaffoldFormFieldForHasOne()` returns
   `SilverStripe\LinkField\Form\LinkField::create($relationName, $fieldTitle)`; `scaffoldFormFieldForHasMany()`
   returns `SilverStripe\LinkField\Form\MultiLinkField::create($relationName, $fieldTitle)`. Both resolve
   owner/relation from `$this->getForm()->getRecord()` / `$this->getName()` at render time — no separate factory
   object is needed, which is exactly what `LinkField::getMigratedDelegate()` constructs directly.

2. **`Link.Sort` provenance in the gorriecoe fork.**
   `Sort` is **not** a db field on `gorriecoe\Link\Models\Link` at all. `docs/en/usage.md` shows it's supplied
   via `many_many_extraFields => ['Sort' => 'Int']` on the *owning* side's `many_many` relation ("Required for
   all many_many relationships"). Plain `has_many` has no such mechanism and gorriecoe's `Link` has no `Sort`
   field, so `GridFieldOrderableRows` (which throws if the field is absent — see
   `symbiote/silverstripe-gridfieldextensions`'s `GridFieldOrderableRows::setSortField()` usage) would only work
   for `has_many` if a project separately added a `Sort` field itself. In practice, gorriecoe's documented
   "many" support is built around `many_many`, not `has_many`. Core's `Link`, by contrast, has `Sort` (Int)
   natively on every row. This finding directly informed the many_many decision below.

3. **`SelectedStyle` field has no equivalent in core's Link model. → Decision: carry forward via extension.**
   Confirmed by reading the full core `Models/Link.php` and every subclass — no equivalent field exists
   anywhere in core. Per decision, `SelectedStyle` is carried forward: `MigratedLinkStyleExtension`
   adds a `SelectedStyle` field to core's `Link` (config-applied, removable per-project if unused), and
   `LinkMigratorStyleExtension` (applied to `LinkMigrator`) copies the value across during migration via the
   `updateMigratedLink` extend hook.

4. **`SiteTree` type handling.**
   The `LinkSiteTree` extension (`gorriecoe\Link\Extensions\LinkSiteTree`) adds `SiteTreeID` has_one + `Anchor`
   Varchar(255) to gorriecoe `Link`, applied via `nswdpc/silverstripe-link`'s own `_config/config.yml`, gated on
   `silverstripe/cms` being installed. `Anchor` is overloaded per its own field description ("Include # at the
   start of your anchor name or, ? at the start of your querystring"): a leading `#` means anchor, a leading `?`
   means querystring. Core's `SiteTreeLink` splits these into separate `PageID`, `Anchor`, and `QueryString`
   fields. Mapping implemented in `LinkMigrator::createMigratedLink()` / `splitSiteTreeAnchor()`:
   `SiteTreeID` → `PageID`, and `Anchor` is parsed apart based on its leading character.

5. **`many_many`/`belongs_many_many` usage is real, not hypothetical. → Decision: refuse/skip entirely.**
   `docs/en/usage.md` documents and shows both a `many_many` example (with the required `Sort` extraField) *and*
   a global `belongs_many_many` example (`gorriecoe\Link\Models\Link: belongs_many_many: MyCustomObject:
   MyCustomObject.Buttons`) — meaning a single old `Link` row can genuinely be shared by more than one owner
   row, true many-to-many, which core's model structurally cannot represent (exactly one polymorphic `Owner`
   per row; its own docblock explicitly says links must never be added via many_many). Per maintainer decision,
   `LinkMigrator::canMigrate()` only ever returns true for `has_one`/`belongs_to` relations; `migrate()` throws a
   `LogicException` for anything else, and the old field/data for those relations is left completely untouched
   indefinitely. `LinkOwnerLocator` (used to find a raw old-Link row's owner for the ModelAdmin's per-row/bulk
   actions) likewise only scans `has_one` config, so a many_many-shared Link correctly shows as unmigratable
   rather than being silently duplicated or dropped.

## Deliverables

- [x] `LinkMigrationExtension` + `LinkMigrator` service + `ModelAdmin` (`LinkMigrationAdmin`), with the
  type/field mapping confirmed against real vendor source (not assumed column names). Housed in
  `src/Migration/` in this repo for now — see "Housing note" above.
- [x] Migration-aware `LinkField` changes, additive only — no behavioural change for any relation not
  explicitly allow-listed in `LinkField::$migrated_relations` config.
- [x] YAML config demonstrating how a project opts a single relation into migration — see
  `LinkField::$migrated_relations` docblock in `src/LinkField.php` for the full two-part example (allow-list +
  the required owner `has_one` repoint).
- [x] PHPUnit tests in `tests/` covering: idempotency, correct field mapping per link type including
  `SiteTree` (both anchor and querystring forms), has_one behaviour, `LinkField` correctly falling back to the
  legacy field when not migrated/not allow-listed/not has_one, many_many refusal, `SelectedStyle` carry-forward,
  the owner `{relation}ID` sync-after-repoint behaviour, and the ModelAdmin's per-row migrate action (including
  refusing an ambiguous/multi-owner row). All 19 tests pass; verified with `vendor/bin/phpunit`, `phpstan`, and
  `php-cs-fixer` against this repo's own CI configs (see `composer.json` scripts).

## Constraints

- Do not modify `silverstripe/linkfield` (core) source. (Not modified — `MigratedLinkStyleExtension` extends
  core's `Link` via config/extension, per standard SilverStripe practice, rather than editing vendor files.)
- Do not use or depend on `SilverStripe\LinkField\Tasks\GorriecoeMigrationTask` or `MigrationTaskTrait`
  (deprecated, and structurally a one-shot/destructive whole-database operation incompatible with our
  incremental goal). Not used.
- Nothing should migrate or change rendering behaviour unless explicitly allow-listed in config — default
  behaviour for unconfigured relations must be byte-for-byte identical to today. Enforced by
  `LinkField::$migrated_relations` defaulting to `[]`, and by `LinkMigrator::syncOwnerRelationID()` never
  touching the owner's `{relation}ID` until the project has repointed that relation's `has_one` config.
