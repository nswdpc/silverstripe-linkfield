<?php

namespace gorriecoe\LinkField\Migration;

use SilverStripe\Core\Extension;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\FieldList;

/**
 * Adds a SelectedStyle field to core silverstripe/linkfield Link records, so
 * that gorriecoe\Link\Models\Link::SelectedStyle - which has no equivalent
 * anywhere in core's Link model or subclasses - is preserved across
 * migration rather than being silently dropped.
 *
 * Applied to core's Link (and therefore every subclass, since the field is
 * declared on the base table) via config. The db field and the migration
 * copy (LinkMigratorStyleExtension) are always active whenever this
 * extension is applied - if a project doesn't use SelectedStyle at all, the
 * right way to opt out entirely is to remove this extension via config:
 *
 * SilverStripe\LinkField\Models\Link:
 *   extensions:
 *     MigratedLinkStyleExtension: null
 *
 * LinkMigratorStyleExtension::updateMigratedLink() and this class's own
 * updateCmsFields() both guard on hasField()/dataFieldByName(), so removing
 * the extension this way cleanly removes all SelectedStyle behaviour with
 * no further config needed.
 *
 * Separately, whether the SelectedStyle field is actually shown in the CMS
 * is controlled by a selectedstyle_field_enabled config value read from the
 * OWNER class (SilverStripe\LinkField\Models\Link), defaulting to false
 * (see _config/migration.yml) - the db column and migration copy still
 * happen either way, this only hides/shows the CMS form field itself. A
 * project that wants CMS users to see and edit SelectedStyle should turn
 * this on explicitly:
 *
 * SilverStripe\LinkField\Models\Link:
 *   selectedstyle_field_enabled: true
 *
 *
 * @extends \SilverStripe\Core\Extension<(\SilverStripe\LinkField\Models\Link & static)>
 * @property mixed $SelectedStyle
 */
class MigratedLinkStyleExtension extends Extension
{
    private static array $db = [
        'SelectedStyle' => 'Varchar',
    ];

    public function updateCmsFields(FieldList $fields)
    {
        $selectedStyleField = $fields->dataFieldByName('SelectedStyle');
        if (!$selectedStyleField) {
            return;
        }

        if (!$this->getOwner()->config()->get('selectedstyle_field_enabled')) {
            $fields->removeByName('SelectedStyle');
            return;
        }

        $fields->insertAfter(
            'OpenInNewWindow',
            $selectedStyleField->setTitle(
                _t('linkfield.SELECT_STYLE_FIELD_TITLE', 'Custom link style(s) for CSS')
            )
        );
    }

    public function getSelectedStyle()
    {
        return Convert::raw2htmlatt(trim($this->getOwner()->getField('SelectedStyle') ?? ''));
    }
}
