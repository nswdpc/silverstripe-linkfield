<?php

namespace gorriecoe\LinkField\Extensions;

use gorriecoe\LinkField\LinkField;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\OptionsetField;

/**
 * Used in conjunction with LinkField, makes the types of Links available configurable.
 * @extends \SilverStripe\Core\Extension<(\gorriecoe\Link\Models\Link & static)>
 */
class LinkExtension extends Extension
{

    public function updateCMSFields(FieldList $fields)
    {
        // Hide Title field if the config requires it.
        if (!$this->shouldDisplayTitleFields()) {
            $fields->replaceField('Title', HiddenField::create('Title'));
        }

        // Set default Type value.
        $types = array_keys($this->getOwner()->getTypes());
        $typeField = $fields->dataFieldByName('Type');
        if (($typeField instanceof OptionsetField) && !in_array($typeField->getValue(), $types)) {
            $typeField->setValue($types[0]);
        }
    }

    public function onBeforeWrite()
    {

        // re-set the Title if title fields are not editable.
        if (!$this->shouldDisplayTitleFields()) {
            $this->resetTitle();
        }
    }

    /**
     * Only display the link types as defined by the owner's configuration.
     * @see \gorriecoe\Link\Models\Link::getTypes
     */
    public function updateTypes(array &$types)
    {
        $linkSpecs = $this->getOwner()->link_requirements;
        if (!empty($linkSpecs['types'])) {
            foreach (array_keys($types) as $type) {
                if (empty($linkSpecs['types'][$type]) && !in_array($type, $linkSpecs['types'], true)) {
                    unset($types[$type]);
                }
            }
        }
    }

    protected function shouldDisplayTitleFields(): bool
    {
        $linkSpecs = $this->getOwner()->link_requirements;
        return !isset($linkSpecs['title_display']) || $linkSpecs['title_display'];
    }

    protected function resetTitle()
    {
        $this->getOwner()->Title = null;
    }
}
