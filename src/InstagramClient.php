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
use SilverStripe\Security\SecurityToken;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Class InstagramClient
 * @package Broarm\Instagram
 */
class InstagramClient
{
    use Configurable;

    // Instagram API with Instagram Login (the Basic Display API was shut down by Meta on 2024-12-04)
    const API_URL = 'https://graph.instagram.com/';

    const AUTHORIZE_URL = 'https://www.instagram.com/oauth/authorize';

    private static $api_version = 'v20.0';

    private static $app_id = null;

    private static $app_secret = null;

    private static $scope = [
        'instagram_business_basic',
    ];

    private static $user_fields = [
        'user_id',
        'username'
    ];

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

        $handler = new HandlerStack();
        $handler->setHandler(new CurlHandler());
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
     * Remove tokens, secrets and codes from a message (Guzzle exceptions contain the request URL)
     */
    public static function redact(string $message): string
    {
        return preg_replace('/((?:access_token|client_secret|code|fb_exchange_token)=)[^&\s"\']+/i', '$1***', $message);
    }

    /**
     * Exchange the short lived access token for a long lived access token
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getLongLivedAccessToken()
    {
        if (!$appSecret = self::getAppSecret()) {
            throw new \RuntimeException('No Instagram app secret configured (INSTAGRAM_APP_SECRET in .env, or config)');
        }
        return $this->client->get(self::API_URL . 'access_token', [
            'query' => [
                'grant_type'=> 'ig_exchange_token',
                'client_secret' => $appSecret,
            ]
        ]);
    }

    /**
     * Refresh the in use access token
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getRefreshLongLivedAcctionToken()
    {
        return $this->client->get(self::API_URL . 'refresh_access_token', [
            'query' => [
                'grant_type'=> 'ig_refresh_token'
            ]
        ]);
    }

    /**
     * Get the user info for the user connected to the token or given id
     *
     * @param string $id
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getUser($id = 'me')
    {
        return $this->client->get($id, [
            'query' => [
                'fields'=> implode(',', self::config()->get('user_fields'))
            ]
        ]);
    }

    /**
     * Get the media for the current user or given user id
     *
     * @param string $id
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getUserMedia($id = 'me')
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
     * Create the url for authenicating users
     *
     * @return string
     */
    public static function getAuthenticationURL()
    {
        $query = [
            'client_id' => self::getAppID(),
            'redirect_uri' => Director::absoluteURL(CallbackController::REDIRECT_URL),
            'scope' => implode(',', self::config()->get('scope')),
            'response_type' => self::config()->get('response_type'),
            // checked in CallbackController::authenticate() (OAuth CSRF protection)
            'state' => SecurityToken::inst()->getValue(),
        ];

        return self::AUTHORIZE_URL . '?' . http_build_query($query);
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