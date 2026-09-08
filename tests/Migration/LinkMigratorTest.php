<?php

namespace gorriecoe\LinkField\Tests\Migration;

use gorriecoe\Link\Models\Link as OldLink;
use gorriecoe\LinkField\Migration\LinkMigrator;
use gorriecoe\LinkField\Tests\Migration\Stubs\MigrationTestOwner;
use LogicException;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\LinkField\Models\EmailLink;
use SilverStripe\LinkField\Models\ExternalLink;
use SilverStripe\LinkField\Models\FileLink;
use SilverStripe\LinkField\Models\PhoneLink;
use SilverStripe\LinkField\Models\SiteTreeLink;
use SilverStripe\Versioned\Versioned;

class LinkMigratorTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        MigrationTestOwner::class,
    ];

    protected LinkMigrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrator = Injector::inst()->get(LinkMigrator::class);
        Config::modify()->set(LinkMigrator::class, 'relation_map', [
            MigrationTestOwner::class => ['Button' => 'CoreButton', 'Buttons' => 'CoreButtons'],
        ]);
    }

    public function testMigratesUrlLink(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create([
            'Type' => 'URL',
            'Title' => 'Example',
            'URL' => 'https://example.com',
            'OpenInNewWindow' => true,
        ]);
        $oldLink->write();
        $owner->ButtonID = $oldLink->ID;
        $owner->write();

        $newLink = $this->migrator->migrate($oldLink, $owner, 'Button');

        $this->assertInstanceOf(ExternalLink::class, $newLink);
        $this->assertSame('Example', $newLink->LinkText);
        $this->assertSame('https://example.com', $newLink->ExternalUrl);
        $this->assertTrue((bool) $newLink->OpenInNew);
        $this->assertSame($owner->ID, $newLink->OwnerID);
        $this->assertSame(MigrationTestOwner::class, $newLink->OwnerClass);
        // OwnerRelation must be the NEW (target) relation name, not the old
        // one: core's own Link::Owner() cross-checks
        // $owner->{$this->OwnerRelation}ID === $this->ID, which would fail
        // if this were still "Button" (that field holds the OLD Link's ID).
        $this->assertSame('CoreButton', $newLink->OwnerRelation);

        $oldLink = OldLink::get()->byID($oldLink->ID);
        $this->assertTrue((bool) $oldLink->IsMigrated);
        $this->assertSame($newLink->ID, $oldLink->MigratedLinkID);

        // The owner's dedicated CoreButton relation now points at the new
        // Link, while the old Button relation is completely untouched - it's
        // a separate field that's never been used for anything else, so
        // there's no shared column and no ordering/collision concern.
        $owner = MigrationTestOwner::get()->byID($owner->ID);
        $this->assertSame($newLink->ID, $owner->CoreButtonID);
        $this->assertSame($oldLink->ID, $owner->ButtonID);

        // Core's own Link::Owner() cross-checks OwnerRelation against the
        // owner's current {relation}ID - confirms the fix above actually
        // resolves correctly, not just that the string matches.
        $resolvedOwner = $newLink->Owner();
        $this->assertNotNull($resolvedOwner);
        $this->assertSame($owner->ID, $resolvedOwner->ID);

        if ($newLink->hasExtension(Versioned::class)) {
            $this->assertTrue($newLink->isPublished());
        }
    }

    public function testRefusesMigrationWhenNoRelationMapEntry(): void
    {
        Config::modify()->set(LinkMigrator::class, 'relation_map', []);

        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();

        $this->assertFalse($this->migrator->canMigrate($owner, 'Button'));

        $this->expectException(LogicException::class);
        $this->migrator->migrate($oldLink, $owner, 'Button');
    }

    public function testRefusesMigrationWhenTargetRelationIsMisconfigured(): void
    {
        Config::modify()->set(LinkMigrator::class, 'relation_map', [
            MigrationTestOwner::class => ['Button' => 'NotARealRelation'],
        ]);

        $owner = MigrationTestOwner::create();
        $owner->write();

        $this->assertFalse($this->migrator->canMigrate($owner, 'Button'));
    }

    public function testMigratesEmailLink(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create([
            'Type' => 'Email',
            'Email' => 'person@example.com',
        ]);
        $oldLink->write();
        $owner->ButtonID = $oldLink->ID;
        $owner->write();

        $newLink = $this->migrator->migrate($oldLink, $owner, 'Button');

        $this->assertInstanceOf(EmailLink::class, $newLink);
        $this->assertSame('person@example.com', $newLink->Email);
    }

    public function testMigratesPhoneLink(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create([
            'Type' => 'Phone',
            'Phone' => '+61 2 1234 5678',
        ]);
        $oldLink->write();
        $owner->ButtonID = $oldLink->ID;
        $owner->write();

        $newLink = $this->migrator->migrate($oldLink, $owner, 'Button');

        $this->assertInstanceOf(PhoneLink::class, $newLink);
        $this->assertSame('+61 2 1234 5678', $newLink->Phone);
    }

    public function testMigratesFileLink(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $file = File::create(['Name' => 'test.pdf']);
        $file->write();

        $oldLink = OldLink::create([
            'Type' => 'File',
            'FileID' => $file->ID,
        ]);
        $oldLink->write();
        $owner->ButtonID = $oldLink->ID;
        $owner->write();

        $newLink = $this->migrator->migrate($oldLink, $owner, 'Button');

        $this->assertInstanceOf(FileLink::class, $newLink);
        $this->assertSame($file->ID, $newLink->FileID);
    }

    public function testMigratesSiteTreeLinkWithAnchor(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $page = SiteTree::create(['Title' => 'A page']);
        $page->write();

        $oldLink = OldLink::create([
            'Type' => 'SiteTree',
            'SiteTreeID' => $page->ID,
            'Anchor' => '#section-2',
        ]);
        $oldLink->write();
        $owner->ButtonID = $oldLink->ID;
        $owner->write();

        $newLink = $this->migrator->migrate($oldLink, $owner, 'Button');

        $this->assertInstanceOf(SiteTreeLink::class, $newLink);
        $this->assertSame($page->ID, $newLink->PageID);
        $this->assertSame('section-2', $newLink->Anchor);
        $this->assertSame('', $newLink->QueryString);
    }

    public function testMigratesSiteTreeLinkWithQueryString(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $page = SiteTree::create(['Title' => 'A page']);
        $page->write();

        $oldLink = OldLink::create([
            'Type' => 'SiteTree',
            'SiteTreeID' => $page->ID,
            'Anchor' => '?option1=value',
        ]);
        $oldLink->write();
        $owner->ButtonID = $oldLink->ID;
        $owner->write();

        $newLink = $this->migrator->migrate($oldLink, $owner, 'Button');

        $this->assertSame('', $newLink->Anchor);
        $this->assertSame('option1=value', $newLink->QueryString);
    }

    public function testMigrationIsIdempotent(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create([
            'Type' => 'URL',
            'URL' => 'https://example.com',
        ]);
        $oldLink->write();
        $owner->ButtonID = $oldLink->ID;
        $owner->write();

        $first = $this->migrator->migrate($oldLink, $owner, 'Button');
        $countAfterFirst = ExternalLink::get()->count();

        // Simulate something clearing the dedicated relation - migrate()
        // must be safe to call again, re-sync it, and not create a second
        // migrated Link.
        $owner->CoreButtonID = 0;
        $owner->write();

        $oldLink = OldLink::get()->byID($oldLink->ID);
        $second = $this->migrator->migrate($oldLink, $owner, 'Button');

        $this->assertSame($first->ID, $second->ID);
        $this->assertSame($countAfterFirst, ExternalLink::get()->count());

        $owner = MigrationTestOwner::get()->byID($owner->ID);
        $this->assertSame($first->ID, $owner->CoreButtonID);
    }

    public function testSinglyOwnedManyManyLinkMigratesToACoreHasMany(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create([
            'Type' => 'URL',
            'Title' => 'Example',
            'URL' => 'https://example.com',
        ]);
        $oldLink->write();
        $owner->Buttons()->add($oldLink, ['Sort' => 1]);

        $this->assertTrue($this->migrator->canMigrate($owner, 'Buttons'));

        $newLink = $this->migrator->migrate($oldLink, $owner, 'Buttons');

        $this->assertInstanceOf(ExternalLink::class, $newLink);
        $this->assertSame($owner->ID, $newLink->OwnerID);
        $this->assertSame(MigrationTestOwner::class, $newLink->OwnerClass);
        $this->assertSame('CoreButtons', $newLink->OwnerRelation);

        // No owner-side column to set for a has_many target - the relation
        // is entirely carried by the new Link's own Owner fields.
        $this->assertTrue($owner->CoreButtons()->filter('ID', $newLink->ID)->exists());

        // The old many_many attachment is completely untouched.
        $this->assertTrue($owner->Buttons()->filter('ID', $oldLink->ID)->exists());
    }

    public function testGenuinelySharedManyManyLinkIsRefused(): void
    {
        $ownerA = MigrationTestOwner::create();
        $ownerA->write();
        $ownerB = MigrationTestOwner::create();
        $ownerB->write();

        $oldLink = OldLink::create([
            'Type' => 'URL',
            'URL' => 'https://example.com',
        ]);
        $oldLink->write();
        $ownerA->Buttons()->add($oldLink, ['Sort' => 1]);
        $ownerB->Buttons()->add($oldLink, ['Sort' => 1]);

        // canMigrate() only checks relation-type/mapping eligibility, which
        // is satisfied here - the sole-ownership refusal happens inside
        // migrate() itself, re-verified against the actual current owners.
        $this->assertTrue($this->migrator->canMigrate($ownerA, 'Buttons'));

        $this->expectException(LogicException::class);
        $this->migrator->migrate($oldLink, $ownerA, 'Buttons');
    }

    public function testMigrateRefusesALinkNotActuallyAttachedToTheGivenOwnerRelation(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();

        // $oldLink was never attached to $owner via 'Button' at all.
        $this->expectException(LogicException::class);
        $this->migrator->migrate($oldLink, $owner, 'Button');
    }

    public function testSelectedStyleIsCarriedForwardWhenExtensionApplied(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create([
            'Type' => 'URL',
            'URL' => 'https://example.com',
            'SelectedStyle' => 'primary',
        ]);
        $oldLink->write();
        $owner->ButtonID = $oldLink->ID;
        $owner->write();

        $newLink = $this->migrator->migrate($oldLink, $owner, 'Button');

        if ($newLink->hasField('SelectedStyle')) {
            $this->assertSame('primary', $newLink->SelectedStyle);
        } else {
            $this->markTestSkipped('MigratedLinkStyleExtension is not applied in this environment');
        }
    }
}
