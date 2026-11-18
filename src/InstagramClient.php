<?php

namespace Broarm\Instagram;

use Broarm\Instagram\Controllers\CallbackController;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Member;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Class InstagramClient
 * @package Broarm\Instagram
 */
class InstagramClient
{
    use Configurable;

    // Instagram API with Unified Login (Facebook Login for Business). New Meta apps can't use "Instagram Login" any more,
    // and the Basic Display API was shut down by Meta on 2024-12-04.
    const API_URL = 'https://graph.facebook.com/';

    const AUTHORIZE_URL = 'https://www.facebook.com/dialog/oauth';

    const STATE_SESSION_KEY = 'InstagramOAuthState';

    private static $api_version = 'v27.0';

    private static $app_id = null;

    private static $app_secret = null;

    // the login configuration (Facebook Login for Business → Configurations) decides the permissions (instagram_basic)
    private static $config_id = null;

    private static $response_type = 'code';

    /**
     * @var Client
     */
    protected $client;

    /**
     * Construct a client for a given access token
     * Access tokens are stored on members, so multiple accounts van be added to your site.
     *
     * @param $accessToken
     */
    public function __construct($accessToken)
    {
        // only the access token travels with every request; the app secret is sent only where Meta needs it
        // (token exchange), so it can't end up in logs or error messages of ordinary API calls
        $defaultQuery = [
            'access_token' => $accessToken
        ];

        // HandlerStack::create() keeps Guzzle's default middleware (http_errors): Meta's error responses throw
        // instead of passing as an empty result (an empty stack made a dead token look like "0 posts")
        $handler = HandlerStack::create(new CurlHandler());
        $handler->unshift(Middleware::mapRequest(function(RequestInterface $request) use ($defaultQuery) {
            $uri = $request->getUri();
            foreach ($defaultQuery as $key => $value) {
                $uri = Uri::withQueryValue($uri, $key, $value);
            }
            return $request->withUri($uri);
        }));

        $this->client = new Client([
            'base_uri' => self::API_URL . self::config()->get('api_version') . '/',
            'handler' => $handler
        ]);
    }

    /**
     * Meta's error for a failed request as "code: message" (redacted), or the exception message.
     * Code 190 = the token no longer works (app removed in the client's business portfolio, app inactive): connect again.
     */
    public static function errorSummary(\Throwable $e): string
    {
        if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
            $body = json_decode((string) $e->getResponse()->getBody());
            if (isset($body->error->code)) {
                return self::redact((int) $body->error->code . ': ' . mb_substr((string) ($body->error->message ?? ''), 0, 200));
            }
        }
        return self::redact(mb_substr($e->getMessage(), 0, 300));
    }

    /**
     * Remove tokens, secrets and codes from a message (Guzzle exceptions contain the request URL)
     */
    public static function redact(string $message): string
    {
        return preg_replace('/((?:access_token|client_secret|code|fb_exchange_token)=)[^&\s"\']+/i', '$1***', $message);
    }

    /**
     * Get the user info for the user connected to the token or given id
     *
     * @param string $id
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getUser()
    {
        // the token belongs to a business system user, not to the Instagram account: list the accounts it was given
        return $this->client->get('me/assigned_instagram_accounts', [
            'query' => [
                'fields' => 'id,username'
            ]
        ]);
    }

    /**
     * The (first) Instagram account the token has access to, or null
     */
    public function getInstagramAccount(): ?\stdClass
    {
        $response = json_decode($this->getUser()->getBody()->getContents());
        return $response->data[0] ?? null;
    }

    /**
     * Get the media for the current user or given user id
     *
     * @param string $id
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getUserMedia(string $id)
    {
        return $this->client->get("$id/media", [
            'query' => [
                'fields' => implode(',', [
                    'caption',
                    'id',
                    'media_type',
                    'media_url',
                    'permalink',
                    'thumbnail_url',
                    'timestamp',
                    'username',
                ])
            ]
        ]);
    }

    public function getMedia($id)
    {
        return $this->client->get($id, [
            'query' => [
                'fields' => implode(',', [
                    'caption',
                    'id',
                    'media_type',
                    'media_url',
                    'permalink',
                    'thumbnail_url',
                    'timestamp',
                    'username',
                ])
            ]
        ]);
    }

    /**
     * Get the app id form the config or database
     *
     * @return string
     */
    public static function getAppID()
    {
        // .env first (keeps the key out of the repository), then config, then the CMS field
        return Environment::getEnv('INSTAGRAM_APP_ID') ?: self::config()->get('app_id') ?: SiteConfig::current_site_config()->InstagramAppID;
    }

    /**
     * Get the app secret form the config or database
     *
     * @return string
     */
    public static function getAppSecret()
    {
        return Environment::getEnv('INSTAGRAM_APP_SECRET') ?: self::config()->get('app_secret') ?: SiteConfig::current_site_config()->InstagramAppSecret;
    }

    /**
     * The login configuration ID (Facebook Login for Business)
     */
    public static function getConfigID()
    {
        return Environment::getEnv('INSTAGRAM_CONFIG_ID') ?: self::config()->get('config_id') ?: SiteConfig::current_site_config()->InstagramConfigID;
    }

    /**
     * Create the url for authenicating users
     *
     * @return string
     */
    public static function getAuthenticationURL()
    {
        $query = [
            'client_id' => self::getAppID(),
            'redirect_uri' => Director::absoluteURL(CallbackController::REDIRECT_URL),
            'config_id' => self::getConfigID(),
            'response_type' => self::config()->get('response_type'),
            // checked in CallbackController::authenticate() (OAuth CSRF protection)
            'state' => self::stateToken(),
        ];

        return self::AUTHORIZE_URL . '?' . http_build_query($query);
    }

    /**
     * The OAuth state: a random value kept in the session until the callback. Outside a web request (CLI, tests) there is no session;
     * the URL is then built without a state, and CallbackController::authenticate() rejects it.
     */
    protected static function stateToken(): string
    {
        // a separate one-time value (not the session's CSRF token: the state comes back in the URL and ends up in logs)
        try {
            $session = Controller::curr()->getRequest()->getSession();
        } catch (\Throwable $e) {
            return '';
        }
        $state = $session->get(self::STATE_SESSION_KEY);
        if (!$state) {
            $state = bin2hex(random_bytes(16));
            $session->set(self::STATE_SESSION_KEY, $state);
        }
        return $state;
    }

    /**
     * Get a list of authenticated members
     *
     * @return DataList
     */
    public static function getAuthenticatedMembers()
    {
        return Member::get()->filter(array('InstagramAccessToken:not' => null));
    }
}