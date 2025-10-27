<?php

namespace gorriecoe\LinkField\Forms;

use gorriecoe\LinkField\Forms\GridField\GridFieldHasOneDeleteButton;
use gorriecoe\LinkField\Forms\GridField\GridFieldLinkDetailForm;
use SilverShop\HasOneField\GridFieldHasOneButtonRow;
use SilverShop\HasOneField\GridFieldHasOneEditButton;
use SilverShop\HasOneField\GridFieldSummaryField;
use SilverShop\HasOneField\HasOneButtonField;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\ORM\DataObject;

/**
 * FormField to add a {@link \gorriecoe\Link\Models\Link} to a has_one relationship.
 *
 * This field allows configuration to define which types of link are available, which is passed to it
 * from the {@link \gorriecoe\LinkField\LinkField}.
 *
 * @see \gorriecoe\LinkField\LinkField
 */
class HasOneLinkField extends HasOneButtonField
{
    public function __construct(
        DataObject $parent,
        $relationName,
        $title = null,
        $linkConfig = [],
        $useAutocompleter = false
    ) {
        $config = GridFieldConfig::create()
            ->addComponent(GridFieldHasOneButtonRow::create())
            ->addComponent(new GridFieldSummaryField($relationName))
            ->addComponent($detailForm = GridFieldLinkDetailForm::create($linkConfig))
            ->addComponent(new GridFieldHasOneDeleteButton())
            ->addComponent(GridFieldHasOneEditButton::create('buttons-before-right'));

        $detailForm->setShowAdd(false);

        parent::__construct($parent, $relationName, null, $title, $config, $useAutocompleter);
    }

    /**
     * Set the configuration for this Link relationship.
     *
     * @return $this
     */
    public function setLinkConfig(array $linkConfig): static
    {
        $detailForm = $this->getConfig()->getComponentByType(GridFieldLinkDetailForm::class);
        if ($detailForm) {
            $detailForm->setLinkConfig($linkConfig);
        }

        return $this;
    }

    /**
     * Get the configuration for this Link relationship.
     *
     * @return array
     */
    public function getLinkConfig()
    {
        $detailForm = $this->getConfig()->getComponentByType(GridFieldLinkDetailForm::class);
        if ($detailForm) {
            return $detailForm->getLinkConfig();
        }

        return [];
    }

    /**
     * Check that there is a link.
     *
     * RequiredFields makes assumptions about what missing values look like
     * that the underlying implementation does not meet (specifically, a
     * missing link is not an empty array). That check is hard-coded into
     * RequiredFields.
     *
     * This works around it by checking to see if the field is required, then
     * checking whether it is empty as part of validate(). Normally, required
     * fields are checked in a separate check following validation.
     *
     * {@inheritdoc}
     * @see \SilverStripe\Forms\FormField::validate()
     */
    #[\Override]
    public function validate(): ValidationResult
    {
        $validationResult = parent::validate();
        $valid = $validationResult->isValid();
        if ($valid) {
            $recordValidationResult = $this->getRecord()->validate();
            $valid = $recordValidationResult->isValid();
            foreach ($recordValidationResult->getMessages() as $message) {
                $messageString = $message['message'] ?? '';
                $validationResult->addFieldError(
                    $this->getName(),
                    $messageString,
                    $message['messageType'] ?? ValidationResult::TYPE_ERROR,
                    '',
                    $message['messageCast'] ?? ValidationResult::CAST_TEXT,
                );
            }
        }

        if ($valid && $this->Required() && !$this->getRecord()->Type) {
            $valid = false;

            $errorMessage = _t('SilverStripe\\Forms\\Form.FIELDISREQUIRED', '{name} is required', [
                'name' => strip_tags('"' . ($this->Title() ?: $this->getName()) . '"'),
            ]);

            $validationResult->addFieldError($this->getName(), $errorMessage, ValidationResult::TYPE_ERROR);
        }

        return $validationResult;
    }

    /**
     * Display GridField with messages.
     *
     * GridField renders using a hard-coded table, consequently it cannot
     * display messages. This will append any messages.
     *
     * {@inheritdoc}
     * @see \SilverStripe\Forms\GridField\GridField::FieldHolder()
     */
    #[\Override]
    public function FieldHolder($properties = [])
    {
        $html = parent::FieldHolder();
        $message = Convert::raw2xml($this->getMessage());
        if (is_array($message)) {
            $message = $message['message'];
        }

        if ($message) {
            $html .= '<p class="alert" role="alert" id="message-' . $this->ID
                . '">' . $message . '</p>';
        }

        return $html;
    }
}
