# Task: Incremental migration path from gorriecoe Link/LinkField to silverstripe/linkfield

## Context

This project is `nswdpc/silverstripe-linkfield` (fork of `gorriecoe/silverstripe-linkfield`, `ss6` branch), with the following requirements:

- `gorriecoe/silverstripe-link` — the fork at `nswdpc/silverstripe-link` (`ss6` branch), providing
  `gorriecoe\Link\Models\Link`, a single flat DataObject with a `Type` column (`URL`/`Email`/`Phone`/`File`/`SiteTree`).
- `silverstripe/linkfield` (core CMS 6 module) — provides `SilverStripe\LinkField\Models\Link` (abstract base)
  and per-type subclasses (`ExternalLink`, `EmailLink`, `PhoneLink`, `FileLink`, `SiteTreeLink`), each Versioned,
  owned via a polymorphic multi-relational `has_one` (`Owner`/`OwnerClass`/`OwnerRelation`).

**Goal:** let existing gorriecoe `LinkField` usages migrate to core `silverstripe/linkfield` incrementally
(one relation/record at a time, no project-wide "flag day"), without ever modifying `silverstripe/linkfield`
itself, and without depending on core's own deprecated `GorriecoeMigrationTask` (it's a one-shot, whole-database,
destructive operation — drops the shared old `Link` table in the same transaction — which is exactly the
all-or-nothing behaviour we're trying to avoid).

## How to migrate

**The chosen design instead uses two independent, permanently-separate relations.** A project adds a brand new
has_one relation on the owning class (e.g. `CoreButton` alongside an existing `Button`) that has *always*
points at core's `SilverStripe\LinkField\Models\Link`, and a small getter (e.g. `getButton()`) that returns the
migrated Link if one exists, falling back to the old relation otherwise.

The old relation, its column, and its data are never touched. This module's job shrinks to exactly what it should be: locate the old Link's owner, create/map the equivalent core Link, and point the *new* dedicated relation at it — nothing shared, nothing to repoint, nothing that can collide.

This migration path has structural safety properties:

- **No ID collisions.** The new relation's column has never held any other value, so setting it is a plain,
  isolated has_one assignment — regardless of whether other, unrelated relations elsewhere in the project are
  already using core `Link` natively.
- **No atomicity/deploy-timing requirement.** Migrating one record is a single ordinary write. A failure leaves
  the dedicated relation unset — indistinguishable from "not yet migrated" — never a *wrong* value.
- **Versioning safety comes for free.** Setting the new relation's ID only touches Draft (ordinary `write()`
  behaviour on a Versioned owner). Live keeps resolving through the old relation via the fallback getter until
  the record's *next ordinary editorial publish* — no forced/automated publish, no risk of pushing unrelated
  pending Draft changes live.
- **Rollback is free.** Nothing is deleted and nothing shared is mutated, so "undo" is either "don't set the new
  relation" or "make the getter ignore it" — never a data-recovery problem.
- **Cleanup is optional and low-stakes.** Dropping the old relation and renaming the new one can happen per
  module whenever convenient, arbitrarily deferred, with no correctness cost in the meantime — just minor
  schema clutter.

The cost is genuine and worth naming: every call location that currently reads the relation directly
(`$record->Button()`, `$record->ButtonID`, a template's `$Button`) has to be found and switched to the fallback
getter, or migrated records simply won't render via the new path anywhere except where that switch has been
made. That's real, possibly wide-reaching work — but it's *safe* incremental work (each call site conversion is
independently low-risk and reversible), not a data-correctness gamble, and each project/module is free to handle
its own template/theme quirks with bespoke getters rather than this module trying to build one generic mechanism
to cover every usage pattern.

### What this module provides — and what it deliberately doesn't

This module (`src/Migration/`) is scoped to **data migration and bookkeeping only**. It does not attempt to
change rendering, does not add any CMS-field delegation, and does not know or care how a project's templates or
`getCMSFields()` are structured. Concretely, it provides:

- `LinkMigrationExtension` (`SilverStripe\Core\Extension`) applied to `gorriecoe\Link\Models\Link`, adding
  `IsMigrated` (boolean) and a `MigratedLink` has_one, so an old Link row can record whether/where it's been
  migrated to.
- `LinkOwnerLocator`, which scans every `DataObject` subclass's `has_one` *and* `many_many` config for a field
  targeting the old `Link` class (or a subclass of it), to find a raw old-Link row's owner(s) + relation name.
  gorriecoe's Link stores no back-reference to its owner, so this is the only way to find it. It deliberately
  returns *every* owner found, not just the first: a `has_one`-owned Link should only ever resolve to one, but a
  `many_many`-owned Link may genuinely resolve to several - the caller (and `LinkMigrator` itself) uses that
  count to tell "safe to migrate" apart from "genuinely shared, refuse" - see decision 5 below.
- `LinkMigrator`, the core service: given an old Link + its owner + the *old* relation name, creates the
  matching core Link subclass, copies field data across, sets `Owner`/`OwnerClass`/`OwnerRelation` on it,
  publishes it if Versioned, flags the old record `IsMigrated = 1` with a pointer to the new record, and points
  the project's *new*, dedicated relation (per `$relation_map` — see below) at it. Never deletes or drops the
  old table/rows.
- `LinkMigrationAdmin` (a `ModelAdmin`) plus `GridFieldMigrateLinkButton` / `GridFieldMigrateAllLinksButton`, for
  tracking migration status and triggering per-row or bulk migration from the CMS.

It's entirely up to each project or module to decide *when* and *whether* to add the new relation, write its own
fallback getter(s) and template updates, and configure `LinkMigrator::$relation_map` to enable migration for that
relation. Nothing migrates or changes behaviour for any relation not present in `$relation_map`.

## Resolved decisions

All five original "unresolved facts" below were investigated directly against the installed vendor source
(not assumed). Findings, and the items that needed a human decision, are recorded here.

1. **Core's actual field/form-factory entry point.**
   `SilverStripe\LinkField\Models\Link::scaffoldFormFieldForHasOne()` returns
   `SilverStripe\LinkField\Form\LinkField::create($relationName, $fieldTitle)`; `scaffoldFormFieldForHasMany()`
   returns `SilverStripe\LinkField\Form\MultiLinkField::create($relationName, $fieldTitle)`. Both resolve
   owner/relation from `$this->getForm()->getRecord()` / `$this->getName()` at render time. Because the new
   relation is a normal, permanently-correct has_one to core's Link class, a project can simply use these
   classes directly in `getCMSFields()` for the new relation — no delegation or factory wrapping is needed.

2. **`Link.Sort` provenance in the gorriecoe fork.**
   `Sort` is **not** a db field on `gorriecoe\Link\Models\Link` at all. `docs/en/usage.md` shows it's supplied
   via `many_many_extraFields => ['Sort' => 'Int']` on the *owning* side's `many_many` relation ("Required for
   all many_many relationships"). Plain `has_many` has no such mechanism and gorriecoe's `Link` has no `Sort`
   field, so `GridFieldOrderableRows` (which throws if the field is absent) would only work for `has_many` if a
   project separately added a `Sort` field itself. Core's `Link`, by contrast, has `Sort` (Int) natively on
   every row. This finding directly informed the many_many decision below.

3. **`SelectedStyle` field has no equivalent in core's Link model. → Decision: carry forward via extension.**
   Confirmed by reading the full core `Models/Link.php` and every subclass — no equivalent field exists
   anywhere in core. Per decision, `SelectedStyle` is carried forward: `MigratedLinkStyleExtension`
   adds a `SelectedStyle` field to core's `Link` (config-applied, removable per-project if unused), and
   `LinkMigratorStyleExtension` (applied to `LinkMigrator`) copies the value across during migration via the
   `updateMigratedLink` extend hook.

4. **`SiteTree` type handling.**
   The `LinkSiteTree` extension (`gorriecoe\Link\Extensions\LinkSiteTree`) adds `SiteTreeID` has_one + `Anchor`
   Varchar(255) to gorriecoe `Link`, gated on `silverstripe/cms` being installed. `Anchor` is overloaded per its
   own field description ("Include # at the start of your anchor name or, ? at the start of your querystring"):
   a leading `#` means anchor, a leading `?` means querystring. Core's `SiteTreeLink` splits these into separate
   `PageID`, `Anchor`, and `QueryString` fields. Mapping implemented in
   `LinkMigrator::createMigratedLink()` / `splitSiteTreeAnchor()`: `SiteTreeID` → `PageID`, and `Anchor` is
   parsed apart based on its leading character.

5. **`many_many`/`belongs_many_many` usage is real, not hypothetical. → Decision (revised): migrate per-record
   when genuinely single-owned; refuse only when actually shared.**
   `docs/en/usage.md` documents and shows both a `many_many` example (with the required `Sort` extraField) *and*
   a global `belongs_many_many` example — meaning a single old `Link` row *can* be shared by more than one owner
   row, true many-to-many, which core's model structurally cannot represent (exactly one polymorphic `Owner` per
   row; its own docblock explicitly says links must never be added via many_many). The original decision refused
   `many_many`/`belongs_many_many` entirely, by relation type. On review that was broader than the actual
   constraint requires: `many_many` *permits* sharing, it doesn't *require* it, and the real question is whether
   a *specific* Link row is currently attached to more than one owner, not what type its relation declares.
   `LinkOwnerLocator` now scans `many_many` alongside `has_one` (`belongs_many_many` needs no separate scan - it's
   just the reverse accessor of a `many_many` declared on the other class, already found by the forward scan),
   and `LinkMigrator::migrate()` re-verifies sole ownership via the locator immediately before creating a new
   migrated Link - not just relying on the caller to have checked - refusing with a `LogicException` if the Link
   turns out to be attached anywhere else. A genuinely shared Link's old field keeps working unchanged for every
   owner indefinitely; only once every-but-one owner drops the relation would migrating the remaining one become
   possible.

6. **`OwnerRelation` must record the NEW relation name, not the old one.**
   Core's own `Link::Owner()` cross-checks `$owner->getRelationType($this->OwnerRelation) === 'has_one'` and
   `$owner->{$this->OwnerRelation}ID === $this->ID` before returning the owner. If `OwnerRelation` were set to
   the *old* relation name, that check would run against the old relation (which still exists, still targets
   `gorriecoe\Link\Models\Link`, and holds the old record's ID) and always fail — silently breaking permission
   checks and owner back-references on every migrated Link. `LinkMigrator::migrate()` therefore resolves the
   target relation via `$relation_map` and stores *that* name on `OwnerRelation`.

### Worked example: migrating a has_one relation

Say `MyPage` currently has:

```php

use gorriecoe\Link\Models\Link;
use gorriecoe\LinkField\LinkField;

class MyPage extends Page
{
    private static array $has_one = [
        'Button' => \gorriecoe\Link\Models\Link::class,
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->addFieldToTab('Root.Main', LinkField::create('Button', 'Button', $this));
        return $fields;
    }
}
```

**Step 1 — add the new, dedicated relation and getter.** This is a normal, safe, additive code change:

```php
use gorriecoe\Link\Models\Link;
use gorriecoe\LinkField\LinkField;
use SilverStripe\LinkField\Models\Link as CoreLink;
use SilverStripe\LinkField\Form\LinkField as CoreLinkField;

class MyPage extends Page
{
    private static array $has_one = [
        'Button' => Link::class,
        'CoreButton' => CoreLink::class, // you can name this relation anything, update usage if so
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        // Keep the legacy field visible until CoreButton is populated for
        // this record, then core's own field takes over automatically.
        $fields->addFieldToTab(
            'Root.Main',
            $this->CoreButtonID ? CoreLinkField::create('CoreButton', 'Button') : LinkField::create('Button', 'Button', $this)
        );
        return $fields;
    }

    /**
     * Returns the migrated core Link if one exists, falling back to the
     * legacy gorriecoe Link otherwise. Use this (not Button()/ButtonID)
     * anywhere the link is consumed - templates, controllers, other modules.
     */
    public function getButton(): null|CoreLink|Link
    {
        if ($this->CoreButtonID) {
            return $this->CoreButton();
        }
        return $this->getComponent('Button');
    }
}
```

Update the template from `$Button` to `$Button` via the getter (SilverStripe resolves `$Button` to
`getButton()` automatically ahead of the `Button` has_one accessor), or call `$Button` explicitly if the
template already used a different name.

**Step 2 — enable migration for this relation:**

```yaml
gorriecoe\LinkField\Migration\LinkMigrator:
  relation_map:
    MyPage:
      Button: CoreButton
```

**Step 3 — migrate records**, per-row or in bulk, from the `LinkMigrationAdmin` CMS section (`/admin/link-migration`),
at any pace, with no deploy-timing dependency on steps 1-2 having "just happened" — they can already be live for
some time before any records are migrated, or migration can start immediately after deploy.

Nothing above requires raw SQL, transactions, or coordinating with a code deploy: each row's migration is a
single independent, idempotent operation, safe to retry, and safe to run indefinitely alongside records that
haven't been migrated yet.

### Worked example: migrating a many_many relation

> The migration does not support `gorriecoe\Link\Models\Link` records in a has_many relation as this is specifically not recommended in usage documentation. 

Core has no `many_many`/`belongs_many_many` concept for Link at all - its "many" pattern is a `has_many` using
its own `Owner` dot-relation, scaffolded via `Link::scaffoldFormFieldForHasMany()` as `MultiLinkField`. So a
migrated `many_many` relation maps onto that has_many/`MultiLinkField` shape, per-record, exactly as a `has_one`
relation maps onto a `has_one`/`LinkField`. Say `MyPage` currently has:

```php

use gorriecoe\Link\Models\Link;

class MyPage extends Page
{
    private static array $many_many = [
        'Buttons' => \gorriecoe\Link\Models\Link::class,
    ];
    private static array $many_many_extraFields = [
        'Buttons' => ['Sort' => 'Int'],
    ];
}
```

**Step 1 — add the new, dedicated has_many relation and a merging getter:**

```php

use gorriecoe\Link\Models\Link;
use gorriecoe\LinkField\LinkField;
use SilverStripe\LinkField\Models\Link as CoreLink;
use SilverStripe\LinkField\Form\MultiLinkField as CoreMultiLinkField;

class MyPage extends Page
{
    private static array $many_many = [
        'Buttons' => Link::class,
    ];
    private static array $many_many_extraFields = [
        'Buttons' => ['Sort' => 'Int'],
    ];
    private static array $has_many = [
        'CoreButtons' => CoreLink::class . '.Owner',
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        // The new field is always shown, so a CMS author can migrate
        // (via LinkMigrationAdmin) and immediately manage the result here.
        $fields->addFieldToTab(
            'Root.Main',
            CoreMultiLinkField::create(
                'CoreButtons',
                'Buttons'
            )
        );

        // Links migrate one at a time, not all at once for the whole
        // relation - so the legacy field must stay visible (and usable)
        // for as long as any old row on this relation hasn't been migrated
        // yet, not just until the first one has. Once none remain, drop it.
        $hasUnmigratedButtons = $this->Buttons()->filter(['IsMigrated' => 0])->count() > 0;
        if ($hasUnmigratedButtons) {
            $fields->addFieldToTab(
                'Root.Main',
                LinkField::create(
                    'Buttons', 'Buttons', $this
                )
            );
        }

        return $fields;
    }

    /**
     * Migrated old rows are replaced by their core equivalent, in the
     * original sort order; not-yet-migrated old rows keep appearing as-is,
     * since a many_many relation's rows migrate one at a time rather than
     * all together. Use this (not Buttons()/CoreButtons() directly)
     * anywhere the collection is consumed - templates, controllers, other
     * modules.
     * Each record in the ArrayList will either be a Link or CoreLink record
     */
    public function getButtons(): \SilverStripe\ORM\ArrayList
    {
        $result = \SilverStripe\ORM\ArrayList::create();
        $oldLinks = $this->getManyManyComponents('Buttons')->sort(['Sort' => 'ASC']);
        foreach ($oldLinks as $oldLink) {
            $link = $oldLink;
            if($oldLink->IsMigrated == 1 && (($migratedLink = $oldLink->MigratedLink()) && $migratedLink->isInDB())) {
                $link = $migratedLink;
            }
        }
        $result->push($migratedLink);
        return $result;
    }
}
```

Core's `Link` has a native `Sort` field, so `CoreButtons`/`MultiLinkField`'s ordering works out of the box - no
need to reproduce gorriecoe's `many_many_extraFields` `Sort` column for the new relation.

**Step 2 — enable migration for this relation:**

```yaml
gorriecoe\LinkField\Migration\LinkMigrator:
  relation_map:
    MyPage:
      Buttons: CoreButtons
```

**Step 3 — migrate records** from `LinkMigrationAdmin`, exactly as for a has_one relation. Each old Link row is
migrated individually; `LinkMigrator::migrate()` re-checks (via `LinkOwnerLocator`) that the specific row isn't
also attached to some other owner before creating its migrated counterpart, so a Link genuinely shared between,
say, `MyPage` and `MyLandingPage` is refused (throwing, not corrupting) until only one owner remains.

## has_many relations are out of scope, by design

`LinkMigrator::$eligible_relation_types` does not include plain `has_many` (as opposed to a migrated *target*
relation, which is a has_many - see above). Not because it's structurally impossible - a `has_many` Link still
has exactly one owner, just multiple rows per owner - but because gorriecoe's documented "many" usage pattern is
built entirely around `many_many` (see finding 2 above — gorriecoe's `Link` has no native `Sort` field, which
`has_many` would need for ordering via `GridFieldOrderableRows`, without a project adding one itself). A project
genuinely relying on gorriecoe's `Link` via a real `has_many` (with its own `Sort` field) can opt in by adding
`has_many` to `LinkMigrator::$eligible_relation_types` via config and following the many_many pattern above.

## Deliverables

- [x] `LinkMigrationExtension` + `LinkMigrator` service + `ModelAdmin` (`LinkMigrationAdmin`), with the
  type/field mapping confirmed against real vendor source (not assumed column names).
- [x] `LinkMigrator::$relation_map` config, gating migration entirely off by default (`[]`) until a project
  explicitly maps an old relation to a new, dedicated one it has added.
- [x] PHPUnit tests in `tests/Migration/` covering: idempotency (including re-syncing the target relation if
  cleared), correct field mapping per link type including `SiteTree` (both anchor and querystring forms),
  many_many migration when singly-owned, many_many refusal when genuinely shared (and `LinkOwnerLocator`
  correctly finding both owners in that case), refusal when a Link isn't actually attached to the given
  owner+relation at all, `SelectedStyle` carry-forward, correct `OwnerRelation` naming (and that core's own
  `Link::Owner()` resolves correctly as a result), refusing migration with no/misconfigured `$relation_map`
  entry, and the ModelAdmin's per-row migrate action (including refusing an ambiguous/multi-owner row and a
  not-yet-enabled relation). All 20 tests pass; verified with `vendor/bin/phpunit`, `phpstan`, and
  `php-cs-fixer` against this repo's own CI configs (see `composer.json` scripts).
- [x] Worked examples above for both a has_one and a many_many relation, plus an explicit account of why plain
  has_many (as an *old* relation type) is out of scope by default.

## Constraints

- Do not modify `silverstripe/linkfield` (core) source. (Not modified — `MigratedLinkStyleExtension` extends
  core's `Link` via config/extension, per standard SilverStripe practice, rather than editing vendor files.)
- Do not use or depend on `SilverStripe\LinkField\Tasks\GorriecoeMigrationTask` or `MigrationTaskTrait`
  (deprecated, and structurally a one-shot/destructive whole-database operation incompatible with our
  incremental goal). Not used.
- Nothing should migrate unless a relation is explicitly present in `LinkMigrator::$relation_map` - default
  behaviour is byte-for-byte identical to today for anything not mapped, and this module never touches the old
  relation's own column/data regardless of migration state.
