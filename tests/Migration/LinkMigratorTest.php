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
use SilverStripe\LinkField\Models\Link as CoreLink;
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
        $this->assertSame('Button', $newLink->OwnerRelation);

        $oldLink = OldLink::get()->byID($oldLink->ID);
        $this->assertTrue((bool) $oldLink->IsMigrated);
        $this->assertSame($newLink->ID, $oldLink->MigratedLinkID);

        // The owner's own ButtonID is NOT touched, because MigrationTestOwner's
        // has_one still targets the old gorriecoe Link class in this test -
        // mutating it here would break $owner->Button() for that still-active
        // relation. See testSyncsOwnerRelationIdOnceHasOneIsRepointed().
        $owner = MigrationTestOwner::get()->byID($owner->ID);
        $this->assertSame($oldLink->ID, $owner->ButtonID);

        if ($newLink->hasExtension(Versioned::class)) {
            $this->assertTrue($newLink->isPublished());
        }
    }

    public function testSyncsOwnerRelationIdOnceHasOneIsRepointed(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();
        $owner->ButtonID = $oldLink->ID;
        $owner->write();

        $newLink = $this->migrator->migrate($oldLink, $owner, 'Button');

        // Before the project repoints has_one, ButtonID is left alone.
        $owner = MigrationTestOwner::get()->byID($owner->ID);
        $this->assertSame($oldLink->ID, $owner->ButtonID);

        // Once the project repoints the relation at core's Link class...
        Config::modify()->set(MigrationTestOwner::class, 'has_one', [
            'Button' => CoreLink::class,
        ]);

        // ...calling migrate() again (idempotent - no new Link is created)
        // syncs the owner's ButtonID onto the already-migrated record.
        $oldLink = OldLink::get()->byID($oldLink->ID);
        $again = $this->migrator->migrate($oldLink, $owner, 'Button');
        $this->assertSame($newLink->ID, $again->ID);

        $owner = MigrationTestOwner::get()->byID($owner->ID);
        $this->assertSame($newLink->ID, $owner->ButtonID);
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

        $first = $this->migrator->migrate($oldLink, $owner, 'Button');
        $countAfterFirst = ExternalLink::get()->count();

        $oldLink = OldLink::get()->byID($oldLink->ID);
        $second = $this->migrator->migrate($oldLink, $owner, 'Button');

        $this->assertSame($first->ID, $second->ID);
        $this->assertSame($countAfterFirst, ExternalLink::get()->count());
    }

    public function testManyManyRelationsAreRefused(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create([
            'Type' => 'URL',
            'URL' => 'https://example.com',
        ]);
        $oldLink->write();
        $owner->Buttons()->add($oldLink, ['Sort' => 1]);

        $this->assertFalse($this->migrator->canMigrate($owner, 'Buttons'));

        $this->expectException(LogicException::class);
        $this->migrator->migrate($oldLink, $owner, 'Buttons');
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

        $newLink = $this->migrator->migrate($oldLink, $owner, 'Button');

        if ($newLink->hasField('SelectedStyle')) {
            $this->assertSame('primary', $newLink->SelectedStyle);
        } else {
            $this->markTestSkipped('MigratedLinkStyleExtension is not applied in this environment');
        }
    }
}
