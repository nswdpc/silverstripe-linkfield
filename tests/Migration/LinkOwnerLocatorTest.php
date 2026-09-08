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

    public function testFindsManyManyOwnerWhenSinglyOwned(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();
        $owner->Buttons()->add($oldLink, ['Sort' => 1]);

        $found = (new LinkOwnerLocator())->findOwners($oldLink);

        $this->assertCount(1, $found);
        $this->assertSame($owner->ID, $found[0]['owner']->ID);
        $this->assertSame('Buttons', $found[0]['relation']);
    }

    public function testFindsBothOwnersWhenManyManyLinkIsGenuinelyShared(): void
    {
        $ownerA = MigrationTestOwner::create();
        $ownerA->write();
        $ownerB = MigrationTestOwner::create();
        $ownerB->write();

        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();
        $ownerA->Buttons()->add($oldLink, ['Sort' => 1]);
        $ownerB->Buttons()->add($oldLink, ['Sort' => 1]);

        $found = (new LinkOwnerLocator())->findOwners($oldLink);

        $this->assertCount(2, $found);
        $foundOwnerIDs = array_map(fn (array $entry) => $entry['owner']->ID, $found);
        $this->assertEqualsCanonicalizing([$ownerA->ID, $ownerB->ID], $foundOwnerIDs);
    }
}
