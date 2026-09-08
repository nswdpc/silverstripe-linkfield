<?php

namespace gorriecoe\LinkField\Tests\Migration;

use gorriecoe\Link\Models\Link as OldLink;
use gorriecoe\LinkField\Migration\GridFieldLinkOwnerColumn;
use gorriecoe\LinkField\Migration\GridFieldMigrateLinkButton;
use gorriecoe\LinkField\Migration\LinkMigrator;
use gorriecoe\LinkField\Tests\Migration\Stubs\MigrationTestOwner;
use SilverStripe\Core\Config\Config;
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
        Config::modify()->set(LinkMigrator::class, 'relation_map', [
            MigrationTestOwner::class => ['Button' => 'CoreButton'],
        ]);
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

    public function testPerRowMigrateActionRefusesRelationWithNoRelationMapEntry(): void
    {
        Config::modify()->set(LinkMigrator::class, 'relation_map', []);

        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();
        $owner->ButtonID = $oldLink->ID;
        $owner->write();

        $gridField = GridField::create('Links', 'Links', OldLink::get(), GridFieldConfig::create());
        $button = GridFieldMigrateLinkButton::create();

        $this->expectException(ValidationException::class);
        $button->handleAction($gridField, 'migratelink', ['RecordID' => $oldLink->ID], []);
    }

    public function testLinkOwnerColumnShowsOwnerAndRelationWhenFound(): void
    {
        $owner = MigrationTestOwner::create();
        $owner->write();

        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();
        $owner->ButtonID = $oldLink->ID;
        $owner->write();

        $gridField = GridField::create('Links', 'Links', OldLink::get(), GridFieldConfig::create());
        $column = GridFieldLinkOwnerColumn::create();

        $content = $column->getColumnContent($gridField, $oldLink, 'LinkOwner');

        $this->assertStringContainsString('#' . $owner->ID, $content);
        $this->assertStringContainsString('Button', $content);
    }

    public function testLinkOwnerColumnShowsNoOwnerMessageForOrphanedLink(): void
    {
        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();

        $gridField = GridField::create('Links', 'Links', OldLink::get(), GridFieldConfig::create());
        $column = GridFieldLinkOwnerColumn::create();

        $content = $column->getColumnContent($gridField, $oldLink, 'LinkOwner');

        $this->assertSame('No owner found', $content);
    }

    public function testLinkOwnerColumnListsEveryOwnerWhenLinkIsShared(): void
    {
        $ownerA = MigrationTestOwner::create();
        $ownerA->write();
        $ownerB = MigrationTestOwner::create();
        $ownerB->write();

        $oldLink = OldLink::create(['Type' => 'URL', 'URL' => 'https://example.com']);
        $oldLink->write();
        $ownerA->Buttons()->add($oldLink, ['Sort' => 1]);
        $ownerB->Buttons()->add($oldLink, ['Sort' => 1]);

        $gridField = GridField::create('Links', 'Links', OldLink::get(), GridFieldConfig::create());
        $column = GridFieldLinkOwnerColumn::create();

        $content = $column->getColumnContent($gridField, $oldLink, 'LinkOwner');

        $this->assertStringContainsString('#' . $ownerA->ID, $content);
        $this->assertStringContainsString('#' . $ownerB->ID, $content);
        $this->assertStringContainsString('<br>', $content);
    }
}
