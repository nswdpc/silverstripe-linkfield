<?php

namespace gorriecoe\LinkField\Migration;

use gorriecoe\Link\Models\Link as OldLink;
use LogicException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\LinkField\Models\EmailLink;
use SilverStripe\LinkField\Models\ExternalLink;
use SilverStripe\LinkField\Models\FileLink;
use SilverStripe\LinkField\Models\Link as CoreLink;
use SilverStripe\LinkField\Models\PhoneLink;
use SilverStripe\LinkField\Models\SiteTreeLink;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Migrates a single gorriecoe\Link\Models\Link record - plus its owning
 * has_one relation - to the equivalent core silverstripe/linkfield Link
 * subclass. Additive and idempotent: never deletes or mutates the old Link
 * row beyond flagging it as migrated, and migrating an already-migrated
 * record is a no-op that returns the existing migrated record.
 *
 * many_many and belongs_many_many relations to Link are never eligible for
 * migration. gorriecoe's Link genuinely supports a Link row being shared by
 * more than one owner via those relation types (see docs/en/usage.md in
 * nswdpc/silverstripe-linkfield), which core's Link model cannot represent -
 * it has exactly one polymorphic Owner per row, and its own docblock states
 * links must never be added via many_many. Rather than silently duplicating
 * or dropping data, migration for those relations is refused entirely; the
 * old field keeps working unchanged for them indefinitely.
 *
 */
class LinkMigrator
{
    use Injectable;
    use Configurable;
    use Extensible;

    /**
     * Maps gorriecoe Link::Type values to the core Link subclass that
     * represents the same kind of link.
     */
    private static array $type_map = [
        'URL' => ExternalLink::class,
        'Email' => EmailLink::class,
        'Phone' => PhoneLink::class,
        'File' => FileLink::class,
        'SiteTree' => SiteTreeLink::class,
    ];

    /**
     * Relation types eligible for migration. Deliberately excludes
     * has_many/many_many/belongs_many_many - see class docblock.
     */
    private static array $eligible_relation_types = ['has_one', 'belongs_to'];

    /**
     * Whether the given owner+relation is eligible for migration at all,
     * independent of whether it has actually been allow-listed in config
     * (that gating lives in LinkField, since it also controls rendering).
     */
    public function canMigrate(DataObject $owner, string $relation): bool
    {
        if (!$owner->exists()) {
            return false;
        }

        $relationType = $owner->getRelationType($relation);
        return in_array($relationType, (array) static::config()->get('eligible_relation_types'), true);
    }

    /**
     * Migrate $link (owned by $owner via $relation) to a core Link.
     * Returns the migrated core Link, creating it if necessary, or
     * returning the existing one if $link was already migrated.
     */
    public function migrate(OldLink $link, DataObject $owner, string $relation): CoreLink
    {
        if (!$link->exists()) {
            throw new LogicException('Cannot migrate a Link that has not been saved');
        }

        if (!$this->canMigrate($owner, $relation)) {
            throw new LogicException(sprintf(
                'Relation "%s" on "%s" is not eligible for migration (relation type "%s"). '
                    . 'many_many/belongs_many_many links are never migrated - see docs/en/migration.md.',
                $relation,
                get_class($owner),
                $owner->getRelationType($relation)
            ));
        }

        $newLink = $this->getExistingMigration($link);
        if (!$newLink) {
            $newLink = $this->createMigratedLink($link);
            $newLink->OwnerID = $owner->ID;
            $newLink->OwnerClass = get_class($owner);
            $newLink->OwnerRelation = $relation;
            $newLink->write();

            if ($newLink->hasExtension(Versioned::class)) {
                $newLink->publishSingle();
            }

            $link->IsMigrated = true;
            $link->MigratedLinkID = $newLink->ID;
            $link->write();
        }

        $this->syncOwnerRelationID($owner, $relation, $newLink);

        return $newLink;
    }

    /**
     * Mirror core's own has_one bookkeeping (see
     * SilverStripe\LinkField\Controllers\LinkFieldController::save()): for a
     * has_one relation, the owner's own "{$relation}ID" field is normally
     * kept pointing at the Link record, in addition to the
     * OwnerID/OwnerClass/OwnerRelation stored on the Link itself.
     *
     * Here that's only safe to do once the owner's has_one config for this
     * relation has actually been repointed at core's Link class in project
     * config (see LinkField::$migrated_relations and docs/en/migration.md).
     * Until then, "{$relation}ID" is still load-bearing for the *old*
     * gorriecoe relation - every not-yet-migrated Link on this same
     * relation is found via that exact field - so overwriting it here would
     * silently break the old field for every other row, violating the
     * "unconfigured/not-yet-migrated behaviour is unchanged" constraint.
     * This check re-runs even for an already-migrated $link, so that a
     * relation repointed *after* some of its rows were migrated still gets
     * its owners' IDs synced up on the next migrate() call for that row.
     */
    protected function syncOwnerRelationID(DataObject $owner, string $relation, CoreLink $newLink): void
    {
        $relationIDField = "{$relation}ID";
        if (!$owner->hasField($relationIDField)) {
            return;
        }

        $configuredClass = $this->getConfiguredRelationClass($owner, $relation);
        if (!$configuredClass || !is_a($configuredClass, CoreLink::class, true)) {
            return;
        }

        if ((int) $owner->$relationIDField !== $newLink->ID) {
            $owner->$relationIDField = $newLink->ID;
            $owner->write();
        }
    }

    /**
     * The class currently configured for $owner's $relation has_one, or
     * null if $relation isn't a has_one on $owner at all.
     */
    protected function getConfiguredRelationClass(DataObject $owner, string $relation): ?string
    {
        $hasOne = $owner->hasOne();
        if (!array_key_exists($relation, $hasOne)) {
            return null;
        }

        $target = $hasOne[$relation];
        return is_array($target) ? ($target['class'] ?? null) : $target;
    }

    /**
     * If $link has already been migrated, return the migrated record
     * (provided it still exists) - otherwise null.
     */
    protected function getExistingMigration(OldLink $link): ?CoreLink
    {
        if (!$link->IsMigrated || !$link->MigratedLinkID) {
            return null;
        }

        $existing = $link->MigratedLink();
        if ($existing && $existing->exists()) {
            return $existing;
        }

        return null;
    }

    protected function createMigratedLink(OldLink $link): CoreLink
    {
        $typeMap = (array) static::config()->get('type_map');
        $type = $link->Type;
        if (!isset($typeMap[$type])) {
            throw new LogicException(sprintf('No migration mapping defined for link type "%s"', $type));
        }

        $className = $typeMap[$type];
        /** @var CoreLink $newLink */
        $newLink = Injector::inst()->create($className);

        $newLink->LinkText = $link->Title;
        $newLink->OpenInNew = $link->OpenInNewWindow;

        switch ($type) {
            case 'URL':
                $newLink->ExternalUrl = $link->URL;
                break;
            case 'Email':
                $newLink->Email = $link->Email;
                break;
            case 'Phone':
                $newLink->Phone = $link->Phone;
                break;
            case 'File':
                $newLink->FileID = $link->FileID;
                break;
            case 'SiteTree':
                $newLink->PageID = $link->SiteTreeID;
                [$anchor, $queryString] = $this->splitSiteTreeAnchor((string) $link->Anchor);
                $newLink->Anchor = $anchor;
                $newLink->QueryString = $queryString;
                break;
        }

        $this->extend('updateMigratedLink', $newLink, $link);

        return $newLink;
    }

    /**
     * gorriecoe's Link::Anchor field (added by the LinkSiteTree extension) is
     * overloaded: per its own field description ("Include # at the start of
     * your anchor name or, ? at the start of your querystring"), a leading
     * "#" denotes an anchor and a leading "?" denotes a querystring. Core
     * splits these into separate Anchor and QueryString fields.
     *
     * @return array{0: string, 1: string} [$anchor, $queryString]
     */
    protected function splitSiteTreeAnchor(string $value): array
    {
        if ($value === '') {
            return ['', ''];
        }
        if (str_starts_with($value, '#')) {
            return [ltrim($value, '#'), ''];
        }
        if (str_starts_with($value, '?')) {
            return ['', ltrim($value, '?')];
        }

        // Unrecognised prefix - preserve the value as an anchor rather than
        // silently dropping it.
        return [$value, ''];
    }
}
