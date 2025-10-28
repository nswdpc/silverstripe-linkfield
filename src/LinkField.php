<?php

namespace gorriecoe\LinkField;

use gorriecoe\Link\Models\Link;
use gorriecoe\LinkField\Forms\GridField\GridFieldLinkDetailForm;
use gorriecoe\LinkField\Forms\HasOneLinkField;
use SilverStripe\View\Requirements;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\HTMLReadonlyField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\RequestHandler;
use SilverStripe\ORM\DataObject;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;
use SilverShop\HasOneField\HasOneButtonField;

/**
 * LinkField
 *s
 * @package silverstripe-linkfield
 */
class LinkField extends FormField
{
    /**
     * @var string $name
     */
    protected $name;

    /**
     * @var string $title
     */
    protected $title;

    /**
     * @var DataObject $parent
     */
    protected $record;

    /**
     * The column to be used for sorting
     * @var string
     */
    protected $sortColumn;

    /**
     * The column to be used for sorting
     */
    private static string $sort_column = 'Sort';

    /**
     * @param mixed[] $linkConfig
     * @param DataObject $parent
     */
    public function __construct($name, $title, protected $parent, protected $linkConfig = [])
    {
        parent::__construct($name, $title);

        $this->name = $name;
        $this->title = $title;
        if ($this->isOneOrMany() == 'one') {
            $this->record = $this->parent->{$name}();
        }

        $this->setForm($this->parent->Form);
    }

    /**
     * @param string $title
     *
     * @return $this
     */
    #[\Override]
    public function setTitle($title)
    {
        $this->title = $title;
        return $this;
    }

    /**
     * @param array $properties
     */
    #[\Override]
    public function Field($properties = [])
    {
        Requirements::css('gorriecoe/silverstripe-linkfield: client/dist/linkfield.css');
        $parent = $this->parent;
        switch ($this->isOneOrMany()) {
            case 'one':
                $field = CompositeField::create([
                    $this->getHasOneField()
                ]);
                $relationship = $parent->{$this->name}();
                if ($relationship instanceof Link) {
                    $linkExampleField = HTMLReadonlyField::create(
                        $this->name . 'View',
                        _t(self::class . '.EXAMPLE', 'Example'),
                        htmlspecialchars($relationship->forTemplate())
                    );
                    $field->push($linkExampleField);
                }
                break;
            case 'many':
                $field = $this->getManyField();
                break;
            default:
                $field = HTMLReadonlyField::create(
                    $this->name . 'Save',
                    _t(self::class . '.SAVETITLE', 'Save'),
                    htmlspecialchars(_t(
                        self::class . '.PLEASESAVEOBJECTTOADDLINKS',
                        'Please save {object} first to add {links}',
                        [
                            'object' => _t(self::class . '.THIS_RECORD_SAVE', 'this record'),
                            'links' => singleton(Link::class)->i18n_plural_name()
                        ]
                    ))
                );
                break;
        }

        $field->addExtraClass('linkfield');

        $this->extend('updateField', $field);
        return $field->Field();
    }

    /**
     * @return array|RequestHandler|HTTPResponse|string|null
     * @throws HTTPResponse_Exception
     */
    #[\Override]
    public function handleRequest(HTTPRequest $request)
    {
        return match ($this->isOneOrMany()) {
            'one' => $this->getHasOneField()->handleRequest($request),
            'many' => $this->getManyField()->handleRequest($request),
            default => null,
        };

    }

    public function isOneOrMany(): ?string
    {
        $parent = $this->parent;
        if (!$parent->exists() || !$parent instanceof DataObject) {
            return null;
        }

        return match ($parent->getRelationType($this->name)) {
            'has_one', 'belongs_to' => 'one',
            'has_many', 'many_many', 'belongs_many_many' => 'many',
            default => null,
        };
    }

    public function getRecord()
    {
        return $this->record;
    }

    /**
     * @return HasOneButtonField
     */
    public function getHasOneField()
    {
        $field = HasOneLinkField::create(
            $this->parent,
            $this->name,
            $this->title,
            $this->linkConfig
        )
        ->setForm($this->Form)
        ->addExtraClass('linkfield__button');

        $this->extend('updateHasOneField', $field);

        return $field;
    }

    /**
     * @return GridField
     */
    public function getManyField()
    {
        $config = GridFieldConfig::create()
            ->addComponent(GridFieldButtonRow::create('before'))
            ->addComponent(GridFieldAddNewButton::create('buttons-before-left'))
            ->addComponent(GridFieldLinkDetailForm::create($this->getLinkConfig()))
            ->addComponent(GridFieldDataColumns::create())
            ->addComponent(GridFieldOrderableRows::create($this->getSortColumn()))
            ->addComponent(GridFieldEditButton::create())
            ->addComponent(GridFieldDeleteAction::create(false));

        $config->getComponentByType(GridFieldDataColumns::class)
            ->setDisplayFields([
                'Layout' => _t(self::class . '.LINK', 'Link')
            ]);

        $field = GridField::create(
            $this->name,
            $this->title,
            $this->parent->{$this->name}(),
            $config
        )->setForm($this->Form);

        $this->extend('updateManyField', $field);

        return $field;
    }

    /**
     * Set the column to be used for sorting
     * @param string $sortColumn
     * @return $this
     */
    public function setSortColumn($sortColumn): static
    {
        $this->sortColumn = $sortColumn;
        return $this;
    }

    /**
     * Returns the column to be used for sorting
     * @return string
     */
    public function getSortColumn()
    {
        if ($this->sortColumn) {
            return $this->sortColumn;
        }

        return $this->config()->get('sort_column');
    }

    /**
     * Set the configuration for this Link relationship.
     * @param array $linkConfig
     * @return $this
     */
    public function setLinkConfig($linkConfig): static
    {
        $this->linkConfig = $linkConfig;
        return $this;
    }

    /**
     * Get the configuration for this Link relationship.
     * @return array
     */
    public function getLinkConfig()
    {
        return $this->linkConfig;
    }

    #[\Override]
    public function validate(): \SilverStripe\Core\Validation\ValidationResult
    {
        return $this->Field()->validate();
    }
}
