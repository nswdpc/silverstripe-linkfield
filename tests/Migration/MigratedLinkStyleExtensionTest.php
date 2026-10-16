<?php

declare(strict_types=1);

namespace gorriecoe\LinkField\Tests\Migration;

use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\LinkField\Models\ExternalLink;
use SilverStripe\LinkField\Models\Link as CoreLink;

/**
 * Covers SilverStripe\LinkField\Models\Link::$selectedstyle_field_enabled
 * controlling whether the SelectedStyle field is shown in the CMS, via
 * MigratedLinkStyleExtension::updateCmsFields(). The SelectedStyle db
 * column itself is unconditional (private static $db on the extension),
 * so unlike an earlier iteration of this toggle, this is a plain runtime
 * FieldList check with no test-database schema-build-order concerns.
 */
class MigratedLinkStyleExtensionTest extends SapphireTest
{
    public function testSelectedStyleFieldIsHiddenFromCmsFieldsByDefault(): void
    {
        $link = ExternalLink::create();
        $fields = $link->getCMSFields();

        $this->assertNull($fields->dataFieldByName('SelectedStyle'));
    }

    public function testSelectedStyleFieldIsShownWhenEnabledOnOwnerClass(): void
    {
        Config::modify()->set(CoreLink::class, 'selectedstyle_field_enabled', true);

        $link = ExternalLink::create();
        $fields = $link->getCMSFields();

        $this->assertNotNull($fields->dataFieldByName('SelectedStyle'));
    }
}
