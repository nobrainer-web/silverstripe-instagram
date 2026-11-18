<?php

namespace Broarm\Instagram\Extensions;

use Broarm\Instagram\InstagramClient;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;
use SilverStripe\Control\Director;
use SilverStripe\Forms\FieldList;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Core\Environment;
use SilverStripe\Forms\PasswordField;
use SilverStripe\Forms\TextField;
use Broarm\Instagram\Instagram;

/**
 * Class SiteConfigExtension
 * @package Broarm\Silverstripe\Instagram
 *
 * @property string InstagramAppID
 * @property string InstagramAppSecret
 * @property |SiteConfig|InstagramAuthenticator owner
 */
class SiteConfigExtension extends Extension
{
    private static $db = [
        'InstagramAppID' => 'Varchar',
        'InstagramAppSecret' => 'Varchar',
        'InstagramConfigID' => 'Varchar',
    ];

    protected function updateCMSFields(FieldList $fields)
    {
        // Check if the App ID/Secret already exists or give the user a option to set it trough the CMS
        // The App ID/Secret can also be set trough the config.yml
        // only when not set in .env or config (then the CMS value isn't used anyway)
        if (!Environment::getEnv('INSTAGRAM_APP_ID') && !InstagramClient::config()->get('app_id')) {
            $fields->addFieldToTab(
                'Root.Instagram',
                TextField::create('InstagramAppID', _t(__CLASS__ . '.InstagramAppID', 'Meta app ID'))
                    ->setDescription(_t(
                        __CLASS__ . '.InstagramAppIDDescription',
                        'The app ID at the top of your app on developers.facebook.com'
                    ))
            );
        }

        if (!Environment::getEnv('INSTAGRAM_APP_SECRET') && !InstagramClient::config()->get('app_secret')) {
            $fields->addFieldToTab(
                'Root.Instagram',
                // write-only: the stored secret is never sent back to the browser
                PasswordField::create('InstagramAppSecret', _t(__CLASS__ . '.InstagramAppSecret', 'Meta app secret'))
                    ->setDescription(_t(
                        __CLASS__ . '.InstagramAppSecretDescription',
                        'App settings > Basic > App secret on developers.facebook.com'
                    ))
            );
        }

        if (!Environment::getEnv('INSTAGRAM_CONFIG_ID') && !InstagramClient::config()->get('config_id')) {
            $fields->addFieldToTab(
                'Root.Instagram',
                TextField::create('InstagramConfigID', _t(__CLASS__ . '.InstagramConfigID', 'Login configuration ID'))
                    ->setDescription(_t(
                        __CLASS__ . '.InstagramConfigIDDescription',
                        'Facebook Login for Business > Configurations (system-user token, asset Instagram accounts, permission instagram_basic)'
                    ))
            );
        }
    }

    /**
     * The secret field is write-only (PasswordField, empty on load): saving Settings without typing it keeps the stored secret
     */
    protected function onBeforeWrite()
    {
        $owner = $this->getOwner();
        if ($owner->isChanged('InstagramAppSecret') && (string) $owner->InstagramAppSecret === '') {
            $original = $owner->getChangedFields(['InstagramAppSecret'])['InstagramAppSecret']['before'] ?? null;
            if ($original) {
                $owner->InstagramAppSecret = $original;
            }
        }
    }
}
