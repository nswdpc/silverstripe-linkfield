<?php

namespace gorriecoe\LinkField\Migration;

use gorriecoe\Link\Models\Link as OldLink;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Model\ModelData;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectInterface;

/**
 * Adds a read-only column to a GridField listing old gorriecoe Link records,
 * showing the label of the record's owner (and, where the owner supports
 * it, a link to edit it) - purely to help a CMS user find where a Link is
 * actually used, alongside the "Migrate now" action from
 * GridFieldMigrateLinkButton. Uses the same LinkOwnerLocator scan, so it
 * shows exactly the owner(s) migration would use: a single owner, "no owner
 * found", or every owner for a Link that's genuinely shared.
 *
 */
class GridFieldLinkOwnerColumn extends AbstractGridFieldComponent implements GridField_ColumnProvider
{
    public function augmentColumns($gridField, &$columns)
    {
        if (!in_array('LinkOwner', $columns ?? [], true)) {
            $columns[] = 'LinkOwner';
        }
    }

    public function getColumnsHandled($gridField)
    {
        return ['LinkOwner'];
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
        return ['title' => _t(self::class . '.OWNER_COLUMN', 'Used on')];
    }

    /**
     * @param DataObjectInterface&ModelData $record
     */
    public function getColumnContent($gridField, $record, $columnName): string
    {
        if (!$record instanceof OldLink) {
            return '';
        }

        $owners = $this->getLocator()->findOwners($record);
        if (count($owners) === 0) {
            return _t(self::class . '.NO_OWNER', 'No owner found');
        }

        $descriptions = array_map(
            fn (array $entry): string => $this->describeOwner($entry['owner'], $entry['relation']),
            $owners
        );

        return implode('<br>', $descriptions);
    }

    protected function describeOwner(DataObject $owner, string $relation): string
    {
        $label = sprintf(
            '%s: %s (%s)',
            $owner->i18n_singular_name(),
            $this->getOwnerTitle($owner),
            $relation
        );

        if ($owner->hasMethod('CMSEditLink')) {
            $url = $owner->getCMSEditLink();
            if ($url) {
                return sprintf('<a href="%s">%s</a>', htmlspecialchars($url), htmlspecialchars($label));
            }
        }

        return htmlspecialchars($label);
    }

    protected function getOwnerTitle(DataObject $owner): string
    {
        if ($owner->hasMethod('getTitle')) {
            $title = (string) $owner->getTitle();
            if ($title !== '') {
                return $title;
            }
        } elseif ($owner->hasField('Title')) {
            $title = (string) $owner->Title;
            if ($title !== '') {
                return $title;
            }
        }

        return '#' . $owner->ID;
    }

    protected function getLocator(): LinkOwnerLocator
    {
        return Injector::inst()->get(LinkOwnerLocator::class);
    }
}
