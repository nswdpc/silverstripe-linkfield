<?php

namespace gorriecoe\LinkField\Tests\Migration;

use gorriecoe\Link\Models\Link as OldLink;
use gorriecoe\LinkField\Migration\GridFieldMigrateLinkButton;
use gorriecoe\LinkField\Tests\Migration\Stubs\MigrationTestOwner;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\LinkField\Models\ExternalLink;

class LinkMigrationAdminTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        MigrationTestOwner::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->logInWithPermission('ADMIN');
    }

    public function testPerRowMigrateActionMigratesOwnedLink(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();
        $owner->ButtonID = $oldLink->ID;
        $owner->write();

        $gridField = GridField::create('Links', 'Links', OldLink::get(), GridFieldConfig::create());
        $button = GridFieldMigrateLinkButton::create();

        $button->handleAction($gridField, 'migratelink', ['RecordID' => $oldLink->ID], []);

        $oldLink = OldLink::get()->byID($oldLink->ID);
        $this->assertTrue((bool) $oldLink->IsMigrated);
        $this->assertInstanceOf(ExternalLink::class, $oldLink->MigratedLink());
    }

    public function testPerRowMigrateActionRefusesAmbiguousOwner(): void
    {
        $ownerA = MigrationTestOwner::create();
        $ownerA->write();
        $ownerB = MigrationTestOwner::create();
        $ownerB->write();

        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();
        $ownerA->ButtonID = $oldLink->ID;
        $ownerA->write();
        $ownerB->ButtonID = $oldLink->ID;
        $ownerB->write();

        $gridField = GridField::create('Links', 'Links', OldLink::get(), GridFieldConfig::create());
        $button = GridFieldMigrateLinkButton::create();

        $this->expectException(ValidationException::class);
        $button->handleAction($gridField, 'migratelink', ['RecordID' => $oldLink->ID], []);
    }
}
