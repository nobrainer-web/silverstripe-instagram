<?php

namespace Broarm\Instagram\Extensions;

use Broarm\Instagram\Instagram;
use Broarm\Instagram\InstagramClient;
use GuzzleHttp\Exception\RequestException;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\FormAction;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextField;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use Broarm\Instagram\Forms\RevokeButton;
use Broarm\Instagram\Forms\AccountInformationField;
use Broarm\Instagram\Forms\AuthButton;
use Exception;

/**
 * Class MemberExtension
 *
 * @property string InstagramAccessToken
 * @property string InstagramAccessTokenExpirers
 * @property string InstagramID
 * @property string InstagramUserName
 *
 * @property Member|MemberExtension owner
 */
class MemberExtension extends Extension
{
    private static $db = [
        'InstagramAccessToken' => 'Text', // long-lived tokens have no documented maximum length
        'InstagramAccessTokenExpirers' => 'Datetime',
        'InstagramID' => 'Varchar',
        'InstagramUserName' => 'Varchar',
    ];

    protected function updateCMSFields(FieldList $fields)
    {
        $fields->removeByName([
            'InstagramAccessToken',
            'InstagramAccessTokenExpirers',
            'InstagramID',
            'InstagramUserName',
            'InstagramProfilePicture',
            'InstagramFullName'
        ]);

        // Render user feedback
        if (($controller = Controller::curr()) && $request = $controller->getRequest()) {
            if (($success = $request->getVar('instagram_success')) !== null && $message = $request->getVar('instagram_message')) {
                $messageClass = $success ? 'good' : 'bad';
                $message = Convert::raw2xml(urldecode($message));
                $fields->addFieldsToTab('Root.Instagram', [
                    LiteralField::create('InstagramMessage', "<p class='message $messageClass'>$message</p>")
                ]);
            }
        }

        if (!$this->getOwner()->InstagramAccessToken) {
            $fields->addFieldsToTab('Root.Instagram', [
                AuthButton::create('InstagramAuthButton'),
                TextField::create('InstagramAccessToken', _t(__CLASS__ . '.InstagramAccessToken', 'Or add a generated acccess token'))
                    ->setDescription(_t(
                        __CLASS__ . 'InstagramAccessTokenDescription',
                        'You can generate access tokens for test users of your app. <a href="{read_more_link}" target="_blank">more information</a>',
                        null,
                        ['read_more_link' => 'https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login']
                    ))
            ]);
        } else {
            $fields->addFieldsToTab('Root.Instagram', array(
                ReadonlyField::create('InstagramUserName', _t(__CLASS__ . '.InstagramUserName', 'Connected to IG account')),
                RevokeButton::create('InstagramRevokeButton', $this->getOwner()->ID)
            ));
        }
    }

    protected function onBeforeWrite()
    {
        // update user date here to also support set generated tokens
        if ($this->getOwner()->isChanged('InstagramAccessToken') && !empty($this->getOwner()->InstagramAccessToken)) {
            try {
                $client = new InstagramClient($this->getOwner()->InstagramAccessToken);
                $response = $client->getUser();
                $user = json_decode($response->getBody()->getContents());
                if (!empty($user)) {
                    // SS6 validates Varchar fields: the API returns the id as a number
                    $id = $user->user_id ?? $user->id ?? null;
                    $this->getOwner()->InstagramID = $id === null ? null : (string) $id;
                    $this->getOwner()->InstagramUserName = isset($user->username) ? (string) $user->username : null;
                }
            } catch (Exception $e) {
                // soft error ?
            } catch (RequestException $e) {
                // soft error ?
            }
        }
    }
}