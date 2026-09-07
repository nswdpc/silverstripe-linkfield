<?php

namespace gorriecoe\LinkField\Tests\Migration;

use gorriecoe\Link\Models\Link as OldLink;
use gorriecoe\LinkField\Migration\LinkOwnerLocator;
use gorriecoe\LinkField\Tests\Migration\Stubs\MigrationTestOwner;
use SilverStripe\Dev\SapphireTest;

class LinkOwnerLocatorTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        MigrationTestOwner::class,
    ];

    public function testFindsHasOneOwner(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();
        $owner->ButtonID = $oldLink->ID;
        $owner->write();

        $found = (new LinkOwnerLocator())->findOwners($oldLink);

        $this->assertCount(1, $found);
        $this->assertSame($owner->ID, $found[0]['owner']->ID);
        $this->assertSame('Button', $found[0]['relation']);
    }

    public function testReturnsEmptyForOrphanedLink(): void
    {
        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();

        $found = (new LinkOwnerLocator())->findOwners($oldLink);

        $this->assertSame([], $found);
    }

    public function testDoesNotMatchManyManyRelations(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();
        $owner->Buttons()->add($oldLink, ['Sort' => 1]);

        // many_many is not a has_one, so the locator (which only scans
        // has_one config) correctly finds no owner for it.
        $found = (new LinkOwnerLocator())->findOwners($oldLink);

        $this->assertSame([], $found);
    }
}
