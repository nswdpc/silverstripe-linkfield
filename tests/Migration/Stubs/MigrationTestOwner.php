<?php

namespace gorriecoe\LinkField\Tests\Migration\Stubs;

use gorriecoe\Link\Models\Link;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ManyManyList;

/**
 * A minimal DataObject with both a has_one and a many_many relation to the
 * old gorriecoe Link, used to exercise LinkMigrator/LinkOwnerLocator/
 * LinkField migration behaviour without depending on a real project model.
 *
 * @method Link Button()
 * @method ManyManyList Buttons()
 */
class MigrationTestOwner extends DataObject implements TestOnly
{
    private static string $table_name = 'LinkFieldMigration_TestOwner';

    private static array $has_one = [
        'Button' => Link::class,
    ];

    private static array $many_many = [
        'Buttons' => Link::class,
    ];

    private static array $many_many_extraFields = [
        'Buttons' => [
            'Sort' => 'Int',
        ],
    ];
}
