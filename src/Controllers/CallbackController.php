<?php

namespace Broarm\Instagram\Controllers;

use Broarm\Instagram\InstagramAuthClient;
use Broarm\Instagram\InstagramClient;
use Broarm\Instagram\Tasks\ImportMediaTasks;
use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;
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
            return $this->redirectWithMessage('token');
        }

        /** @var Member|null $member */
        $member = Member::get()->byID((int) $request->param('ID'));
        if (!$member || !($member->ID === $current->ID || Permission::check('CMS_ACCESS_SecurityAdmin'))) {
            return $this->redirectWithMessage('notallowed');
        }

        // the token never expires: also ask Meta to drop the access, so old backups or copies of the database hold nothing usable
        if ($member->InstagramAccessToken) {
            try {
                $revoked = (new InstagramClient($member->InstagramAccessToken))->revokeAccess();
                ImportMediaTasks::log('Instagram access revoked at Meta for member ' . $member->ID . ': ' . ($revoked ? 'yes' : 'no'));
            } catch (Exception $e) {
                ImportMediaTasks::log('Instagram revoke at Meta failed for member ' . $member->ID . ': ' . InstagramClient::errorSummary($e), 'warning');
            }
        }

        $member->update([
            'InstagramAccessToken' => null,
            'InstagramAccessTokenExpirers' => null,
            'InstagramID' => null,
            'InstagramUserName' => null,
            'InstagramStatus' => null,
        ]);
        $member->write();

        return $this->redirectWithMessage('revoked', self::MESSAGE_SUCCESS, $member->ID);
    }

    /**
     * Authenticate the user with the Instagram API (OAuth redirect target)
     */
    public function authenticate(): HTTPResponse
    {
        $request = $this->getRequest();
        // SS6 session cookies are SameSite=Strict: the browser leaves them out when Meta redirects here from facebook.com,
        // so the member looks logged out and the OAuth state is gone. Bounce once through a page on our own site:
        // the follow-up request is same-site and carries the session cookie.
        if (!$request->getVar('samesite')) {
            $query = array_intersect_key($request->getVars(), array_flip(['code', 'state', 'error', 'error_reason', 'error_description']));
            $query['samesite'] = 1;
            // relative: never depends on the request's Host header
            $url = Convert::raw2att(rtrim(self::REDIRECT_URL, '/') . '/?' . http_build_query($query));
            return HTTPResponse::create("<!doctype html><meta charset=\"utf-8\"><meta http-equiv=\"refresh\" content=\"0;url=$url\"><title>Instagram</title><a href=\"$url\">Continue</a>")
                ->addHeader('Content-Type', 'text/html; charset=utf-8')
                ->addHeader('Cache-Control', 'no-store')
                ->addHeader('Referrer-Policy', 'no-referrer');
        }

        $member = Security::getCurrentUser();
        if (!$member || !Permission::check('CMS_ACCESS')) {
            return Security::permissionFailure($this);
        }

        if ($request->getVar('error')) {
            // Instagram's own error text is logged, not shown (it arrives in the URL and could be crafted)
            ImportMediaTasks::log('Instagram authorization error: ' . InstagramClient::redact(mb_substr((string) ($request->getVar('error_description') ?: $request->getVar('error')), 0, 200)), 'warning');
            return $this->redirectWithMessage('denied');
        }
        // state was set to the CMS security token when building the authorize URL
        // one-time OAuth state from the session (InstagramClient::stateToken), cleared after use
        $session = $request->getSession();
        $expected = (string) $session->get(InstagramClient::STATE_SESSION_KEY);
        $session->clear(InstagramClient::STATE_SESSION_KEY);
        $state = (string) $request->getVar('state');
        if ($expected === '' || $state === '' || !hash_equals($expected, $state)) {
            return $this->redirectWithMessage('state');
        }
        $code = $request->getVar('code');
        if (!$code) {
            return $this->redirectWithMessage('nocode');
        }

        try {
            // system-user access token from the authorization code (expiry is set in the login configuration)
            $response = json_decode((new InstagramAuthClient())->getAccessToken($code)->getBody()->getContents());
            if (empty($response->access_token)) {
                return $this->redirectWithMessage('notoken');
            }
            $account = (new InstagramClient($response->access_token))->getInstagramAccount();
            if (!$account || empty($account->id)) {
                return $this->redirectWithMessage('noaccount');
            }
        } catch (Exception $e) {
            // the message can contain the request URL: log it without secrets, show a generic message
            ImportMediaTasks::log('Instagram authentication failed: ' . InstagramClient::redact($e->getMessage()), 'error');
            return $this->redirectWithMessage('failed');
        }

        $member->update([
            'InstagramAccessToken' => $response->access_token,
            'InstagramAccessTokenExpirers' => empty($response->expires_in) ? null
                : date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() + (int) $response->expires_in),
            'InstagramID' => (string) $account->id,
            'InstagramUserName' => isset($account->username) ? (string) $account->username : null,
            'InstagramStatus' => null,
        ]);

        try {
            $member->write();
            return $this->redirectWithMessage('authenticated', self::MESSAGE_SUCCESS);
        } catch (Exception $e) {
            return $this->redirectWithMessage('savefailed');
        }
    }

    protected function jsonResponse(array $data): HTTPResponse
    {
        return HTTPResponse::create(json_encode($data))->addHeader('Content-Type', 'application/json');
    }

    /**
     * Redirect to the member's edit form in the security admin, with a message
     */
    protected function redirectWithMessage(string $code, $success = self::MESSAGE_ERROR, ?int $memberID = null): HTTPResponse
    {
        // only a message code travels in the URL; the text is looked up in MemberExtension::messages()
        $current = Security::getCurrentUser();
        $memberID = $memberID ?: ($current ? $current->ID : 0);
        $code = urlencode($code);
        return $this->redirect(Director::absoluteURL("/admin/security/users/EditForm/field/users/item/{$memberID}/edit?instagram_msg=$code"));
    }
}
