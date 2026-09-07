<?php

namespace gorriecoe\LinkField\Migration;

use gorriecoe\Link\Models\Link as OldLink;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Model\ModelData;
use SilverStripe\ORM\DataObjectInterface;

/**
 * Adds a per-row "Migrate now" action to a GridField listing old gorriecoe
 * Link records, for use on LinkMigrationAdmin.
 *
 * Because gorriecoe's Link stores no back-reference to its owner, migrating
 * a single row from this button requires first locating its owner via
 * LinkOwnerLocator. A row is only migratable from here when exactly one
 * has_one owner is found for it - ambiguous (multiple owners, which can
 * only happen for many_many-style sharing) or ownerless rows show a status
 * message instead of a button.
 *
 */
class GridFieldMigrateLinkButton extends AbstractGridFieldComponent implements GridField_ColumnProvider, GridField_ActionProvider
{
    public function augmentColumns($gridField, &$columns)
    {
        if (!in_array('MigrateLink', $columns ?? [], true)) {
            $columns[] = 'MigrateLink';
        }
    }

    public function getColumnsHandled($gridField)
    {
        return ['MigrateLink'];
    }

    /**
     * @param DataObjectInterface&ModelData $record
     */
    public function getColumnAttributes($gridField, $record, $columnName)
    {
        return ['class' => 'grid-field__col-compact'];
    }

    public function getColumnMetadata($gridField, $columnName)
    {
        return ['title' => ''];
    }

    /**
     * @param DataObjectInterface&ModelData $record
     */
    public function getColumnContent($gridField, $record, $columnName): string
    {
        if (!$record instanceof OldLink) {
            return '';
        }

        if ($record->IsMigrated) {
            return _t(__CLASS__ . '.MIGRATED', 'Migrated');
        }

        $owners = $this->getLocator()->findOwners($record);
        if (count($owners) === 0) {
            return _t(__CLASS__ . '.NO_OWNER', 'No has_one owner found');
        }
        if (count($owners) > 1) {
            return _t(__CLASS__ . '.AMBIGUOUS_OWNER', 'Linked from multiple owners - migrate individually');
        }

        $owner = $owners[0]['owner'];
        if (!$owner->canEdit()) {
            return _t(__CLASS__ . '.NO_PERMISSION', 'No permission');
        }

        $field = GridField_FormAction::create(
            $gridField,
            'MigrateLink' . $record->ID,
            _t(__CLASS__ . '.MIGRATE_NOW', 'Migrate now'),
            'migratelink',
            ['RecordID' => $record->ID]
        )
            ->addExtraClass('btn btn-secondary')
            ->setAttribute('classNames', 'action--migrate-link');

        return $field->Field();
    }

    public function getActions($gridField)
    {
        return ['migratelink'];
    }

    public function handleAction(GridField $gridField, $actionName, $arguments, $data)
    {
        if ($actionName !== 'migratelink') {
            return;
        }

        $link = $gridField->getList()->byID($arguments['RecordID']);
        if (!$link instanceof OldLink) {
            return;
        }

        if ($link->IsMigrated) {
            return;
        }

        $owners = $this->getLocator()->findOwners($link);
        if (count($owners) !== 1) {
            throw ValidationException::create(_t(
                __CLASS__ . '.CANNOT_MIGRATE',
                'Cannot migrate this link: expected exactly one has_one owner, found {count}.',
                ['count' => count($owners)]
            ));
        }

        $owner = $owners[0]['owner'];
        if (!$owner->canEdit()) {
            throw ValidationException::create(_t(__CLASS__ . '.EDIT_PERMISSIONS_FAILURE', 'No permission to migrate this link'));
        }

        $this->getMigrator()->migrate($link, $owner, $owners[0]['relation']);
    }

    protected function getLocator(): LinkOwnerLocator
    {
        return Injector::inst()->get(LinkOwnerLocator::class);
    }

    protected function getMigrator(): LinkMigrator
    {
        return Injector::inst()->get(LinkMigrator::class);
    }
}
