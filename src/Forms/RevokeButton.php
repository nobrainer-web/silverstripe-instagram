<?php

namespace Broarm\Instagram\Forms;

use Broarm\Instagram\Controllers\CallbackController;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Security\SecurityToken;

class RevokeButton extends LiteralField
{
    public function __construct($name, $memberId)
    {
        $label = _t(__CLASS__ . '.RevokeLabel', 'Revoke access to Instagram');
        $revokeUrl = SecurityToken::inst()->addToUrl(Controller::join_links(CallbackController::REVOKE_URL, (int) $memberId));
        $button = "<a href='{$revokeUrl}' class='btn action btn-outline-secondary'>{$label}</a>";
        parent::__construct($name, $button);
    }
}
