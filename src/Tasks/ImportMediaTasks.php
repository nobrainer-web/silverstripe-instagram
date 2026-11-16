<?php

namespace Broarm\Instagram\Tasks;

use Broarm\Instagram\Extensions\MemberExtension;
use Broarm\Instagram\InstagramClient;
use Broarm\Instagram\Model\InstagramMediaObject;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * The facebook import images task
 * requires the existence of the FacebookImage class for downloading the image
 *
 * Class ImportFacebookPhotosTasks
 */
class ImportMediaTasks extends BuildTask
{
    protected string $title = 'Import Instagram media';

    protected static string $description = 'Refresh each connected member\'s token and import their latest Instagram media';

    // keep the SS4/SS5 task URL (dev/tasks/Broarm-Instagram-Tasks-ImportMediaTasks)
    protected static string $commandName = 'Broarm-Instagram-Tasks-ImportMediaTasks';

    private static $data_mapping = [
        'id' => 'InstagramID',
        'caption' => 'InstagramCaptionText',
        'media_type' => 'InstagramMediaType',
        'media_url' => 'InstagramImageURL',
        'thumbnail_url' => 'InstagramImageURL',
        'permalink' => 'InstagramLink',
        'timestamp' => 'InstagramCreated',
        'username' => 'InstagramUserName',
    ];

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        /** @var Member|MemberExtension $member */
        foreach (InstagramClient::getAuthenticatedMembers() as $member) {
            self::log("Import Instagram post for {$member->getName()}");
            try {
                // Refresh the access token; keep the old one unless a new one arrives
                // (the old version wrote an empty token on any failed refresh and lost the connection)
                $client = new InstagramClient($member->InstagramAccessToken);
                $token = json_decode($client->getRefreshLongLivedAcctionToken()->getBody()->getContents());
                if (!empty($token->access_token)) {
                    $member->InstagramAccessToken = $token->access_token;
                    if (!empty($token->expires_in)) {
                        $member->InstagramAccessTokenExpirers = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() + (int) $token->expires_in);
                    }
                    $member->write();
                } else {
                    self::log("Token refresh for {$member->getName()} returned no token, keeping the current one", 'warning');
                }

                $client = new InstagramClient($member->InstagramAccessToken);
                $response = json_decode($client->getUserMedia()->getBody()->getContents());
            } catch (Throwable $e) {
                self::log("Instagram request for {$member->getName()} failed: " . InstagramClient::redact($e->getMessage()), 'error');
                $output->writeln("<error>Instagram request for {$member->getName()} failed</error>");
                continue;
            }

            if ($response && property_exists($response, 'data')) {
                foreach ($response->data as $mediaObject) {
                    $obj = self::handleObject($mediaObject);
                    self::log("Created instagram media obj with ID {$obj->ID} from source {$obj->InstagramID}");
                }
            }
        }

        $output->writeln('Done');
        return Command::SUCCESS;
    }

    /**
     * Handle the image data
     *
     * @param stdClass|array $data
     * @return DataObject|InstagramMediaObject
     */
    private static function handleObject($data) {
        // Find or make a facebook image object from the given facebook id
        $mediaObject = InstagramMediaObject::find_or_make($data->id);

        // Loop over the data and set like the mapping
        self::loopMap($mediaObject, $data, self::config()->get('data_mapping'));

        try {
            if ($mediaObject->isChanged('InstagramImageURL', DataObject::CHANGE_VALUE)) {
                $mediaObject->downloadImage();
            }

            if ($mediaObject->isChanged()) {
                $mediaObject->write();
                $mediaObject->generateThumbnails();
            }

            if (!$mediaObject->isLiveVersion()) {
                $mediaObject->publishSingle();
            }
        } catch (\Exception $e) {
            self::log(InstagramClient::redact($e->getMessage()), 'error');
        }

        return $mediaObject;
    }


    /**
     * Loop over the data map and set accordingly
     *
     * @param InstagramMediaObject $mediaObject
     * @param $dataSet
     * @param $map
     */
    private static function loopMap(InstagramMediaObject $mediaObject, $dataSet, $map)
    {
        foreach ($map as $from => $to) {
            if (is_array($to) && property_exists($dataSet, $from)) {
                self::loopMap($mediaObject, $dataSet->$from, $to);
            } elseif (property_exists($dataSet, $from)) {
                self::log("Set $to => {$dataSet->$from}");
                $mediaObject->setField((string)$to, $dataSet->$from);
            }
        }
    }
    
    public static function log($message, $level = 'info')
    {
        Injector::inst()->get(LoggerInterface::class)->$level($message);
    }
}
