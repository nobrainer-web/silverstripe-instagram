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
 * @property string InstagramStatus
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
        // result of the last import, shown on the Instagram tab (e.g. a lost connection)
        'InstagramStatus' => 'Varchar(255)',
    ];

    protected function updateCMSFields(FieldList $fields)
    {
        $fields->removeByName([
            'InstagramAccessToken',
            'InstagramAccessTokenExpirers',
            'InstagramID',
            'InstagramUserName',
            'InstagramStatus',
            'InstagramProfilePicture',
            'InstagramFullName'
        ]);

        // Render user feedback
        if (($controller = Controller::curr()) && $request = $controller->getRequest()) {
            $messages = self::messages();
            $code = (string) $request->getVar('instagram_msg');
            if (isset($messages[$code])) {
                [$text, $success] = $messages[$code];
                $messageClass = $success ? 'good' : 'bad';
                $message = Convert::raw2xml($text);
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
                        'Generated in your Meta app under Use cases > Instagram API > API setup with Unified login > Generate access tokens (Instagram testers only). <a href="{read_more_link}" target="_blank" rel="noopener noreferrer">More information</a>',
                        null,
                        ['read_more_link' => 'https://developers.facebook.com/documentation/instagram-platform/instagram-api-with-unified-login/get-started']
                    ))
            ]);
        } else {
            $fields->addFieldsToTab('Root.Instagram', array(
                ReadonlyField::create('InstagramUserName', _t(__CLASS__ . '.InstagramUserName', 'Connected to IG account')),
                ReadonlyField::create('InstagramStatus', _t(__CLASS__ . '.InstagramStatus', 'Last import'))
                    ->setValue($this->getOwner()->InstagramStatus ?: _t(__CLASS__ . '.InstagramStatusNone', 'Not run yet')),
                RevokeButton::create('InstagramRevokeButton', $this->getOwner()->ID)
            ));
        }
    }

    /**
     * Messages after connecting/revoking: the URL carries only the code, so nobody can put their own text in the CMS
     *
     * @return array code => [text, success]
     */
    public static function messages(): array
    {
        return [
            'authenticated' => [_t(__CLASS__ . '.MsgAuthenticated', 'Instagram connected'), true],
            'revoked' => [_t(__CLASS__ . '.MsgRevoked', 'Instagram access revoked'), true],
            'token' => [_t(__CLASS__ . '.MsgToken', 'Invalid security token, please try again'), false],
            'notallowed' => [_t(__CLASS__ . '.MsgNotAllowed', 'Not allowed to revoke this account'), false],
            'denied' => [_t(__CLASS__ . '.MsgDenied', 'Instagram did not grant access (details in the error log)'), false],
            'state' => [_t(__CLASS__ . '.MsgState', 'The request expired or was not started here, please try again'), false],
            'nocode' => [_t(__CLASS__ . '.MsgNoCode', 'Did not receive a valid auth code'), false],
            'notoken' => [_t(__CLASS__ . '.MsgNoToken', 'Could not get an access token from Instagram'), false],
            'noaccount' => [_t(__CLASS__ . '.MsgNoAccount', 'No Instagram account was shared with the site. Connect again and select your Instagram account'), false],
            'failed' => [_t(__CLASS__ . '.MsgFailed', 'Instagram authentication failed, see the error log'), false],
            'savefailed' => [_t(__CLASS__ . '.MsgSaveFailed', 'Failed to save the member'), false],
        ];
    }

    protected function onBeforeWrite()
    {
        // update user date here to also support set generated tokens
        if ($this->getOwner()->isChanged('InstagramAccessToken') && !empty($this->getOwner()->InstagramAccessToken)) {
            try {
                $account = (new InstagramClient($this->getOwner()->InstagramAccessToken))->getInstagramAccount();
                if ($account) {
                    // SS6 validates Varchar fields: the API returns the id as a number
                    $this->getOwner()->InstagramID = isset($account->id) ? (string) $account->id : null;
                    $this->getOwner()->InstagramUserName = isset($account->username) ? (string) $account->username : null;
                }
            } catch (Exception $e) {
                // soft error ?
            } catch (RequestException $e) {
                // soft error ?
            }
        }
    }
}