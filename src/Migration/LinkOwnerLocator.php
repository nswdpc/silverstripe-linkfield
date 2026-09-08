<?php

namespace gorriecoe\LinkField\Migration;

use gorriecoe\Link\Models\Link as OldLink;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;

/**
 * Locates the owning DataObject(s) + relation name for an old gorriecoe
 * Link record, across both has_one and many_many/belongs_many_many
 * relations.
 *
 * gorriecoe's Link model stores no back-reference to its owner(s) - unlike
 * core's Link, which stores OwnerID/OwnerClass/OwnerRelation on every row -
 * so an owner can only be found by scanning every DataObject subclass's
 * has_one and many_many configuration for a field targeting Link (or a
 * subclass of it via an extension), then querying for a matching row.
 * belongs_many_many needs no separate scan: it's just the auto-generated
 * reverse accessor of a many_many declared on the *other* class, which the
 * many_many scan already discovers directly.
 *
 * The result of this scan is what LinkMigrator relies on to tell a genuinely
 * single-owner relation (safe to migrate, has_one or many_many alike) apart
 * from a Link that's actually shared by more than one owner (which core's
 * single-Owner Link model cannot represent, and is never migrated) - see
 * docs/en/migration.md.
 *
 */
class LinkOwnerLocator
{
    /**
     * @return array<int, array{owner: DataObject, relation: string}>
     */
    public function findOwners(OldLink $link): array
    {
        $found = [];
        $seen = [];

        if (!$link->exists()) {
            return $found;
        }

        foreach ($this->getCandidateClasses() as $class) {
            $this->findHasOneOwners($link, $class, $found, $seen);
            $this->findManyManyOwners($link, $class, $found, $seen);
        }

        return $found;
    }

    /**
     * @param array<int, array{owner: DataObject, relation: string}> $found
     * @param array<string, bool> $seen
     */
    protected function findHasOneOwners(OldLink $link, string $class, array &$found, array &$seen): void
    {
        $hasOne = (array) DataObject::singleton($class)->config()->get('has_one');
        foreach ($hasOne as $relation => $target) {
            $targetClass = is_array($target) ? ($target['class'] ?? null) : $target;
            if (!$targetClass || !is_a($targetClass, OldLink::class, true)) {
                continue;
            }

            $idField = "{$relation}ID";
            $owners = DataObject::get($class)->filter([$idField => $link->ID]);
            $this->addFound($owners, $relation, $found, $seen);
        }
    }

    /**
     * @param array<int, array{owner: DataObject, relation: string}> $found
     * @param array<string, bool> $seen
     */
    protected function findManyManyOwners(OldLink $link, string $class, array &$found, array &$seen): void
    {
        $manyMany = (array) DataObject::singleton($class)->config()->get('many_many');
        foreach ($manyMany as $relation => $target) {
            // many_many "through" relations are declared as an array rather
            // than a plain target class string - not used by gorriecoe's
            // documented Link usage, so deliberately not matched here.
            if (!is_string($target) || !is_a($target, OldLink::class, true)) {
                continue;
            }

            // Join through the many_many relation to find every owner
            // currently attached to this specific Link row - a real SQL
            // join (DataQuery::applyRelation() supports many_many), not an
            // iterate-every-owner scan.
            $owners = DataObject::get($class)->filter([$relation . '.ID' => $link->ID]);
            $this->addFound($owners, $relation, $found, $seen);
        }
    }

    /**
     * @param iterable<DataObject> $owners
     * @param array<int, array{owner: DataObject, relation: string}> $found
     * @param array<string, bool> $seen
     */
    protected function addFound(iterable $owners, string $relation, array &$found, array &$seen): void
    {
        foreach ($owners as $owner) {
            // Querying every candidate class (not just base data classes) can
            // revisit the same actual row via more than one ancestor class -
            // dedupe on the owner's real class + ID + relation.
            $key = get_class($owner) . '|' . $owner->ID . '|' . $relation;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $found[] = ['owner' => $owner, 'relation' => $relation];
        }
    }

    /**
     * @return string[]
     */
    protected function getCandidateClasses(): array
    {
        return ClassInfo::subclassesFor(DataObject::class, false);
    }
}
