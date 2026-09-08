<?php

namespace gorriecoe\LinkField\Migration;

use SilverStripe\Core\Extension;
use SilverStripe\LinkField\Models\PhoneLink;

/**
 * Provides method compatibilityh with the `gorriecoe\Link\Models\Link` model
 * @extends Extension<\SilverStripe\LinkField\Models\Link>
 */
class MethodCompatibilityExtension extends Extension
{
    /**
     * Compatibility with gorriecoe\Link\Models\Link::getLinkURL()
     */
    public function getLinkURL(): ?string
    {
        return $this->getOwner()->getURL();
    }

    /**
     * Returns value for the template variable $LinkURL
     */
    public function LinkURL(): ?string
    {
        return $this->getLinkURL();
    }

    /**
     * In this extension, the phone link is returned as-is
     */
    public function getFormattedPhoneLink(): ?string
    {
        if($this->getOwner() instanceof PhoneLink) {
            return $this->getOwner()->getURL();
        }
        return null;
    }
}
