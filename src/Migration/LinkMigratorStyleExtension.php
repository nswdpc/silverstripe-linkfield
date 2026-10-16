<?php

namespace gorriecoe\LinkField\Migration;

use gorriecoe\Link\Models\Link as OldLink;
use SilverStripe\Core\Extension;
use SilverStripe\LinkField\Models\Link as CoreLink;

/**
 * Copies gorriecoe\Link\Models\Link::SelectedStyle across during migration,
 * onto the SelectedStyle field added by MigratedLinkStyleExtension. Applied
 * to LinkMigrator via config, alongside MigratedLinkStyleExtension.
 *
 *
 * @extends \SilverStripe\Core\Extension<(\gorriecoe\LinkField\Migration\LinkMigrator & static)>
 */
class LinkMigratorStyleExtension extends Extension
{
    public function updateMigratedLink(CoreLink $newLink, OldLink $oldLink): void
    {
        if ($newLink->hasField('SelectedStyle')) {
            $newLink->SelectedStyle = $oldLink->SelectedStyle;
        }
    }
}
