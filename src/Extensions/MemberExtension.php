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
            'failed' => [_t(__CLASS__ . '.MsgFailed', 'Instagram authentication failed, see the error log'), false],
            'savefailed' => [_t(__CLASS__ . '.MsgSaveFailed', 'Failed to save the member'), false],
        ];
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