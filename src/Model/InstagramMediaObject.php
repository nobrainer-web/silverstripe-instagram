<?php

namespace Broarm\Instagram\Model;

use GuzzleHttp\Client;
use SilverStripe\Assets\FileNameFilter;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\AssetAdmin\Controller\AssetAdmin;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\View\Parsers\URLSegmentFilter;

/**
 * Class InstagramMediaObject
 * @package Broarm\Instagram
 *
 * @property string Title
 * @property string InstagramID
 * @property string InstagramCaptionText
 * @property string InstagramMediaType
 * @property string InstagramImageURL
 * @property string InstagramLink
 * @property string InstagramCreated
 * @property string InstagramUserName
 */
class InstagramMediaObject extends Image
{
    private static $table_name = 'InstagramMediaObject';

    private static $db = array(
        'Title' => 'Varchar',
        'InstagramID' => 'Varchar', // id
        'InstagramCaptionText' => 'Text', // caption
        'InstagramMediaType' => 'Varchar', // media_type
        'InstagramImageURL' => 'Text', // media_url
        'InstagramLink' => 'Varchar', // permalink
        'InstagramCreated' => 'Datetime', // timestamp
        'InstagramUserName' => 'Varchar', // username
    );

    private static $default_sort = 'InstagramCreated DESC';

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        return $fields;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        // File.Title is Varchar(255); SS6 rejects longer values on write (captions can be up to 2,200 characters)
        $this->Title = mb_substr((string) $this->InstagramCaptionText, 0, 255);
    }

    /**
     * Find or make a facebook image
     *
     * @param $instagramId
     * @return DataObject|InstagramMediaObject
     */
    public static function find_or_make($instagramId)
    {
        if (!$image = InstagramMediaObject::get()->find('InstagramID', $instagramId)) {
            $image = InstagramMediaObject::create();
        }

        return $image;
    }


    /**
     * Upload images to the /instagram/OWNER_ID/ folder
     *
     * @return string
     */
    private function uploadFolder()
    {
        $filter = URLSegmentFilter::create();
        $folder = $filter->filter($this->InstagramUserName);
        return "instagram/{$folder}";
    }

    /**
     * Find the owner ID
     *
     * @return int
     */
    public function findOwnerID()
    {
        // the member who connected this account (InstagramUserID never existed: the old lookup matched any member without an Instagram ID)
        $user = $this->InstagramUserName ? Member::get()->filter('InstagramUserName', $this->InstagramUserName)->first() : null;
        if ($user) {
            return $user->ID;
        } elseif ($user = Security::getCurrentUser()) {
            return $user->ID;
        } else {
            return 0;
        }
    }

    /**
     * Download the image
     *
     * @throws \Exception
     */
    public function downloadImage()
    {
        $imageSource = (string) $this->InstagramImageURL;
        $parts = parse_url($imageSource);
        $host = strtolower($parts['host'] ?? '');
        // only images from Instagram's own CDN, over https (the URL comes from the API response)
        $allowedHost = (bool) preg_match('/(^|\.)(cdninstagram\.com|fbcdn\.net)$/', $host);
        $fileName = basename($parts['path'] ?? '');
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (($parts['scheme'] ?? '') !== 'https' || !$allowedHost || !in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new \Exception('Refused to download media (not an https image on the Instagram CDN): ' . $host . '/' . $fileName);
        }

        $client = new Client(['http_errors' => false, 'timeout' => 30, 'allow_redirects' => false]);
        $response = $client->request('GET', $imageSource);
        if ($response->getStatusCode() !== 200 || stripos($response->getHeaderLine('Content-Type'), 'image/') !== 0) {
            throw new \Exception('Error while downloading media from ' . $host . ' (HTTP ' . $response->getStatusCode() . ')');
        }

        $folder = Folder::find_or_make($this->uploadFolder());
        $this->setFromStream($response->getBody()->detach(), $fileName);
        $this->ParentID = $folder->ID;
        $this->OwnerID = $this->findOwnerID();
    }

    /**
     * Generate thumbnails for use in the CMS
     */
    public function generateThumbnails()
    {
        $assetAdmin = AssetAdmin::singleton();
        $this->FitMax(
            $assetAdmin->config()->get('thumbnail_width'),
            $assetAdmin->config()->get('thumbnail_height')
        );
        $this->FitMax(
            UploadField::config()->uninherited('thumbnail_width'),
            UploadField::config()->uninherited('thumbnail_height')
        );
    }
}
