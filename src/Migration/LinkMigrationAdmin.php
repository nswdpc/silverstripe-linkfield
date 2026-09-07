<?php

namespace gorriecoe\LinkField\Migration;

use gorriecoe\Link\Models\Link;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDataColumns;

/**
 * Lists old gorriecoe Link records, filterable by migration status, with
 * per-row and bulk "migrate now" actions.
 *
 */
class LinkMigrationAdmin extends ModelAdmin
{
    private static string $url_segment = 'link-migration';

    private static string $menu_title = 'Link migration';

    private static string $menu_icon_class = 'font-icon-link';

    private static array $managed_models = [
        Link::class,
    ];

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);

        $gridField = $form->Fields()->dataFieldByName($this->sanitiseClassName(Link::class));
        if (!$gridField instanceof GridField) {
            return $form;
        }

        $config = $gridField->getConfig();

        $columns = $config->getComponentByType(GridFieldDataColumns::class);
        if ($columns) {
            $displayFields = $columns->getDisplayFields($gridField);
            $displayFields['IsMigrated'] = _t(__CLASS__ . '.MIGRATED_COLUMN', 'Migrated?');
            $columns->setDisplayFields($displayFields);
        }

        $config->addComponent(GridFieldMigrateLinkButton::create());
        $config->addComponent(GridFieldMigrateAllLinksButton::create());

        return $form;
    }
}
