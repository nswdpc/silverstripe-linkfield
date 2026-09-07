<?php

namespace gorriecoe\LinkField\Migration;

use gorriecoe\Link\Models\Link as OldLink;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;

/**
 * Adds a bulk "Migrate all eligible links" button to LinkMigrationAdmin's
 * GridField. Migrates every currently-listed, not-yet-migrated Link that has
 * exactly one locatable has_one owner; skips (does not error on) any row
 * that's ambiguous, ownerless, or not permitted, so one bad row can't block
 * the rest of the batch.
 *
 */
class GridFieldMigrateAllLinksButton extends AbstractGridFieldComponent implements GridField_HTMLProvider, GridField_ActionProvider
{
    protected string $targetFragment;

    public function __construct(string $targetFragment = 'buttons-before-right')
    {
        $this->targetFragment = $targetFragment;
    }

    public function getHTMLFragments($gridField)
    {
        $button = GridField_FormAction::create(
            $gridField,
            'migratealllinks',
            _t(__CLASS__ . '.MIGRATE_ALL', 'Migrate all eligible links'),
            'migratealllinks',
            []
        );
        $button->addExtraClass('btn btn-primary action--migrate-all-links');
        $button->setForm($gridField->getForm());

        return [
            $this->targetFragment => $button->Field(),
        ];
    }

    public function getActions($gridField)
    {
        return ['migratealllinks'];
    }

    public function handleAction(GridField $gridField, $actionName, $arguments, $data)
    {
        if ($actionName !== 'migratealllinks') {
            return;
        }

        $locator = Injector::inst()->get(LinkOwnerLocator::class);
        $migrator = Injector::inst()->get(LinkMigrator::class);

        foreach ($gridField->getList() as $link) {
            if (!$link instanceof OldLink || $link->IsMigrated) {
                continue;
            }

            $owners = $locator->findOwners($link);
            if (count($owners) !== 1) {
                continue;
            }

            $owner = $owners[0]['owner'];
            if (!$owner->canEdit()) {
                continue;
            }

            if (!$migrator->canMigrate($owner, $owners[0]['relation'])) {
                continue;
            }

            $migrator->migrate($link, $owner, $owners[0]['relation']);
        }
    }
}
