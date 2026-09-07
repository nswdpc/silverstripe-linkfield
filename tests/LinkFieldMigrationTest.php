<?php

namespace gorriecoe\LinkField\Tests;

use gorriecoe\Link\Models\Link as OldLink;
use gorriecoe\LinkField\LinkField;
use gorriecoe\LinkField\Migration\LinkMigrator;
use gorriecoe\LinkField\Tests\Migration\Stubs\MigrationTestOwner;
use ReflectionMethod;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\LinkField\Form\LinkField as CoreLinkField;

/**
 * Verifies gorriecoe\LinkField\LinkField only ever delegates to core's field
 * when a relation has been explicitly allow-listed via migrated_relations
 * AND its underlying Link has actually been migrated - every other
 * combination must keep rendering/handling via the legacy field, so that
 * default behaviour for unconfigured relations is unchanged.
 */
class LinkFieldMigrationTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        MigrationTestOwner::class,
    ];

    private function getDelegate(LinkField $field)
    {
        $method = new ReflectionMethod(LinkField::class, 'getMigratedDelegate');
        return $method->invoke($field);
    }

    public function testDoesNotDelegateWhenNotAllowListed(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = $this->createMigratedOldLink($owner);

        // migrated_relations is empty by default - nothing should delegate.
        $field = LinkField::create('Button', 'Button', $owner);
        $this->assertNull($this->getDelegate($field));
    }

    public function testDoesNotDelegateWhenAllowListedButNotMigrated(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();
        $owner->ButtonID = $oldLink->ID;
        $owner->write();

        Config::modify()->set(LinkField::class, 'migrated_relations', [
            MigrationTestOwner::class => ['Button'],
        ]);

        $field = LinkField::create('Button', 'Button', $owner);
        $this->assertNull($this->getDelegate($field));
    }

    public function testDelegatesWhenAllowListedAndMigrated(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = $this->createMigratedOldLink($owner);

        Config::modify()->set(LinkField::class, 'migrated_relations', [
            MigrationTestOwner::class => ['Button'],
        ]);

        $field = LinkField::create('Button', 'Button', $owner);
        $delegate = $this->getDelegate($field);

        $this->assertInstanceOf(CoreLinkField::class, $delegate);
        $this->assertSame($oldLink->MigratedLinkID, $delegate->dataValue());
    }

    public function testDoesNotDelegateForManyManyRelationEvenIfAllowListed(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        Config::modify()->set(LinkField::class, 'migrated_relations', [
            MigrationTestOwner::class => ['Buttons'],
        ]);

        $field = LinkField::create('Buttons', 'Buttons', $owner);
        $this->assertNull($this->getDelegate($field));
    }

    private function createMigratedOldLink(MigrationTestOwner $owner): OldLink
    {
        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();
        $owner->ButtonID = $oldLink->ID;
        $owner->write();

        $migrator = Injector::inst()->get(LinkMigrator::class);
        $migrator->migrate($oldLink, $owner, 'Button');

        return OldLink::get()->byID($oldLink->ID);
    }
}
