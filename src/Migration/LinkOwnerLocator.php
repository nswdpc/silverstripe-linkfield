<?php

namespace gorriecoe\LinkField\Migration;

use gorriecoe\Link\Models\Link as OldLink;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;

/**
 * Locates the owning DataObject(s) + has_one relation name for an old
 * gorriecoe Link record.
 *
 * gorriecoe's Link model stores no back-reference to its owner(s) - unlike
 * core's Link, which stores OwnerID/OwnerClass/OwnerRelation on every row -
 * so a has_one owner can only be found by scanning every DataObject
 * subclass's has_one configuration for a field targeting Link (or a
 * subclass of it via an extension), then querying for a matching
 * "{$relation}ID" value.
 *
 * This intentionally only looks at has_one relations. many_many and
 * belongs_many_many are never eligible for migration (see LinkMigrator and
 * docs/en/migration.md), so there is no reverse lookup for them here.
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
            $hasOne = (array) DataObject::singleton($class)->config()->get('has_one');
            foreach ($hasOne as $relation => $target) {
                $targetClass = is_array($target) ? ($target['class'] ?? null) : $target;
                if (!$targetClass || !is_a($targetClass, OldLink::class, true)) {
                    continue;
                }

                $idField = "{$relation}ID";
                $owners = DataObject::get($class)->filter([$idField => $link->ID]);
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
        }

        return $found;
    }

    /**
     * @return string[]
     */
    protected function getCandidateClasses(): array
    {
        return ClassInfo::subclassesFor(DataObject::class, false);
    }
}
