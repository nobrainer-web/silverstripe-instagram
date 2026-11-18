# Silverstripe Instagram

Show the posts of an Instagram professional account on a Silverstripe 6 site. Uses the
[Instagram API with Unified Login](https://developers.facebook.com/documentation/instagram-platform/instagram-api-with-unified-login/get-started)
(read only: permission `instagram_basic`).

- A CMS user connects an Instagram account once, on the **Instagram** tab of their member record (Security admin).
- A task imports the account's latest posts (single images) into `Broarm\Instagram\Model\InstagramMediaObject` (stored as images in Files).
- The token never expires (system-user token), so no refresh is needed.

Meta closed the Basic Display API (2024-12-04), and new Meta apps can no longer use "Instagram Login"; Unified Login is the route for new apps.

## Installation

```bash
composer require nobrainer-web/silverstripe-instagram
```

## Meta app setup (once per site)

1. A Meta app owned by a **business portfolio** (business.facebook.com), with the use case *Manage messaging & content on Instagram*.
2. *Facebook Login for Business > Configurations > Create configuration*: login variation **General**, **System-user access token** (never expires), asset **Instagram accounts** (required), permission **`instagram_basic`** only. Copy the configuration ID.
   (The ready-made template asks for ads, messaging and publishing too; avoid it.)
3. *Use cases > Customize > API setup with Unified login*: select the configuration (it must say "Instagram Login Compatible") and register the redirect URL `https://<your-site>/instagram/authenticate/` (with the trailing slash).
4. *App roles > Roles*: add the Instagram account as **Instagram Tester**; the account accepts it in Instagram under *Settings > Apps and websites > Tester invites*.
   In Development mode only accounts with a role on the app can connect; that's enough for a site that shows its own account. Other accounts need App Review and Live mode.
5. *App settings > Basic*: fill in a privacy policy URL, a data deletion URL, an icon and a contact email (Meta's Platform Terms require a privacy policy).

## Configuration

Put the keys in `.env` (preferred; never commit the secret):

```
INSTAGRAM_APP_ID="<Meta app ID>"
INSTAGRAM_APP_SECRET="<Meta app secret>"
INSTAGRAM_CONFIG_ID="<login configuration ID>"
```

Or in YAML (`Broarm\Instagram\InstagramClient`: `app_id`, `app_secret`, `config_id`), or in the CMS under *Settings > Instagram* (the fields only show when the value isn't set in `.env` or YAML; the secret field is write-only).

## Connect

Security admin > a member > tab **Instagram** > **Authenticate Instagram** > log in **with Instagram** (or Facebook) > pick the business portfolio and the Instagram account > Allow.
Meta creates a business portfolio for the account during this login if it has none.

Alternatively paste a token generated in the app (*API setup with Unified login > Generate access tokens*, testers only).

## Import

Run once a day, e.g. by cron:

```bash
vendor/bin/sake tasks:Broarm-Instagram-Tasks-ImportMediaTasks
```

The tab shows the result of the last import ("Last import"). If it reports error `190`, the connection no longer works
(the app was removed under *Connected apps* in the account's business portfolio, or the Meta app became inactive after 90 days without use): connect again.

The daily import also keeps the Meta app active.

## Endpoints

| URL | Purpose |
|---|---|
| `/instagram/authenticate/` | OAuth redirect target (CMS login required). Bounces once through a same-site page, because SS6 session cookies are `SameSite=Strict` and are not sent on the redirect back from Meta. |
| `/instagram/revoke/<memberID>` | Disconnect (CMS login + security token; own member or security admin) |
| `/instagram/deauthorize/`, `/instagram/deletedata/` | Meta callbacks; acknowledge with `[]` |

## API

```php
$client = new \Broarm\Instagram\InstagramClient($member->InstagramAccessToken);
$account = $client->getInstagramAccount();          // id + username of the connected account
$client->getUserMedia($member->InstagramID);         // latest media
$client->getMedia($mediaId);                         // one media item
```
