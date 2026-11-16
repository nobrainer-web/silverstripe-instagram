<?php

namespace Broarm\Instagram\Controllers;

use Broarm\Instagram\InstagramAuthClient;
use Broarm\Instagram\InstagramClient;
use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;

/**
 * Class CallbackController
 * @package Broarm\Silverstripe\Instagram
 */
class CallbackController extends Controller
{
    const REDIRECT_URL = '/instagram/authenticate';
    const REVOKE_URL = '/instagram/revoke';
    const MESSAGE_SUCCESS = 1;
    const MESSAGE_ERROR = 0;

    private static $url_handlers = [
        'revoke/$ID' => 'revoke',
    ];

    private static $allowed_actions = [
        'authenticate',
        'revoke',
        'deauthorize',
        'deletedata',
    ];

    /**
     * Meta's "deauthorize" callback (app settings). The member's token stops working by itself.
     * Acknowledge without echoing the request (the old version printed the whole request to anyone).
     */
    public function deauthorize(HTTPRequest $request): HTTPResponse
    {
        return $this->jsonResponse([]);
    }

    /**
     * Meta's "data deletion" callback (app settings). Imported posts are site content and are removed by an editor.
     */
    public function deletedata(HTTPRequest $request): HTTPResponse
    {
        return $this->jsonResponse([]);
    }

    /**
     * Disconnect the Instagram account of a member: only that member or someone who may edit members
     */
    public function revoke(HTTPRequest $request): HTTPResponse
    {
        $current = Security::getCurrentUser();
        if (!$current) {
            return Security::permissionFailure($this);
        }
        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->redirectWithMessage('Invalid security token, please try again');
        }

        /** @var Member|null $member */
        $member = Member::get()->byID((int) $request->param('ID'));
        if (!$member || !($member->ID === $current->ID || Permission::check('CMS_ACCESS_SecurityAdmin'))) {
            return $this->redirectWithMessage('Not allowed to revoke this account');
        }

        $member->update([
            'InstagramAccessToken' => null,
            'InstagramAccessTokenExpirers' => null,
            'InstagramID' => null,
            'InstagramUserName' => null,
        ]);
        $member->write();

        return $this->redirectWithMessage('Instagram access revoked', self::MESSAGE_SUCCESS, $member->ID);
    }

    /**
     * Authenticate the user with the Instagram API (OAuth redirect target)
     */
    public function authenticate(): HTTPResponse
    {
        $member = Security::getCurrentUser();
        if (!$member || !Permission::check('CMS_ACCESS')) {
            return Security::permissionFailure($this);
        }

        $request = $this->getRequest();
        if ($request->getVar('error')) {
            return $this->redirectWithMessage((string) ($request->getVar('error_description') ?: $request->getVar('error')));
        }
        // state was set to the CMS security token when building the authorize URL
        if (!hash_equals((string) SecurityToken::inst()->getValue(), (string) $request->getVar('state'))) {
            return $this->redirectWithMessage('Invalid state, please try again');
        }
        $code = $request->getVar('code');
        if (!$code) {
            return $this->redirectWithMessage('Did not receive a valid auth token');
        }

        try {
            // short lived token from the authorization code
            $response = json_decode((new InstagramAuthClient())->getAccessToken($code)->getBody()->getContents());
            if (empty($response->access_token) || empty($response->user_id)) {
                return $this->redirectWithMessage('Could not receive token from auth response');
            }
            $userID = $response->user_id;

            // exchange it for a long lived token (60 days) that the import refreshes
            $response = json_decode((new InstagramClient($response->access_token))
                ->getLongLivedAccessToken()->getBody()->getContents());
            if (empty($response->access_token) || empty($response->expires_in)) {
                return $this->redirectWithMessage('Could not read long lived access token from response');
            }
        } catch (Exception $e) {
            return $this->redirectWithMessage($e->getMessage());
        }

        $member->update([
            'InstagramAccessToken' => $response->access_token,
            'InstagramAccessTokenExpirers' => date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() + (int) $response->expires_in),
            'InstagramID' => $userID,
        ]);

        try {
            $member->write();
            return $this->redirectWithMessage('Authenticated Instagram', self::MESSAGE_SUCCESS);
        } catch (Exception $e) {
            return $this->redirectWithMessage('Failed to save member');
        }
    }

    protected function jsonResponse(array $data): HTTPResponse
    {
        return HTTPResponse::create(json_encode($data))->addHeader('Content-Type', 'application/json');
    }

    /**
     * Redirect to the member's edit form in the security admin, with a message
     */
    protected function redirectWithMessage($message, $success = self::MESSAGE_ERROR, ?int $memberID = null): HTTPResponse
    {
        $current = Security::getCurrentUser();
        $memberID = $memberID ?: ($current ? $current->ID : 0);
        $message = urlencode($message);
        return $this->redirect(Director::absoluteURL("/admin/security/users/EditForm/field/users/item/{$memberID}/edit?instagram_success=$success&instagram_message=$message"));
    }
}
