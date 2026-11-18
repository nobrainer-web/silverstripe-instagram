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

    protected static string $description = 'Import the latest Instagram media of each connected member';

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
                // Unified login: the system-user token doesn't need refreshing (the login configuration sets "never expires")
                $client = new InstagramClient($member->InstagramAccessToken);
                if (!$member->InstagramID) {
                    $account = $client->getInstagramAccount();
                    if (!$account || empty($account->id)) {
                        throw new \RuntimeException('The token has no Instagram account assigned');
                    }
                    $member->InstagramID = (string) $account->id;
                    $member->write();
                }
                $response = json_decode($client->getUserMedia($member->InstagramID)->getBody()->getContents());
            } catch (Throwable $e) {
                // no retry: the next run is tomorrow (Meta asks to stop calling when a limit is hit)
                $error = InstagramClient::errorSummary($e);
                self::log("Instagram request for {$member->getName()} failed: $error", 'error');
                $output->writeln("<error>Instagram request for {$member->getName()} failed</error>");
                $hint = str_starts_with($error, '190:') ? ' The connection no longer works: connect again.' : '';
                self::setStatus($member, 'Failed on ' . DBDatetime::now()->Nice() . " ($error).$hint");
                continue;
            }

            $count = 0;
            if ($response && property_exists($response, 'data')) {
                foreach ($response->data as $mediaObject) {
                    $obj = self::handleObject($mediaObject);
                    $count++;
                    self::log("Created instagram media obj with ID {$obj->ID} from source {$obj->InstagramID}");
                }
            }
            self::setStatus($member, 'OK on ' . DBDatetime::now()->Nice() . " ($count posts checked)");
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
    
    private static function setStatus(Member $member, string $status): void
    {
        $member->InstagramStatus = mb_substr($status, 0, 255);
        try {
            $member->write();
        } catch (Throwable $e) {
            self::log('Could not save the Instagram status: ' . InstagramClient::redact($e->getMessage()), 'warning');
        }
    }

    public static function log($message, $level = 'info')
    {
        Injector::inst()->get(LoggerInterface::class)->$level($message);
    }
}
