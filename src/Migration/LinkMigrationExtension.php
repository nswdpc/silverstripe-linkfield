<?php

namespace gorriecoe\LinkField\Migration;

use SilverStripe\Core\Extension;
use SilverStripe\LinkField\Models\Link as CoreLink;

/**
 * Applied to gorriecoe\Link\Models\Link. Tracks whether an old Link record has
 * been migrated to a core silverstripe/linkfield Link, and if so, which one.
 *
 * This extension is purely additive: it never deletes or mutates existing
 * columns on the old Link table, so the old module keeps working exactly as
 * before for any relation that hasn't been migrated.
 *
 *
 * @extends Extension<\gorriecoe\Link\Models\Link>
 */
class LinkMigrationExtension extends Extension
{
    private static array $db = [
        'IsMigrated' => 'Boolean',
    ];

    private static array $has_one = [
        'MigratedLink' => CoreLink::class,
    ];

    private static array $summary_fields = [
        'IsMigrated' => 'Migrated?',
    ];
}
