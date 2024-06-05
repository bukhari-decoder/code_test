<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use InvalidArgumentException;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    public function __construct(Job $model, MailerInterface $mailer, LoggerInterface $logger)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = $logger;

        $this->initializeLogger();
    }

    private function initializeLogger(): void
    {
        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * Get user's jobs based on their user type.
     *
     * @param int $userId
     * @return array
     */
    public function getUsersJobs(int $userId): array
    {
        $currentUser = User::find($userId);

        if (!$currentUser) {
            return [
                'emergencyJobs' => [],
                'normalJobs' => [],
                'currentUser' => null,
                'userType' => ''
            ];
        }

        $userType = $currentUser->is('customer') ? 'customer' : ($currentUser->is('translator') ? 'translator' : '');
        $jobs = $this->getJobsByUserType($currentUser, $userType);
        $emergencyJobs = array();
        $normalJobs = array();
        [$emergencyJobs, $normalJobs] = $this->categorizeJobs($jobs, $userId);

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'currentUser' => $currentUser,
            'userType' => $userType
        ];
    }

    /**
     * Get jobs based on user type.
     *
     * @param User $user
     * @param string $userType
     * @return Collection
     */
    private function getJobsByUserType(User $user, string $userType): Collection
    {
        if ($userType === 'customer') {
            return $user->jobs()->with([
                'user.userMeta',
                'user.average',
                'translatorJobRel.user.average',
                'language',
                'feedback'
            ])->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();
        } elseif ($userType === 'translator') {
            return Job::getTranslatorJobs($user->id, 'new')->pluck('jobs')->flatten();
        }

        return collect();
    }

    /**
     * Categorize jobs into emergency and normal jobs.
     *
     * @param Collection $jobs
     * @param int $userId
     * @return array
     */
    private function categorizeJobs(Collection $jobs, int $userId): array
    {
        $emergencyJobs = [];
        $normalJobs = [];

        foreach ($jobs as $job) {
            if ($job->immediate === 'yes') {
                $emergencyJobs[] = $job;
            } else {
                $normalJobs[] = $job;
            }
        }

        $normalJobs = collect($normalJobs)->each(function ($job) use ($userId) {
            $job['usercheck'] = Job::checkParticularJob($userId, $job);
        })->sortBy('due')->values()->all();

        return [$emergencyJobs, $normalJobs];
    }


    public function getUsersJobsHistory(int $userId, Request $request): array
    {
        $pageNum = $request->get('page', 1);
        $currentUser = User::find($userId);

        if (!$currentUser) {
            return $this->buildResponse([], [], [], null, '', 0, 0);
        }

        $userType = $currentUser->is('customer') ? 'customer' : ($currentUser->is('translator') ? 'translator' : '');

        if ($userType === 'customer') {
            return $this->getCustomerJobHistory($currentUser);
        } elseif ($userType === 'translator') {
            return $this->getTranslatorJobHistory($currentUser, $pageNum);
        }

        return $this->buildResponse([], [], [], $currentUser, $userType, 0, 0);
    }

    /**
     * Get job history for a customer user.
     *
     * @param User $user
     * @return array
     */
    private function getCustomerJobHistory(User $user): array
    {
        $jobs = $user->jobs()
            ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
            ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
            ->orderBy('due', 'desc')
            ->paginate(15);

        return $this->buildResponse([], [], $jobs, $user, 'customer', 0, 0);
    }

    /**
     * Get job history for a translator user.
     *
     * @param User $user
     * @param int $pageNum
     * @return array
     */
    private function getTranslatorJobHistory(User $user, int $pageNum): array
    {
        $jobs = Job::getTranslatorJobsHistoric($user->id, 'historic', $pageNum);
        $totalJobs = $jobs->total();
        $numPages = ceil($totalJobs / 15);

        return $this->buildResponse([], $jobs, $jobs, $user, 'translator', $numPages, $pageNum);
    }

    /**
     * Build the response array.
     *
     * @param array $emergencyJobs
     * @param array|Collection $normalJobs
     * @param Paginator $jobs
     * @param User|null $currentUser
     * @param string $userType
     * @param int $numPages
     * @param int $pageNum
     * @return array
     */
    private function buildResponse(
        array $emergencyJobs,
              $normalJobs,
              $jobs,
        ?User $currentUser,
        string $userType,
        int $numPages,
        int $pageNum
    ): array {
        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'jobs' => $jobs,
            'currentUser' => $currentUser,
            'userType' => $userType,
            'numPages' => $numPages,
            'pageNum' => $pageNum
        ];
    }
    public function store($user, $data)
    {
        $immediateTime = 5;
        $consumerType = $user->userMeta->consumer_type;

        if ($user->user_type != env('CUSTOMER_ROLE_ID')) {
            return [
                'status' => 'fail',
                'message' => "Translator can not create booking"
            ];
        }

        if (empty($data['from_language_id'])) {
            return $this->createErrorResponse('from_language_id', "Du måste fylla in alla fält");
        }

        if ($data['immediate'] == 'no') {
            $fields = ['due_date', 'due_time', 'duration'];
            foreach ($fields as $field) {
                if (isset($data[$field]) && empty($data[$field])) {
                    return $this->createErrorResponse($field, "Du måste fylla in alla fält");
                }
            }

            if (empty($data['customer_phone_type']) && empty($data['customer_physical_type'])) {
                return $this->createErrorResponse('customer_phone_type', "Du måste göra ett val här");
            }
        } elseif (empty($data['duration'])) {
            return $this->createErrorResponse('duration', "Du måste fylla in alla fält");
        }

        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';
        $response['customer_physical_type'] = $data['customer_physical_type'];

        if ($data['immediate'] == 'yes') {
            $dueCarbon = Carbon::now()->addMinute($immediateTime);
            $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
            $response['type'] = 'immediate';
        } else {
            $due = $data['due_date'] . " " . $data['due_time'];
            $response['type'] = 'regular';
            $dueCarbon = Carbon::createFromFormat('m/d/Y H:i', $due);
            $data['due'] = $dueCarbon->format('Y-m-d H:i:s');

            if ($dueCarbon->isPast()) {
                return $this->createErrorResponse('due_date', "Can't create booking in past");
            }
        }

        $this->setJobForAttributes($data);

        switch ($consumerType) {
            case 'rwsconsumer':
                $data['job_type'] = 'rws';
                break;
            case 'ngo':
                $data['job_type'] = 'unpaid';
                break;
            case 'paid':
                $data['job_type'] = 'paid';
                break;
        }

        $data['b_created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = isset($due) ? TeHelper::willExpireAt($due, $data['b_created_at']) : null;
        $data['by_admin'] = $data['by_admin'] ?? 'no';

        $job = $user->jobs()->create($data);

        $response['status'] = 'success';
        $response['id'] = $job->id;

        $this->setJobForResponseData($job, $data);

        $data['customer_town'] = $user->userMeta->city;
        $data['customer_type'] = $user->userMeta->customer_type;

        // Event::fire(new JobWasCreated($job, $data, '*'));
        // $this->sendNotificationToSuitableTranslators($job->id, $data, '*');

        return $response;
    }

    private function createErrorResponse($fieldName, $message)
    {
        return [
            'status' => 'fail',
            'message' => $message,
            'field_name' => $fieldName
        ];
    }

    private function setJobForAttributes(&$data)
    {
        $jobFor = $data['job_for'] ?? [];
        $data['gender'] = in_array('male', $jobFor) ? 'male' : (in_array('female', $jobFor) ? 'female' : null);

        if (in_array('normal', $jobFor) && in_array('certified', $jobFor)) {
            $data['certified'] = 'both';
        } elseif (in_array('normal', $jobFor) && in_array('certified_in_law', $jobFor)) {
            $data['certified'] = 'n_law';
        } elseif (in_array('normal', $jobFor) && in_array('certified_in_helth', $jobFor)) {
            $data['certified'] = 'n_health';
        } elseif (in_array('normal', $jobFor)) {
            $data['certified'] = 'normal';
        } elseif (in_array('certified', $jobFor)) {
            $data['certified'] = 'yes';
        } elseif (in_array('certified_in_law', $jobFor)) {
            $data['certified'] = 'law';
        } elseif (in_array('certified_in_helth', $jobFor)) {
            $data['certified'] = 'health';
        }
    }

    private function setJobForResponseData($job, &$data)
    {
        $data['job_for'] = [];
        if ($job->gender) {
            $data['job_for'][] = $job->gender == 'male' ? 'Man' : 'Kvinna';
        }
        if ($job->certified) {
            switch ($job->certified) {
                case 'both':
                    $data['job_for'][] = 'normal';
                    $data['job_for'][] = 'certified';
                    break;
                case 'yes':
                    $data['job_for'][] = 'certified';
                    break;
                default:
                    $data['job_for'][] = $job->certified;
                    break;
            }
        }
    }


    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $userType = $data['user_type'];
        $job = Job::findOrFail($data['user_email_job_id'] ?? null);
        $job->user_email = $data['user_email'] ?? null;
        $job->reference = $data['reference'] ?? '';
        $user = $job->user()->first();

        if (isset($data['address'])) {
            $job->address = !empty($data['address']) ? $data['address'] : $user->userMeta->address;
            $job->instructions = !empty($data['instructions']) ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = !empty($data['town']) ? $data['town'] : $user->userMeta->city;
        }

        $job->save();

        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $sendData = [
            'user' => $user,
            'job'  => $job
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-created', $sendData);

        $response = [
            'type' => $userType,
            'job' => $job,
            'status' => 'success'
        ];

        $eventData = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $eventData, '*'));

        return $response;
    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type
        ];

        list($data['due_date'], $data['due_time']) = explode(' ', $job->due);

        $data['job_for'] = [];

        if ($job->gender) {
            $data['job_for'][] = $job->gender === 'male' ? 'Man' : 'Kvinna';
        }

        if ($job->certified) {
            switch ($job->certified) {
                case 'both':
                    $data['job_for'][] = 'Godkänd tolk';
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'yes':
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'n_health':
                    $data['job_for'][] = 'Sjukvårdstolk';
                    break;
                case 'law':
                case 'n_law':
                    $data['job_for'][] = 'Rätttstolk';
                    break;
                default:
                    $data['job_for'][] = $job->certified;
                    break;
            }
        }

        return $data;
    }

    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = [])
    {
        $completedDate = now();
        $jobId = $post_data['job_id'];
        $job = Job::with('translatorJobRel')->findOrFail($jobId);

        $interval = $job->due->diff($completedDate)->format('%H:%I:%S');
        $job->update([
            'end_at' => $completedDate,
            'status' => 'completed',
            'session_time' => $interval
        ]);

        $user = $job->user;
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $sessionTime = $this->formatSessionTime($job->session_time);
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;

        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $sessionTime,
            'for_text' => 'faktura'
        ];

        $this->sendJobCompletionEmail($email, $name, $subject, $data);
        $this->updateTranslatorJob($job, $post_data['userid'], $sessionTime);
    }

    private function formatSessionTime($sessionTime)
    {
        [$hours, $minutes] = explode(':', $sessionTime);
        return "{$hours} tim {$minutes} min";
    }

    private function sendJobCompletionEmail($email, $name, $subject, $data)
    {
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    }

    private function updateTranslatorJob($job, $userId, $sessionTime)
    {
        $translatorJob = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();
        $translator = $translatorJob->user;
        $translatorJob->update([
            'completed_at' => now(),
            'completed_by' => $userId
        ]);

        $email = $translator->email;
        $name = $translator->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $data = [
            'user' => $translator,
            'job' => $job,
            'session_time' => $sessionTime,
            'for_text' => 'lön'
        ];

        $this->sendJobCompletionEmail($email, $name, $subject, $data);
    }


    public function getPotentialJobIdsWithUserId($userId)
    {
        $userMeta = UserMeta::where('user_id', $userId)->firstOrFail();
        $jobType = $this->getJobTypeByTranslatorType($userMeta->translator_type);

        $userLanguages = UserLanguages::where('user_id', $userId)->pluck('lang_id');
        $jobIds = Job::getJobs($userId, $jobType, 'pending', $userLanguages, $userMeta->gender, $userMeta->translator_level);

        return $this->filterJobsByTown($jobIds, $userId);
    }

    private function getJobTypeByTranslatorType($translatorType)
    {
        return match ($translatorType) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            'volunteer' => 'unpaid',
            default => 'unpaid'
        };
    }

    private function filterJobsByTown($jobIds, $userId)
    {
        return $jobIds->filter(function($job) use ($userId) {
            $job = Job::find($job->id);
            return ($job->customer_phone_type == 'no' || $job->customer_phone_type == '') &&
                $job->customer_physical_type == 'yes' &&
                Job::checkTowns($job->user_id, $userId);
        });
    }

    public function sendNotificationTranslator($job, $data = [], $excludeUserId)
    {
        $users = User::where('user_type', 2)->where('status', 1)->where('id', '!=', $excludeUserId)->get();
        [$translatorArray, $delayedTranslatorArray] = $this->getTranslatorArrays($users, $job, $data);

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msgContents = $this->getMessageContents($data);

        $this->logPushNotification($job->id, $translatorArray, $delayedTranslatorArray, $msgContents, $data);

        $this->sendPushNotificationToSpecificUsers($translatorArray, $job->id, $data, $msgContents, false);
        $this->sendPushNotificationToSpecificUsers($delayedTranslatorArray, $job->id, $data, $msgContents, true);
    }

    private function getTranslatorArrays($users, $job, $data)
    {
        $translatorArray = [];
        $delayedTranslatorArray = [];

        foreach ($users as $user) {
            if (!$this->isNeedToSendPush($user->id)) continue;
            if ($data['immediate'] == 'yes' && TeHelper::getUsermeta($user->id, 'not_get_emergency') == 'yes') continue;

            $jobs = $this->getPotentialJobIdsWithUserId($user->id);

            foreach ($jobs as $oneJob) {
                if ($job->id == $oneJob->id) {
                    if (Job::assignedToPaticularTranslator($user->id, $oneJob->id) == 'SpecificJob') {
                        if (Job::checkParticularJob($user->id, $oneJob) != 'userCanNotAcceptJob') {
                            if ($this->isNeedToDelayPush($user->id)) {
                                $delayedTranslatorArray[] = $user;
                            } else {
                                $translatorArray[] = $user;
                            }
                        }
                    }
                }
            }
        }

        return [$translatorArray, $delayedTranslatorArray];
    }

    private function getMessageContents($data)
    {
        $message = $data['immediate'] == 'no'
            ? 'Ny bokning för ' . $data['language'] . ' tolk ' . $data['duration'] . 'min ' . $data['due']
            : 'Ny akutbokning för ' . $data['language'] . ' tolk ' . $data['duration'] . 'min';

        return ["en" => $message];
    }

    private function logPushNotification($jobId, $translatorArray, $delayedTranslatorArray, $msgContents, $data)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . now()->format('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->info('Push send for job ' . $jobId, [$translatorArray, $delayedTranslatorArray, $msgContents, $data]);
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */


    public function sendSMSNotificationToTranslator($job)
    {
        // Get potential translators for the job
        $translators = $this->getPotentialTranslators($job);

        // Get job poster's city
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        // Prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $phoneJobMessageTemplate = trans('sms.phone_job', compact('date', 'time', 'duration', 'jobId'));
        $physicalJobMessageTemplate = trans('sms.physical_job', compact('date', 'time', 'city', 'duration', 'jobId'));

        // Determine the message based on job type
        $message = '';
        if ($job->customer_physical_type == 'yes' || $job->customer_phone_type == 'yes') {
            $message = $job->customer_physical_type == 'yes' ? $physicalJobMessageTemplate : $phoneJobMessageTemplate;
        }

        // Log the message
        Log::info($message);

        // Send messages via SMS handler
        foreach ($translators as $translator) {
            // Send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */

    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) return false;
        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        if ($not_get_nighttime == 'yes') return true;
        return false;
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        if ($not_get_notification == 'yes') return false;
        return true;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        // Set up logger
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->info("Push send for job $job_id", [$users, $data, $msg_text, $is_need_delay]);

        // Determine environment-specific OneSignal credentials
        $env = env('APP_ENV');
        $onesignalAppID = config($env == 'prod' ? 'app.prodOnesignalAppID' : 'app.devOnesignalAppID');
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config($env == 'prod' ? 'app.prodOnesignalApiKey' : 'app.devOnesignalApiKey'));

        // Prepare user tags and notification fields
        $user_tags = json_decode($this->getUserTagsStringFromArray($users));
        $data['job_id'] = $job_id;

        $android_sound = 'default';
        $ios_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        $fields = [
            'app_id'         => $onesignalAppID,
            'tags'           => $user_tags,
            'data'           => $data,
            'title'          => ['en' => 'DigitalTolk'],
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound,
        ];

        if ($is_need_delay) {
            $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
        }

        $fields = json_encode($fields);

        // Send the push notification
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $onesignalRestAuthKey]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        $response = curl_exec($ch);
        $logger->info("Push send for job $job_id curl answer", [$response]);
        curl_close($ch);
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        // Determine translator type based on job type
        $translator_type = $this->determineTranslatorType($job->job_type);

        // Determine translator levels based on certification
        $translator_levels = $this->determineTranslatorLevels($job->certified);

        // Get blacklisted translators for the job user
        $blacklisted_translator_ids = UsersBlacklist::where('user_id', $job->user_id)
            ->pluck('translator_id')
            ->all();

        // Fetch potential users
        $users = User::getPotentialUsers(
            $translator_type,
            $job->from_language_id,
            $job->gender,
            $translator_levels,
            $blacklisted_translator_ids
        );

        return $users;
    }

    private function determineTranslatorType($job_type)
    {
        switch ($job_type) {
            case 'paid':
                return 'professional';
            case 'rws':
                return 'rwstranslator';
            case 'unpaid':
                return 'volunteer';
            default:
                throw new InvalidArgumentException("Unknown job type: $job_type");
        }
    }

    private function determineTranslatorLevels($certification)
    {
        $translator_levels = [];

        if (empty($certification)) {
            return [
                'Certified',
                'Certified with specialisation in law',
                'Certified with specialisation in health care',
                'Layman',
                'Read Translation courses'
            ];
        }

        switch ($certification) {
            case 'yes':
            case 'both':
                $translator_levels = [
                    'Certified',
                    'Certified with specialisation in law',
                    'Certified with specialisation in health care'
                ];
                break;
            case 'law':
            case 'n_law':
                $translator_levels = ['Certified with specialisation in law'];
                break;
            case 'health':
            case 'n_health':
                $translator_levels = ['Certified with specialisation in health care'];
                break;
            case 'normal':
            case 'both':
                $translator_levels = [
                    'Layman',
                    'Read Translation courses'
                ];
                break;
            default:
                throw new InvalidArgumentException("Unknown certification type: $certification");
        }

        return $translator_levels;
    }


    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);

        // Get the current translator
        $current_translator = $job->translatorJobRel->where('cancel_at', null)->first() ?: $job->translatorJobRel->where('completed_at', '!=', null)->first();

        $log_data = [];
        $langChanged = false;

        // Change translator if needed
        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        // Change due date if needed
        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        // Change language if needed
        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        // Change status if needed
        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        // Update admin comments
        $job->admin_comments = $data['admin_comments'];

        // Log the update action
        $this->logger->info(
            'USER #' . $cuser->id . '(' . $cuser->name . ') has updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data: ',
            $log_data
        );

        // Update job reference
        $job->reference = $data['reference'];

        // Save the job and send notifications if needed
        $job->save();

        // Send notifications if conditions are met
        if ($job->due > Carbon::now()) {
            if (isset($old_time) && $changeDue['dateChanged']) {
                $this->sendChangedDateNotification($job, $old_time);
            }
            if ($changeTranslator['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            }
            if ($langChanged) {
                $this->sendChangedLangNotification($job, $old_lang);
            }
        }

        return ['Updated'];
    }


    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;
        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'pending') {
            $this->handlePendingStatus($job, $email, $name, $dataEmail);
            return true;
        } elseif ($changedTranslator) {
            $this->handleChangedTranslator($job, $email, $name, $dataEmail);
            return true;
        }

        return false;
    }

    private function handlePendingStatus($job, $email, $name, $dataEmail)
    {
        $job->created_at = now();
        $job->emailsent = 0;
        $job->emailsenttovirpal = 0;
        $job->save();

        $job_data = $this->jobToData($job);
        $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);
        $this->sendNotificationTranslator($job, $job_data, '*'); // send Push all suitable translators
    }

    private function handleChangedTranslator($job, $email, $name, $dataEmail)
    {
        $job->save();
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
    }


    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];

        if ($data['status'] == 'timedout') {
            if (empty($data['admin_comments'])) {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
        }

        $job->save();
        return true;
    }


    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];

        if (empty($data['admin_comments'])) {
            return false;
        }
        $job->admin_comments = $data['admin_comments'];

        if ($data['status'] == 'completed') {
            if (empty($data['sesion_time'])) {
                return false;
            }

            $user = $job->user()->first();
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = now();
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

            $email = $job->user_email ?: $user->email;
            $name = $user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $translatorJob = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
            $translatorEmail = $translatorJob->user->email;
            $translatorName = $translatorJob->user->name;
            $translatorDataEmail = [
                'user'         => $translatorJob->user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];

            $this->mailer->send($translatorEmail, $translatorName, $subject, 'emails.session-ended', $translatorDataEmail);
        }

        $job->save();
        return true;
    }


    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'assigned' && $changedTranslator) {

            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }

        return false;
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                } else {
                    $email = $user->email;
                }
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

                $email = $user->user->email;
                $name = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged)
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];

        }

        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];

    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);

    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);

    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = array();            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;
        $data['job_for'] = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }
        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );
        else
            $msg_text = array(
                "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = array_map(function($user) {
            return [
                'key' => 'email',
                'relation' => '=',
                'value' => strtolower($user->email)
            ];
        }, $users);

        return json_encode($this->interleaveWithOperator($user_tags, ['operator' => 'OR']));
    }

    private function interleaveWithOperator(array $items, array $operator)
    {
        $result = [];
        foreach ($items as $item) {
            if (!empty($result)) {
                $result[] = $operator;
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);

        if (Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            return [
                'status' => 'fail',
                'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'
            ];
        }

        if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
            $job->status = 'assigned';
            $job->save();

            $user = $job->user()->first();
            $email = $job->user_email ?? $user->email;
            $name = $user->name;
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $dataEmail = [
                'user' => $user,
                'job'  => $job
            ];

            $mailer = new AppMailer();
            $mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
        }

        $jobs = $this->getPotentialJobs($cuser);

        return [
            'status' => 'success',
            'list' => json_encode(['jobs' => $jobs, 'job' => $job], true)
        ];
    }


    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $cuser)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $job = Job::findOrFail($job_id);
        $response = array();

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                } else {
                    $email = $user->email;
                    $name = $user->name;
                }
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                $data = array();
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                );
                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = array($user);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
                }
                // Your Booking is accepted sucessfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                // Booking already accepted by someone else
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            // You already have a booking the time
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }
        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        $response = ['status' => 'fail'];
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $current_time = Carbon::now();

        if ($user->is('customer')) {
            $job->withdraw_at = $current_time;
            $job->status = $job->withdraw_at->diffInHours($job->due) >= 24 ? 'withdrawbefore24' : 'withdrawafter24';
            $response['jobstatus'] = 'success';
            $response['status'] = 'success';
            $job->save();

            Event::fire(new JobWasCanceled($job));

            if ($translator) {
                $notification_data = [
                    'notification_type' => 'job_cancelled'
                ];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    "en" => "Kunden har avbokat bokningen för {$language}tolk, {$job->duration}min, {$job->due}. Var god och kolla dina tidigare bokningar för detaljer."
                ];
                if ($this->isNeedToSendPush($translator->id)) {
                    $this->sendPushNotificationToSpecificUsers(
                        [$translator],
                        $job_id,
                        $notification_data,
                        $msg_text,
                        $this->isNeedToDelayPush($translator->id)
                    );
                }
            }
        } else {
            if ($job->due->diffInHours($current_time) > 24) {
                $customer = $job->user()->first();
                if ($customer) {
                    $notification_data = [
                        'notification_type' => 'job_cancelled'
                    ];
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = [
                        "en" => "Er {$language}tolk, {$job->duration}min {$job->due}, har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack."
                    ];
                    if ($this->isNeedToSendPush($customer->id)) {
                        $this->sendPushNotificationToSpecificUsers(
                            [$customer],
                            $job_id,
                            $notification_data,
                            $msg_text,
                            $this->isNeedToDelayPush($customer->id)
                        );
                    }
                }

                $job->status = 'pending';
                $job->created_at = $current_time;
                $job->will_expire_at = TeHelper::willExpireAt($job->due, $current_time);
                $job->save();

                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $notification_data = $this->jobToData($job);
                $this->sendNotificationTranslator($job, $notification_data, '*');
                $response['status'] = 'success';
            } else {
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }
        return $response;
    }


    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $translator_type = $cuser_meta->translator_type;

        // Determine job type based on translator type
        switch ($translator_type) {
            case 'professional':
                $job_type = 'paid';
                break;
            case 'rwstranslator':
                $job_type = 'rws';
                break;
            case 'volunteer':
            default:
                $job_type = 'unpaid';
                break;
        }

        $user_language_ids = UserLanguages::where('user_id', $cuser->id)->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;

        // Fetch potential job IDs
        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $user_language_ids, $gender, $translator_level);

        foreach ($job_ids as $k => $job) {
            $job_user_id = $job->user_id;
            $job->specific_job = Job::assignedToParticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $check_town = Job::checkTowns($job_user_id, $cuser->id);

            // Remove jobs based on specific conditions
            if ($job->specific_job === 'SpecificJob' && $job->check_particular_job === 'userCanNotAcceptJob') {
                unset($job_ids[$k]);
                continue;
            }

            if (($job->customer_phone_type === 'no' || empty($job->customer_phone_type)) &&
                $job->customer_physical_type === 'yes' && !$check_town) {
                unset($job_ids[$k]);
            }
        }

        return $job_ids;
    }


    public function endJob($post_data)
    {
        $completedDate = now();
        $jobId = $post_data["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);

        if ($jobDetail->status != 'started') {
            return ['status' => 'success'];
        }

        $dueDate = $jobDetail->due;
        $interval = $dueDate->diff($completedDate)->format('%h:%i:%s');

        $jobDetail->end_at = $completedDate;
        $jobDetail->status = 'completed';
        $jobDetail->session_time = $interval;

        $user = $jobDetail->user;
        $email = $jobDetail->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $jobDetail->id;
        $sessionExplode = explode(':', $jobDetail->session_time);
        $sessionTime = $sessionExplode[0] . ' tim ' . $sessionExplode[1] . ' min';

        $data = [
            'user'         => $user,
            'job'          => $jobDetail,
            'session_time' => $sessionTime,
            'for_text'     => 'faktura'
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $jobDetail->save();

        $translatorRel = $jobDetail->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();

        Event::fire(new SessionEnded($jobDetail, $post_data['user_id'] == $jobDetail->user_id ? $translatorRel->user_id : $jobDetail->user_id));

        $translator = $translatorRel->user;
        $email = $translator->email;
        $name = $translator->name;
        $data['user'] = $translator;
        $data['for_text'] = 'lön';

        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $translatorRel->completed_at = $completedDate;
        $translatorRel->completed_by = $post_data['user_id'];
        $translatorRel->save();

        return ['status' => 'success'];
    }



    public function customerNotCall($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        $allJobs = Job::query();

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0')
                    ->whereHas('feedback', function ($q) {
                        $q->where('rating', '<=', '3');
                    });
            }

            if (isset($requestdata['id']) && $requestdata['id'] != '') {
                $allJobs->whereIn('id', (array)$requestdata['id']);
            }

            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestdata['lang']);
            }

            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('status', $requestdata['status']);
            }

            if (isset($requestdata['expired_at']) && $requestdata['expired_at'] != '') {
                $allJobs->where('expired_at', '>=', $requestdata['expired_at']);
            }

            if (isset($requestdata['will_expire_at']) && $requestdata['will_expire_at'] != '') {
                $allJobs->where('will_expire_at', '>=', $requestdata['will_expire_at']);
            }

            if (isset($requestdata['customer_email']) && count($requestdata['customer_email']) > 0) {
                $userIds = DB::table('users')->whereIn('email', $requestdata['customer_email'])->pluck('id');
                $allJobs->whereIn('user_id', $userIds);
            }

            if (isset($requestdata['translator_email']) && count($requestdata['translator_email']) > 0) {
                $userIds = DB::table('users')->whereIn('email', $requestdata['translator_email'])->pluck('id');
                $jobIds = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', $userIds)->pluck('job_id');
                $allJobs->whereIn('id', $jobIds);
            }

            if (isset($requestdata['filter_timetype'])) {
                $from = $requestdata['from'] ?? '';
                $to = isset($requestdata['to']) ? $requestdata['to'] . " 23:59:00" : '';

                if ($requestdata['filter_timetype'] == 'created') {
                    if ($from != '') $allJobs->where('created_at', '>=', $from);
                    if ($to != '') $allJobs->where('created_at', '<=', $to);
                    $allJobs->orderBy('created_at', 'desc');
                }

                if ($requestdata['filter_timetype'] == 'due') {
                    if ($from != '') $allJobs->where('due', '>=', $from);
                    if ($to != '') $allJobs->where('due', '<=', $to);
                    $allJobs->orderBy('due', 'desc');
                }
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('job_type', $requestdata['job_type']);
            }

            if (isset($requestdata['physical'])) {
                $allJobs->where('customer_physical_type', $requestdata['physical'])->where('ignore_physical', 0);
            }

            if (isset($requestdata['phone'])) {
                $allJobs->where('customer_phone_type', $requestdata['phone'])->where('ignore_physical_phone', 0);
            }

            if (isset($requestdata['flagged'])) {
                $allJobs->where('flagged', $requestdata['flagged'])->where('ignore_flagged', 0);
            }

            if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty') {
                $allJobs->whereDoesntHave('distance');
            }

            if (isset($requestdata['salary']) && $requestdata['salary'] == 'yes') {
                $allJobs->whereDoesntHave('user.salaries');
            }

            if (isset($requestdata['count']) && $requestdata['count'] == 'true') {
                return ['count' => $allJobs->count()];
            }

            if (isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '') {
                $allJobs->whereHas('user.userMeta', function ($q) use ($requestdata) {
                    $q->where('consumer_type', $requestdata['consumer_type']);
                });
            }

            if (isset($requestdata['booking_type'])) {
                if ($requestdata['booking_type'] == 'physical') {
                    $allJobs->where('customer_physical_type', 'yes');
                } elseif ($requestdata['booking_type'] == 'phone') {
                    $allJobs->where('customer_phone_type', 'yes');
                }
            }
        } else {
            $allJobs->where('job_type', $consumer_type == 'RWS' ? 'rws' : 'unpaid');

            if (isset($requestdata['id']) && $requestdata['id'] != '') {
                $allJobs->where('id', $requestdata['id']);
            }

            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0')
                    ->whereHas('feedback', function ($q) {
                        $q->where('rating', '<=', '3');
                    });
            }

            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestdata['lang']);
            }

            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('status', $requestdata['status']);
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('job_type', $requestdata['job_type']);
            }

            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('user_id', $user->id);
                }
            }

            if (isset($requestdata['filter_timetype'])) {
                $from = $requestdata['from'] ?? '';
                $to = isset($requestdata['to']) ? $requestdata['to'] . " 23:59:00" : '';

                if ($requestdata['filter_timetype'] == 'created') {
                    if ($from != '') $allJobs->where('created_at', '>=', $from);
                    if ($to != '') $allJobs->where('created_at', '<=', $to);
                    $allJobs->orderBy('created_at', 'desc');
                }

                if ($requestdata['filter_timetype'] == 'due') {
                    if ($from != '') $allJobs->where('due', '>=', $from);
                    if ($to != '') $allJobs->where('due', '<=', $to);
                    $allJobs->orderBy('due', 'desc');
                }
            }
        }

        $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance')
            ->orderBy('created_at', 'desc');

        return $limit == 'all' ? $allJobs->get() : $allJobs->paginate(15);
    }

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs [$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId [] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->pluck('email');
        $all_translators = DB::table('users')->where('user_type', '2')->pluck('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0)
                ->where('jobs.status', 'pending')
                ->where('jobs.due', '>=', Carbon::now());

            if (!empty($requestdata['lang'])) {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang']);
            }

            if (!empty($requestdata['status'])) {
                $allJobs->whereIn('jobs.status', $requestdata['status']);
            }

            if (!empty($requestdata['customer_email'])) {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', $user->id);
                }
            }

            if (!empty($requestdata['translator_email'])) {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $jobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id');
                    $allJobs->whereIn('jobs.id', $jobIDs);
                }
            }

            if (!empty($requestdata['filter_timetype'])) {
                $from = $requestdata['from'] ?? null;
                $to = !empty($requestdata['to']) ? $requestdata['to'] . " 23:59:00" : null;

                if ($requestdata['filter_timetype'] == "created") {
                    if ($from) $allJobs->where('jobs.created_at', '>=', $from);
                    if ($to) $allJobs->where('jobs.created_at', '<=', $to);
                    $allJobs->orderBy('jobs.created_at', 'desc');
                } elseif ($requestdata['filter_timetype'] == "due") {
                    if ($from) $allJobs->where('jobs.due', '>=', $from);
                    if ($to) $allJobs->where('jobs.due', '<=', $to);
                    $allJobs->orderBy('jobs.due', 'desc');
                }
            }

            if (!empty($requestdata['job_type'])) {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type']);
            }

            $allJobs->select('jobs.*', 'languages.language')->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata
        ];
    }


    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobId = $request['jobid'];
        $userId = $request['userid'];

        $job = Job::find($jobId);

        if (!$job) {
            return ["Job not found"];
        }

        $currentTime = Carbon::now();

        if ($job->status != 'timedout') {
            $job->status = 'pending';
            $job->created_at = $currentTime;
            $job->will_expire_at = TeHelper::willExpireAt($job->due, $currentTime);
            $job->save();
            $newJobId = $jobId;
        } else {
            $jobData = $job->toArray();
            $jobData['status'] = 'pending';
            $jobData['created_at'] = $currentTime;
            $jobData['updated_at'] = $currentTime;
            $jobData['will_expire_at'] = TeHelper::willExpireAt($job->due, $currentTime);
            $jobData['cust_16_hour_email'] = 0;
            $jobData['cust_48_hour_email'] = 0;
            $jobData['admin_comments'] = 'This booking is a reopening of booking #' . $jobId;

            $newJob = Job::create($jobData);
            $newJobId = $newJob->id;
        }

        Translator::where('job_id', $jobId)
            ->whereNull('cancel_at')
            ->update(['cancel_at' => $currentTime]);

        Translator::create([
            'created_at' => $currentTime,
            'updated_at' => $currentTime,
            'will_expire_at' => TeHelper::willExpireAt($job->due, $currentTime),
            'user_id' => $userId,
            'job_id' => $jobId,
            'cancel_at' => $currentTime
        ]);

        $this->sendNotificationByAdminCancelJob($newJobId);

        return ["Tolk cancelled!"];
    }


    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);
        
        return sprintf($format, $hours, $minutes);
    }

}