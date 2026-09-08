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
 * declared on the base table) via config. If a project doesn't use
 * SelectedStyle at all, this extension can be removed via config:
 *
 * SilverStripe\LinkField\Models\Link:
 *   extensions:
 *     MigratedLinkStyleExtension: null
 *
 *
 * @extends Extension<\SilverStripe\LinkField\Models\Link>
 */
class MigratedLinkStyleExtension extends Extension
{
    private static array $db = [
        'SelectedStyle' => 'Varchar',
    ];

    public function updateCmsFields(FieldList $fields)
    {
        $selectedStyleField = $fields->dataFieldByName('SelectedStyle');
        if($selectedStyleField) {
            $fields->insertAfter(
                'OpenInNewWindow',
                $selectedStyleField->setTitle(
                    _t('linkfield.SELECT_STYLE_FIELD_TITLE', 'Custom link style(s) for CSS')
                )
            );
        }

    }

    public function getSelectedStyle()
    {
        return Convert::raw2htmlatt(trim($this->getOwner()->SelectedStyle ?? ''));
    }
}
