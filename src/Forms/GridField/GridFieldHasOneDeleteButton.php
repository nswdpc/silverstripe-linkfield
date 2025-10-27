<?php

namespace gorriecoe\LinkField\Forms\GridField;

use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Control\Controller;

class GridFieldHasOneDeleteButton implements GridField_HTMLProvider, GridField_ActionProvider
{
    /**
     * GridFieldHasOneUnlinkButton constructor.
     */
    public function __construct(protected string $targetFragment = 'buttons-before-right')
    {
    }

    /**
     * Get fragment to write the button to
     */
    public function getTargetFragment(): string
    {
        return $this->targetFragment;
    }

    /**
     * Set fragment to write the button to
     */
    public function setTargetFragment(string $targetFragment): static
    {
        $this->targetFragment = $targetFragment;
        return $this;
    }

    /**
     * @param GridField $gridField
     * @return array
     */
    public function getActions($gridField)
    {
        return ['deleterelation'];
    }

    public function handleAction(GridField $gridField, $actionName, $arguments, $data)
    {

        \PHPStan\dumpType($gridField);

        if ($actionName !== 'deleterelation') {
            return;
        }

        $record = $gridField->getRecord();
        if (!$record || !$record->exists()) {
            return;
        }

        /** @var DataObject|null $item */
        $item = $gridField->getList()->byID($record->ID);
        if ($item === null) {
            return;
        }

        if (!$item->canDelete()) {
            throw ValidationException::create(
                _t(self::class . '.EditPermissionsFailure', 'No delete permissions')
            );
        }

        $gridField->setRecord(null);
        $gridField->getList()->remove($item);
        $item->delete();

        Controller::curr()->getResponse()->setStatusCode(
            200,
            _t(self::class . '.Deleted', 'Deleted')
        );
    }

    public function getHTMLFragments($gridField)
    {
        $record = $gridField->getRecord();
        if (!$record || !$record->exists()) {
            return [];
        }

        $field = GridField_FormAction::create(
            $gridField,
            'gridfield_deleterelation',
            _t(self::class . '.Delete', 'Delete'),
            'deleterelation',
            ['deleterelation']
        );

        $field->setAttribute('data-icon', 'chain--plus')
            ->addExtraClass(
                'align-items-center d-flex btn btn-outline-secondary '
                    . 'font-icon-trash-bin action_gridfield_deleterelation'
            );

        return [
            $this->targetFragment => $field->Field(),
        ];
    }
}
