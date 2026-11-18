<?php

namespace Broarm\Instagram;

use Broarm\Instagram\Controllers\CallbackController;
use GuzzleHttp\Client;
use RuntimeException;
use SilverStripe\Control\Director;

class InstagramAuthClient
{
    const AUTH_URL = 'https://graph.facebook.com/';

    /**
     * @var Client
     */
    protected $client;

    /**
     * @return Client
     */
    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => self::AUTH_URL . InstagramClient::config()->get('api_version') . '/',
        ]);
    }

    /**
     * Get an access token from the user auth token
     *
     * @param string $code
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getAccessToken($code)
    {
        if (!$appId = InstagramClient::getAppID()) {
            throw new RuntimeException('No app id configured, set this in your config under Broarm\Instagram\InstagramClient.app_id of in the DB trough site config');
        }

        if (!$appSecret = InstagramClient::getAppSecret()) {
            throw new RuntimeException('No app secret configured, set this in your config under Broarm\Instagram\InstagramClient.app_secret of in the DB trough site config');
        }

        // Meta app ID and secret (Unified login; the old Instagram Login used a separate Instagram app ID/secret)
        return $this->client->get('oauth/access_token', [
            'query' => [
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'redirect_uri' => Director::absoluteURL(CallbackController::REDIRECT_URL),
                'code' => $code
            ]
        ]);
    }
}