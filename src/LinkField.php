<?php

namespace gorriecoe\LinkField;

use gorriecoe\Link\Models\Link;
use gorriecoe\LinkField\Forms\GridField\GridFieldLinkDetailForm;
use gorriecoe\LinkField\Forms\HasOneLinkField;
use SilverStripe\Core\Validation\ValidationResult;
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
use SilverStripe\LinkField\Form\LinkField as CoreLinkField;
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
     * Allow-list of owner class => relation names that are eligible to be
     * migrated to core silverstripe/linkfield. Nothing migrates by default -
     * a relation must be explicitly listed here, and its underlying Link
     * record must already be flagged as migrated (via LinkMigrator), before
     * this field starts delegating to core's own field. Only has_one
     * relations are ever delegated; has_many/many_many/belongs_many_many
     * relations always keep rendering via the legacy field, since those
     * relation types are never eligible for migration in the first place
     * (see LinkMigrator).
     *
     * gorriecoe\LinkField\LinkField:
     *   migrated_relations:
     *     MyPage:
     *       - Button
     *
     * Listing a relation here only controls which delegate field gets
     * rendered. For core's LinkFieldController to recognise the relation at
     * all (SilverStripe\LinkField\Controllers\LinkFieldController::
     * getOwnerFromRequest() matches by checking the owner's own configured
     * has_one target class against SilverStripe\LinkField\Models\Link), the
     * owning class's own has_one entry for that relation must also be
     * repointed at core's Link class in the project's own config, e.g.:
     *
     * MyPage:
     *   has_one:
     *     Button: SilverStripe\LinkField\Models\Link
     *
     * This is a config-only change (no PHP edit to the owning DataObject
     * class), and only takes effect once the underlying Link has actually
     * been migrated - see docs/en/migration.md.
     */
    private static array $migrated_relations = [];

    private ?FormField $migratedDelegate = null;

    private bool $migratedDelegateResolved = false;

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
     * If this relation has been allow-listed for migration and its
     * underlying Link has already been migrated, returns a delegate
     * instance of core silverstripe/linkfield's own field, which Field(),
     * handleRequest() and validate() defer to instead of the legacy
     * rendering/handling below. Returns null for any relation that isn't
     * allow-listed, isn't yet migrated, or isn't a has_one/belongs_to
     * relation - in all of those cases behaviour is unchanged from today.
     */
    protected function getMigratedDelegate(): ?FormField
    {
        if ($this->migratedDelegateResolved) {
            return $this->migratedDelegate;
        }

        $this->migratedDelegateResolved = true;

        if ($this->isOneOrMany() !== 'one' || !$this->isRelationMigrationAllowed()) {
            return $this->migratedDelegate;
        }

        $oldLink = $this->record;
        if (!$oldLink instanceof Link || !$oldLink->exists() || !$oldLink->IsMigrated || !$oldLink->MigratedLinkID) {
            return $this->migratedDelegate;
        }

        $this->migratedDelegate = CoreLinkField::create($this->name, $this->title)
            ->setForm($this->getForm())
            ->setValue($oldLink->MigratedLinkID);

        return $this->migratedDelegate;
    }

    /**
     * Whether $this->name on $this->parent's class has been explicitly
     * allow-listed for migration via the migrated_relations config.
     */
    protected function isRelationMigrationAllowed(): bool
    {
        $allowList = (array) $this->config()->get('migrated_relations');
        foreach ($allowList as $class => $relations) {
            if (is_a($this->parent, $class) && in_array($this->name, (array) $relations, true)) {
                return true;
            }
        }

        return false;
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
     * Get the field that renders the link management interface
     */
    protected function getLinkManagementField(): FormField
    {
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

        return $field;
    }

    /**
     * @param array $properties
     */
    #[\Override]
    public function Field($properties = [])
    {
        if ($delegate = $this->getMigratedDelegate()) {
            return $delegate->Field($properties);
        }

        Requirements::css('nswdpc/silverstripe-linkfield: client/dist/linkfield.css');
        $field = $this->getLinkManagementField();
        return $field->Field();
    }

    /**
     * @return array|RequestHandler|HTTPResponse|string|null
     * @throws HTTPResponse_Exception
     */
    #[\Override]
    public function handleRequest(HTTPRequest $request)
    {
        if ($delegate = $this->getMigratedDelegate()) {
            return $delegate->handleRequest($request);
        }

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
    public function validate(): ValidationResult
    {
        if ($delegate = $this->getMigratedDelegate()) {
            return $delegate->validate();
        }

        return $this->getLinkManagementField()->validate();
    }
}
