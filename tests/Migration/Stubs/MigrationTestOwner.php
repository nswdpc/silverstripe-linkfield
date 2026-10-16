<?php

declare(strict_types=1);

namespace gorriecoe\LinkField\Tests\Migration\Stubs;

use gorriecoe\Link\Models\Link;
use SilverStripe\Dev\TestOnly;
use SilverStripe\LinkField\Models\Link as CoreLink;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ManyManyList;

/**
 * A minimal DataObject with a has_one and a many_many relation to the old
 * gorriecoe Link, plus separate, dedicated relations to core's Link -
 * mirroring the "parallel relation" pattern projects add to migrate a
 * relation (see docs/en/migration.md): a has_one for the migrated Button,
 * and a has_many (using core's own dot-notation Owner relation, matching
 * MultiLinkField's expectations) for the migrated Buttons. Used to exercise
 * LinkMigrator/LinkOwnerLocator migration behaviour without depending on a
 * real project model.
 *
 * @method Link Button()
 * @method ManyManyList Buttons()
 * @method CoreLink CoreButton()
 * @method DataList CoreButtons()
 */
class MigrationTestOwner extends DataObject implements TestOnly
{
    private static string $table_name = 'LinkFieldMigration_TestOwner';

    private static array $has_one = [
        'Button' => Link::class,
        'CoreButton' => CoreLink::class,
    ];

    private static array $many_many = [
        'Buttons' => Link::class,
    ];

    private static array $many_many_extraFields = [
        'Buttons' => [
            'Sort' => 'Int',
        ],
    ];

    private static array $has_many = [
        'CoreButtons' => CoreLink::class . '.Owner',
    ];
}
