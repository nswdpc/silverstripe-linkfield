<?php

namespace gorriecoe\LinkField\Migration;

use gorriecoe\Link\Models\Link as OldLink;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\LinkField\Models\Link as CoreLink;
use SilverStripe\ORM\ManyManyList;

/**
 * Copies gorriecoe's documented many_many_extraFields 'Sort' value across
 * during migration of a many_many/belongs_many_many relation, onto core
 * Link's own native Sort field.
 *
 * Without this, a migrated Link's Sort is left unset, and
 * SilverStripe\LinkField\Models\Link::onBeforeWrite() falls back to
 * appending it after the current max Sort for its Owner+OwnerRelation -
 * i.e. links end up ordered by migration order, not their original order,
 * silently reshuffling a relation as its rows are migrated one at a time.
 *
 * 'Sort' is gorriecoe's own documented convention (see docs/en/usage.md's
 * many_many example: `many_many_extraFields => ['Sort' => 'Int']`), and
 * this extension assumes that literal column name - matching
 * gorriecoe\LinkField\LinkField::$sort_column's own default. A project
 * using a differently-named sort column (e.g. 'SortOrder') should remove
 * this extension via config and add its own LinkMigrator extension hooking
 * updateMigratedLink() instead, reading the correct column - see
 * docs/en/migration.md.
 *
 * The owner+relation this Link was migrated via isn't passed to
 * updateMigratedLink() (that hook deliberately stays a simple two-argument
 * $newLink/$oldLink signature), so this re-derives it itself via
 * LinkOwnerLocator - by the time this hook runs (inside LinkMigrator::
 * migrate(), after its own sole-ownership check already passed), that is
 * guaranteed to resolve to exactly one owner+relation.
 *
 * Only relevant for many_many/belongs_many_many: a has_one/belongs_to
 * relation carries no Sort value at all (gorriecoe's Link has no native
 * Sort column of its own - see docs/en/migration.md finding 2), so this is
 * a no-op for those.
 *
 * @extends \SilverStripe\Core\Extension<(\gorriecoe\LinkField\Migration\LinkMigrator & static)>
 */
class LinkMigratorSortExtension extends Extension
{
    public function updateMigratedLink(CoreLink $newLink, OldLink $oldLink): void
    {
        $owners = Injector::inst()->get(LinkOwnerLocator::class)->findOwners($oldLink);
        if (count($owners) !== 1) {
            return;
        }

        $owner = $owners[0]['owner'];
        $relation = $owners[0]['relation'];

        $relationType = $owner->getRelationType($relation);
        if (!in_array($relationType, ['many_many', 'belongs_many_many'], true)) {
            return;
        }

        $list = $owner->$relation();
        if (!$list instanceof ManyManyList) {
            return;
        }

        $extraData = $list->getExtraData($relation, $oldLink->ID);
        if (array_key_exists('Sort', $extraData)) {
            $newLink->Sort = $extraData['Sort'];
        }
    }
}
