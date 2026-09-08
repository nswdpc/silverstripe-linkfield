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
 * Migrates a single gorriecoe\Link\Models\Link record to the equivalent core
 * silverstripe/linkfield Link subclass, and points a project's own dedicated
 * "migrated" relation at it - a has_one for a migrated has_one/belongs_to
 * relation, or a has_many for a migrated many_many/belongs_many_many one.
 *
 * This class never touches the OLD relation a record is being migrated from
 * - it is never repointed, and for a has_one/belongs_to old relation its
 * "{$relation}ID" column is never written to. The recommended architecture
 * (see docs/en/migration.md) is for a project to add a brand new, separate
 * relation (e.g. "CoreButton" alongside an existing "Button") that has
 * always pointed at core's Link class, plus a custom getter (e.g.
 * getButton()) that returns the migrated Link if present, falling back to
 * the old relation otherwise. $relation_map tells this class which new
 * relation corresponds to which old one, per owner class - migration is
 * refused for any relation with no entry here, so nothing happens until a
 * project explicitly opts a relation in.
 *
 * Because the new relation is always a dedicated field that has never been
 * used for anything else, populating it is a plain, ordinary assignment:
 * there is no shared column, so no ID collision or resync ordering concern
 * of the kind that would exist if the old relation were repointed in place
 * instead.
 *
 * Additive and idempotent: never deletes or mutates the old Link row beyond
 * flagging it as migrated, and migrating an already-migrated record is a
 * no-op that returns the existing migrated record.
 *
 * many_many and belongs_many_many relations are eligible for migration too,
 * but only when the specific Link row being migrated is not actually shared:
 * gorriecoe's Link genuinely supports a Link row being shared by more than
 * one owner via those relation types (see docs/en/usage.md in
 * nswdpc/silverstripe-linkfield), which core's Link model cannot represent -
 * it has exactly one polymorphic Owner per row, and its own docblock states
 * links must never be added via many_many. Rather than silently duplicating
 * or dropping data for a genuinely shared row, migrate() re-verifies sole
 * ownership via LinkOwnerLocator immediately before creating a new migrated
 * Link, and refuses (throwing, never silently corrupting) if the Link is
 * attached anywhere else too - this makes migrate() safe to call directly,
 * not just via callers (like the GridField actions) that happen to have
 * already checked ownership themselves. A genuinely shared Link's old field
 * keeps working unchanged for every owner indefinitely.
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
     * Relation types eligible for migration. many_many/belongs_many_many are
     * only actually migrated when the specific Link row turns out to be
     * solely owned - see migrate() and the class docblock. has_many is
     * deliberately excluded by default: gorriecoe's documented "many" usage
     * pattern is built around many_many (see docs/en/migration.md finding 2),
     * and a project relying on a genuine has_many can opt in via config.
     */
    private static array $eligible_relation_types = ['has_one', 'belongs_to', 'many_many', 'belongs_many_many'];

    /**
     * Maps each owning class's OLD relation name to a NEW, dedicated
     * relation name that the project has added, pointing at core's Link
     * class - a has_one for a migrated has_one/belongs_to, or a has_many
     * (using core's own "SilverStripe\LinkField\Models\Link.Owner" dot
     * notation, matching Link::scaffoldFormFieldForHasMany()) for a migrated
     * many_many/belongs_many_many. A relation with no entry here - or whose
     * target relation isn't actually configured as a has_one/has_many to (a
     * subclass of) core's Link class - is not eligible for migration. See
     * docs/en/migration.md for full worked examples.
     *
     * gorriecoe\LinkField\Migration\LinkMigrator:
     *   relation_map:
     *     MyPage:
     *       Button: CoreButton
     *       Buttons: CoreButtons
     */
    private static array $relation_map = [];

    /**
     * Whether the given owner+relation is eligible for migration in
     * principle: the old relation must be an eligible type, and the project
     * must have mapped it to a new relation (via $relation_map) that is
     * itself correctly configured as a has_one/has_many to core's Link
     * class. This does NOT check whether a specific Link row is solely
     * owned - for many_many/belongs_many_many that's re-verified inside
     * migrate() itself, immediately before a new Link would be created,
     * since it depends on the Link being migrated, not just the owner+
     * relation shape.
     */
    public function canMigrate(DataObject $owner, string $relation): bool
    {
        if (!$owner->exists()) {
            return false;
        }

        $relationType = $owner->getRelationType($relation);
        if (!in_array($relationType, (array) static::config()->get('eligible_relation_types'), true)) {
            return false;
        }

        $targetRelation = $this->getTargetRelation($owner, $relation);
        if (!$targetRelation) {
            return false;
        }

        $targetClass = $this->getConfiguredRelationClass($owner, $targetRelation);
        return $targetClass !== null && is_a($targetClass, CoreLink::class, true);
    }

    /**
     * Migrate $link (owned by $owner via the OLD $relation) to a core Link,
     * and point the project's dedicated NEW relation (per $relation_map) at
     * it. Returns the migrated core Link, creating it if necessary, or
     * returning the existing one if $link was already migrated.
     */
    public function migrate(OldLink $link, DataObject $owner, string $relation): CoreLink
    {
        if (!$link->exists()) {
            throw new LogicException('Cannot migrate a Link that has not been saved');
        }

        if (!$this->canMigrate($owner, $relation)) {
            throw new LogicException(sprintf(
                'Relation "%s" on "%s" is not eligible for migration. Either it is not a has_one/belongs_to/'
                    . 'many_many/belongs_many_many relation, or no target relation has been configured for it '
                    . 'via LinkMigrator::$relation_map - see docs/en/migration.md.',
                $relation,
                get_class($owner)
            ));
        }

        $targetRelation = $this->getTargetRelation($owner, $relation);

        $newLink = $this->getExistingMigration($link);
        if (!$newLink) {
            // Re-verify sole ownership immediately before creating a new
            // migrated Link, rather than trusting the caller. This matters
            // most for many_many/belongs_many_many, which permit (but don't
            // require) a Link being shared by more than one owner: without
            // this check, migrating a genuinely shared Link via one owner
            // would flag it IsMigrated for every owner, silently orphaning
            // the relation for every owner beyond this one (a has_many
            // target relation has no owner-side column to mirror a shared
            // Link onto - the migrated Link can only ever have one Owner).
            if (!$this->isSoleOwner($link, $owner, $relation)) {
                throw new LogicException(sprintf(
                    'Refusing to migrate Link #%d via "%s" on "%s": it is not solely owned by this '
                        . 'owner+relation. It may be shared with another owner (only possible for many_many/'
                        . 'belongs_many_many, which are never migrated once genuinely shared), or it may not '
                        . 'actually be currently attached here at all - see docs/en/migration.md.',
                    $link->ID,
                    $relation,
                    get_class($owner)
                ));
            }

            $newLink = $this->createMigratedLink($link);
            $newLink->OwnerID = $owner->ID;
            $newLink->OwnerClass = get_class($owner);
            $newLink->OwnerRelation = $targetRelation;
            $newLink->write();

            if ($newLink->hasExtension(Versioned::class)) {
                $newLink->publishSingle();
            }

            $link->IsMigrated = true;
            $link->MigratedLinkID = $newLink->ID;
            $link->write();
        }

        $this->setOwnerRelationID($owner, $targetRelation, $newLink);

        return $newLink;
    }

    /**
     * The new relation name that $relation on $owner's class maps to, per
     * $relation_map, or null if no mapping is configured.
     */
    protected function getTargetRelation(DataObject $owner, string $relation): ?string
    {
        foreach ((array) static::config()->get('relation_map') as $class => $map) {
            if (!is_a($owner, $class)) {
                continue;
            }

            if (array_key_exists($relation, (array) $map)) {
                return (string) $map[$relation];
            }
        }

        return null;
    }

    /**
     * If $targetRelation is a has_one, points its "{$targetRelation}ID"
     * field at the newly migrated core Link - a plain, ordinary assignment,
     * since (unlike the old gorriecoe relation this migration is based on)
     * $targetRelation is a separate field that has never been used for
     * anything else. If $targetRelation is a has_many instead, there is no
     * owner-side column to set at all - the relation is fully carried by
     * the new Link's own OwnerID/OwnerClass/OwnerRelation (already set in
     * migrate()), so this is a no-op in that case.
     */
    protected function setOwnerRelationID(DataObject $owner, string $targetRelation, CoreLink $newLink): void
    {
        $relationIDField = "{$targetRelation}ID";
        if (!$owner->hasField($relationIDField)) {
            return;
        }

        if ((int) $owner->$relationIDField !== $newLink->ID) {
            $owner->$relationIDField = $newLink->ID;
            $owner->write();
        }
    }

    /**
     * The class currently configured for $owner's $relation, checking both
     * has_one and has_many, or null if $relation is neither on $owner.
     * DataObject::hasMany()'s default $classOnly=true already strips the
     * "TargetClass.RelationName" dot notation down to the plain class name.
     */
    protected function getConfiguredRelationClass(DataObject $owner, string $relation): ?string
    {
        $hasOne = $owner->hasOne();
        if (array_key_exists($relation, $hasOne)) {
            $target = $hasOne[$relation];
            return is_array($target) ? ($target['class'] ?? null) : $target;
        }

        $hasMany = $owner->hasMany();
        if (array_key_exists($relation, $hasMany)) {
            return $hasMany[$relation] ?: null;
        }

        return null;
    }

    /**
     * Whether $link is currently attached to $owner via exactly $relation
     * and nowhere else at all, re-verified via LinkOwnerLocator rather than
     * trusted from the caller. See migrate() for why this matters.
     */
    protected function isSoleOwner(OldLink $link, DataObject $owner, string $relation): bool
    {
        $owners = Injector::inst()->get(LinkOwnerLocator::class)->findOwners($link);
        if (count($owners) !== 1) {
            return false;
        }

        $only = $owners[0];
        return $only['relation'] === $relation
            && get_class($only['owner']) === get_class($owner)
            && (int) $only['owner']->ID === (int) $owner->ID;
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
