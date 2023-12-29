<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
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
use DataTables; // Yajra DataTables
use Carbon\Carbon; // Carbon library for calculation of datetime
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
     public function getUsersJobs($user_id)
     {
     $cuser = User::find($user_id);
     $usertype = '';
     $emergencyJobs = [];
     $normalJobs = [];

     if ($cuser) {
         if ($cuser->is('customer')) {
             $jobs = $this->getCustomerJobs($cuser);
             $usertype = 'customer';
         } elseif ($cuser->is('translator')) {
             $jobs = $this->getTranslatorJobs($cuser);
             $usertype = 'translator';
         }

         if ($jobs) {
             foreach ($jobs as $jobitem) {
                 if ($jobitem->immediate == 'yes') {
                     $emergencyJobs[] = $jobitem;
                 } else {
                     $normalJobs[] = $jobitem;
                 }
             }

             $normalJobs = collect($normalJobs)->each(function ($item, $key) use ($user_id) {
                 $item['usercheck'] = Job::checkParticularJob($user_id, $item);
             })->sortBy('due')->all();
         }
     }

     return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs, 'cuser' => $cuser, 'usertype' => $usertype];
   }

   private function getCustomerJobs($user)
   {
       return $user->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
           ->whereIn('status', ['pending', 'assigned', 'started'])
           ->orderBy('due', 'asc')->get();
   }

   private function getTranslatorJobs($user)
   {
       $jobs = Job::getTranslatorJobs($user->id, 'new');
       return $jobs->pluck('jobs')->all();
   }


    /**
     * @param $user_id
     * @return array
     */


     public function getUsersJobsHistory($user_id, Request $request)
     {
         $cuser = User::find($user_id);

         if ($cuser && $cuser->is('customer')) {
             $jobs = $cuser->jobs()
                 ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                 ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                 ->orderBy('due', 'desc')
                 ->get();

             return Datatables::of($jobs)
                 ->make(true);
         } elseif ($cuser && $cuser->is('translator')) {
             $jobs = Job::getTranslatorJobsHistoric($cuser->id, 'historic');

             return Datatables::of($jobs)
                 ->make(true);
         }
     }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
     public function store($user, $data)
     {
     $immediatetime = 5;

     if ($user->user_type != env('CUSTOMER_ROLE_ID')) {
         return ['status' => 'fail', 'message' => "Translator can not create booking"];
     }

     $cuser = $user;

     $response = $this->validateBookingData($data);

     if ($response['status'] === 'fail') {
         return $response;
     }

     $data = $this->processBookingData($data, $immediatetime, $cuser);

     $job = $cuser->jobs()->create($data);

     $response = [
         'status' => 'success',
         'id' => $job->id,
         'job_for' => $this->getJobForValues($job),
         'customer_town' => $cuser->userMeta->city,
         'customer_type' => $cuser->userMeta->customer_type,
     ];

     //Event::fire(new JobWasCreated($job, $data, '*'));

     // $this->sendNotificationToSuitableTranslators($job->id, $data, '*');

     return $response;
 }

 private function validateBookingData($data)
 {
     $requiredFields = ['from_language_id', 'duration'];

     foreach ($requiredFields as $field) {
         if (!isset($data[$field])) {
             return ['status' => 'fail', 'message' => "Du måste fylla in alla fält", 'field_name' => $field];
         }
     }

     if ($data['immediate'] === 'no' && !$this->validateNonImmediateBooking($data)) {
         return ['status' => 'fail', 'message' => "Du måste fylla in alla fält", 'field_name' => 'due_date'];
     }

     return ['status' => 'success'];
 }

 private function validateNonImmediateBooking($data)
 {
     return isset($data['due_date']) && $data['due_date'] !== '' &&
            isset($data['due_time']) && $data['due_time'] !== '' &&
            isset($data['customer_phone_type']) && isset($data['customer_physical_type']) &&
            isset($data['duration']) && $data['duration'] !== '';
 }

 private function processBookingData($data, $immediatetime, $cuser)
 {
     $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
     $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';

     if ($data['immediate'] === 'yes') {
         $due_carbon = Carbon::now()->addMinute($immediatetime);
         $data['due'] = $due_carbon->format('Y-m-d H:i:s');
         $data['immediate'] = 'yes';
         $data['customer_phone_type'] = 'yes';
         $response['type'] = 'immediate';
     } else {
         $due = $data['due_date'] . " " . $data['due_time'];
         $response['type'] = 'regular';
         $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
         $data['due'] = $due_carbon->format('Y-m-d H:i:s');
         if ($due_carbon->isPast()) {
             return ['status' => 'fail', 'message' => "Can't create booking in past"];
         }
     }

     // Set other fields based on your logic

     return $data;
 }

 private function getJobForValues($job)
 {
     $job_for = [];

     if ($job->gender != null) {
         $job_for[] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
     }

     if ($job->certified != null) {
         if ($job->certified == 'both') {
             $job_for[] = 'normal';
             $job_for[] = 'certified';
         } else {
             $job_for[] = $job->certified;
         }
     }

     return $job_for;
 }


    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $user_type = $data['user_type'];
        $job = Job::findOrFail(@$data['user_email_job_id']);
        $job->user_email = @$data['user_email'];
        $job->reference = isset($data['reference']) ? $data['reference'] : '';
        $user = $job->user()->get()->first();
        if (isset($data['address'])) {
            $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
            $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
        }
        $job->save();

        if (!empty($job->user_email)) {
            $email = $job->user_email;
            $name = $user->name;
        } else {
            $email = $user->email;
            $name = $user->name;
        }
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        $response['type'] = $user_type;
        $response['job'] = $job;
        $response['status'] = 'success';
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));
        return $response;

    }

    /**
     * @param $job
     * @return array
     */
     public function jobToData($job)
 {
     $data = [];

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
     $data['customer_town'] = $job->town;
     $data['customer_type'] = $job->user->userMeta->customer_type;

     [$due_date, $due_time] = explode(" ", $job->due);
     $data['due_date'] = $due_date;
     $data['due_time'] = $due_time;

     $data['job_for'] = $this->mapCertifiedToJobType($job->certified);

     return $data;
 }

 private function mapCertifiedToJobType($certified)
 {
     $certifiedMapping = [
         'both' => ['Godkänd tolk', 'Auktoriserad'],
         'yes' => ['Auktoriserad'],
         'n_health' => ['Sjukvårdstolk'],
         'law' => ['Rättstolk'],
         'n_law' => ['Rättstolk'],
         // Add more mappings as needed
     ];

     return $certifiedMapping[$certified] ?? [$certified];
 }


    /**
     * @param array $post_data
     */
     public function jobEnd($post_data = array())
     {
         $completeddate = Carbon::now();
         $jobid = $post_data["job_id"];
         $job_detail = Job::with('translatorJobRel')->find($jobid);

         $this->updateJobDetails($job_detail, $completeddate, $post_data);

         // Email to the user
         $this->sendSessionEmail($job_detail->user, $job_detail, $completeddate, 'faktura');

         // Email to the translator
         $translator = $job_detail->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
         $this->sendSessionEmail($translator->user(), $job_detail, $completeddate, 'lön');

         $translator->update([
             'completed_at' => $completeddate,
             'completed_by' => $post_data['userid']
         ]);
     }

     private function updateJobDetails($job, $completeddate, $post_data)
     {
         $job->end_at = $completeddate;
         $job->status = 'completed';

         $start = Carbon::parse($job->due);
         $diff = $completeddate->diff($start);
         $job->session_time = $diff->format('%h:%i:%s');

         $job->save();

         Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $job->translatorJobRel->user_id : $job->user_id));
     }

     private function sendSessionEmail($user, $job, $completeddate, $for_text)
     {
         $session_time = $completeddate->diffInRealMinutes(Carbon::parse($job->due));

         $data = [
             'user'         => $user,
             'job'          => $job,
             'session_time' => $session_time,
             'for_text'     => $for_text,
         ];

         $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
         $mailer = new AppMailer();
         $mailer->send($user->email, $user->name, $subject, 'emails.session-ended', $data);
     }


    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
public function getPotentialJobIdsWithUserId($user_id)
 {
     try {
         // Retrieve User Metadata
         $user_meta = UserMeta::where('user_id', $user_id)->firstOrFail();

         // Determine Job Type
         $translator_type = $user_meta->translator_type;
         $job_type = 'unpaid';
         if ($translator_type == 'professional') {
             $job_type = 'paid';
         } elseif ($translator_type == 'rwstranslator') {
             $job_type = 'rws';
         } elseif ($translator_type == 'volunteer') {
             $job_type = 'unpaid';
         }

         // Retrieve User Languages
         $languages = UserLanguages::where('user_id', $user_id)->get();
         $userlanguage = $languages->pluck('lang_id')->all();

         // Filter Jobs Based on Criteria
         $gender = $user_meta->gender;
         $translator_level = $user_meta->translator_level;
         $job_ids = Job::getJobs($user_id, $job_type, 'pending', $userlanguage, $gender, $translator_level);

         // Check Additional Conditions and Filter
         foreach ($job_ids as $k => $v) {
             try {
                 $job = Job::findOrFail($v->id);
                 $jobuserid = $job->user_id;
                 $checktown = Job::checkTowns($jobuserid, $user_id);

                 if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                     unset($job_ids[$k]);
                 }
             } catch (ModelNotFoundException $e) {
                 // Handle the case where a job is not found
                 // Log or perform any necessary actions
             }
         }

         // Convert Job IDs to Job Objects
         $jobs = TeHelper::convertJobIdsInObjs($job_ids);

         return $jobs;
     } catch (ModelNotFoundException $e) {
         // Handle the case where user metadata is not found
         // Log or perform any necessary actions
         return [];
     } catch (\Exception $e) {
         // Handle other exceptions
         // Log or perform any necessary actions
         return [];
     }
 }


    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::all();
        $translator_array = array();            // suitable translators (no need to delay push)
        $delpay_translator_array = array();     // suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $exclude_user_id) { // user is translator and he is not disabled
                if (!$this->isNeedToSendPush($oneUser->id)) continue;
                $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') continue;
                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user
                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) { // one potential job is the same with current job
                        $userId = $oneUser->id;
                        $job_for_translator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($job_for_translator == 'SpecificJob') {
                            $job_checker = Job::checkParticularJob($userId, $oneJob);
                            if (($job_checker != 'userCanNotAcceptJob')) {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delpay_translator_array[] = $oneUser;
                                } else {
                                    $translator_array[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = '';
        if ($data['immediate'] == 'no') {
            $msg_contents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        } else {
            $msg_contents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        }
        $msg_text = array(
            "en" => $msg_contents
        );

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);
        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
     public function sendSMSNotificationToTranslator($job)
 {
     try {
         // Get potential translators for the job
         $translators = $this->getPotentialTranslators($job);

         // Get job poster meta information
         $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

         // Prepare message templates
         $date = date('d.m.Y', strtotime($job->due));
         $time = date('H:i', strtotime($job->due));
         $duration = $this->convertToHoursMins($job->duration);
         $jobId = $job->id;
         $city = $job->city ? $job->city : $jobPosterMeta->city;

         $phoneJobMessageTemplate = trans('sms.phone_job', compact('date', 'time', 'duration', 'jobId'));
         $physicalJobMessageTemplate = trans('sms.physical_job', compact('date', 'time', 'city', 'duration', 'jobId'));

         // Determine the job type
         $isPhysicalJob = $job->customer_physical_type == 'yes';
         $isPhoneJob = $job->customer_phone_type == 'yes';

         // Decide on the message template
         $message = $isPhysicalJob ? $physicalJobMessageTemplate : $phoneJobMessageTemplate;

         // Log the message
         Log::info($message);

         // Send messages via SMS handler
         foreach ($translators as $translator) {
             // Send message to translator
             $status = SendSMSHelper::send(config('sms.sms_number'), $translator->mobile, $message);

             // Check for errors and log
             if ($status !== true) {
                 Log::error('Error sending SMS to ' . $translator->email . ' (' . $translator->mobile . '): ' . $status);
                 // You may consider throwing an exception here if you want to halt the process on the first error.
             } else {
                 Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
             }
         }

         // Return the count of translators
         return count($translators);
     } catch (\Exception $e) {
         // Log any unexpected exceptions
         Log::error('Exception caught: ' . $e->getMessage());
         // You may consider throwing or handling the exception based on your application's requirements.
         return 0; // Returning 0 to signify failure in case of an exception.
     }
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

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);
        if (env('APP_ENV') == 'prod') {
            $onesignalAppID = config('app.prodOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));
        } else {
            $onesignalAppID = config('app.devOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
        }

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        $fields = array(
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        );
        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }
        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * @param Job $job
     * @return mixed
     */
     public function getPotentialTranslators(Job $job): Collection
 {
     $translator_type = $this->getTranslatorType($job->job_type);
     $joblanguage = $job->from_language_id;
     $gender = $job->gender;
     $translator_level = $this->getTranslatorLevel($job->certified);

     $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
     $translatorsId = $blacklist->pluck('translator_id')->all();

     return User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $translatorsId);
 }

 private function getTranslatorType(string $jobType): string
 {
     switch ($jobType) {
         case 'paid':
             return 'professional';
         case 'rws':
             return 'rwstranslator';
         case 'unpaid':
             return 'volunteer';
         default:
             return ''; // or throw an exception for an unknown job type
     }
 }

 private function getTranslatorLevel(?string $certified): array
 {
     // ... Logic for determining translator level based on certification
     // Return an array of translator levels
 }


    /**
     * @param $id
     * @param $data
     * @return mixed
     */
     public function updateJob($id, $data, $cuser)
 {
     try {
         $job = Job::findOrFail($id);

         $currentTranslator = $this->getCurrentTranslator($job);
         $logData = [];

         $translatorChangeResult = $this->changeTranslator($currentTranslator, $data, $job);
         if ($translatorChangeResult['translatorChanged']) {
             $logData[] = $translatorChangeResult['log_data'];
         }

         $dueChangeResult = $this->changeDue($job->due, $data['due']);
         if ($dueChangeResult['dateChanged']) {
             $logData[] = $dueChangeResult['log_data'];
         }

         $langChanged = $this->changeLanguage($job, $data);
         if ($langChanged) {
             $logData[] = [
                 'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                 'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id']),
             ];
         }

         $statusChangeResult = $this->changeStatus($job, $data, $translatorChangeResult['translatorChanged']);
         if ($statusChangeResult['statusChanged']) {
             $logData[] = $statusChangeResult['log_data'];
         }

         $job->admin_comments = $data['admin_comments'];

         $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has updated booking #' . $id . ' with data: ', $logData);

         $job->reference = $data['reference'];
         $job->save();

         if ($job->due <= Carbon::now()) {
             return ['Updated'];
         }

         if ($dueChangeResult['dateChanged']) {
             $this->sendChangedDateNotification($job, $job->due);
         }

         if ($translatorChangeResult['translatorChanged']) {
             $this->sendChangedTranslatorNotification($job, $currentTranslator, $translatorChangeResult['new_translator']);
         }

         if ($langChanged) {
             $this->sendChangedLangNotification($job, $job->from_language_id);
         }

         return ['Updated'];
     } catch (\Exception $e) {
         // Log or handle the exception based on your application's requirements
         return ['error' => $e->getMessage()];
     }
 }

 // Helper function to get the current translator
 private function getCurrentTranslator($job)
 {
     return $job->translatorJobRel->where('cancel_at', null)->first() ?? $job->translatorJobRel->where('completed_at', '!=', null)->first();
 }

 // Other helper functions for specific updates...


    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
     private function changeStatus($job, $data, $changedTranslator)
 {
     $oldStatus = $job->status;
     $statusChanged = false;

     if ($oldStatus != $data['status']) {
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
                 break;
         }

         if ($statusChanged) {
             $logData = [
                 'old_status' => $oldStatus,
                 'new_status' => $data['status']
             ];

             return ['statusChanged' => $statusChanged, 'log_data' => $logData];
         }
     }

     // Return an indicator that the status was not changed
     return ['statusChanged' => false, 'log_data' => null];
 }


    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
//        if (in_array($data['status'], ['pending', 'assigned']) && date('Y-m-d H:i:s') <= $job->due) {
        $old_status = $job->status;
        $job->status = $data['status'];
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
        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

//        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout'])) {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
//        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'completed'])) {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') return false;
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            if ($data['sesion_time'] == '') return false;
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }
            $name = $user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

            $email = $user->user->email;
            $name = $user->user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

        }
        $job->save();
        return true;
//        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'assigned'])) {
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


//        }
        return false;
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
     public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
 {
     // Logger configuration should be done elsewhere, not within the method.

     $data = ['notification_type' => 'session_start_remind'];
     $dueExplode = explode(' ', $due);

     $commonMsg = 'Detta är en påminnelse om att du har en ' . $language . 'tolkning kl ' . $dueExplode[1] . ' på ' . $dueExplode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!';

     $msgText = ($job->customer_physical_type == 'yes')
         ? ['en' => $commonMsg . ' (på plats i ' . $job->town . ')']
         : ['en' => $commonMsg . ' (telefon)'];

     if ($this->bookingRepository->isNeedToSendPush($user->id)) {
         $usersArray = [$user];
         $this->bookingRepository->sendPushNotificationToSpecificUsers($usersArray, $job->id, $data, $msgText, $this->bookingRepository->isNeedToDelayPush($user->id));
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
     $email = $job->user_email ?: $user->email;
     $name = $user->name;
     $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag #' . $job->id;
     $data = [
         'user' => $user,
         'job'  => $job,
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
       $userTags = [];

       foreach ($users as $user) {
         $userTags[] = [
           'key' => 'email',
           'relation' => '=',
           'value' => strtolower($user->email),
         ];
       }

       return json_encode($userTags);
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
        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                } else {
                    $email = $user->email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                }
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

            }
            /*@todo
                add flash message here.
            */
            $jobs = $this->getPotentialJobs($cuser);
            $response = array();
            $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;

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
        $response = array();
        /*@todo
            add 24hrs loging here.
            If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
            if the cancelation is within 24
            if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
            so we must treat it as if it was an executed session
        */
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
                $response['jobstatus'] = 'success';
            } else {
                $job->status = 'withdrawafter24';
                $response['jobstatus'] = 'success';
            }
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';
            if ($translator) {
                $data = array();
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                );
                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = array($translator);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));// send Session Cancel Push to Translaotor
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->get()->first();
                if ($customer) {
                    $data = array();
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = array(
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    );
                    if ($this->isNeedToSendPush($customer->id)) {
                        $users_array = array($customer);
                        $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                    }
                }
                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                $job->save();
//                Event::fire(new JobWasCanceled($job));
                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);

                $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }
        return $response;
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cuser)
{
    // Retrieve user metadata
    $cuserMeta = $cuser->userMeta;

    // Set default job type to 'unpaid'
    $jobType = 'unpaid';

    // Determine job type based on translator type
    if ($cuserMeta->translator_type == 'professional') {
        $jobType = 'paid'; // Show all jobs for professionals
    } elseif ($cuserMeta->translator_type == 'rwstranslator') {
        $jobType = 'rws'; // For rwstranslator only show rws jobs
    } elseif ($cuserMeta->translator_type == 'volunteer') {
        $jobType = 'unpaid'; // For volunteers only show unpaid jobs
    }

    // Retrieve user languages
    $userLanguages = UserLanguages::where('user_id', $cuser->id)->pluck('lang_id')->all();

    // Retrieve other parameters
    $gender = $cuserMeta->gender;
    $translatorLevel = $cuserMeta->translator_level;

    // Get job IDs based on user and job type
    $jobIds = Job::getJobs($cuser->id, $jobType, 'pending', $userLanguages, $gender, $translatorLevel);

    // Filter jobs based on specific criteria
    foreach ($jobIds as $k => $job) {
        $jobUserId = $job->user_id;
        $job->specificJob = Job::assignedToPaticularTranslator($cuser->id, $job->id);
        $job->checkParticularJob = Job::checkParticularJob($cuser->id, $job);
        $checkTown = Job::checkTowns($jobUserId, $cuser->id);

        if ($job->specificJob == 'SpecificJob' && $job->checkParticularJob == 'userCanNotAcceptJob') {
            unset($jobIds[$k]);
        }

        if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') &&
            $job->customer_physical_type == 'yes' && $checkTown == false) {
            unset($jobIds[$k]);
        }
    }

    return array_values($jobIds); // Reset array keys
}


  public function endJob($post_data, AppMailer $mailer)
  {
      $completedDate = now();
      $jobId = $post_data["job_id"];
      $job = Job::with('translatorJobRel')->find($jobId);

      if ($job->status != 'started') {
          return ['status' => 'success'];
      }

      $dueDate = $job->due;
      $start = date_create($dueDate);
      $end = date_create($completedDate);
      $diff = date_diff($end, $start);
      $sessionTime = $diff->format('%h tim %i min');

      $this->sendEmail($job, $mailer, $sessionTime, 'faktura');
      $this->sendEmail($job, $mailer, $sessionTime, 'lön');

      $translator = $job->translatorJobRel()->where('completed_at', null)->where('cancel_at', null)->first();

      event(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $translator->user_id : $job->user_id));

      $translator->completed_at = $completedDate;
      $translator->completed_by = $post_data['user_id'];
      $translator->save();

      return ['status' => 'success'];
  }

  private function sendEmail($job, $mailer, $sessionTime, $forText)
  {
    $user = $job->user()->first();
    $email = $job->user_email ?: $user->email;
    $name = $user->name;
    $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

    $data = [
        'user' => $user,
        'job' => $job,
        'session_time' => $sessionTime,
        'for_text' => $forText,
    ];

    $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
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
        $consumerType = $cuser->consumer_type;

        if ($cuser && $cuser->user_type == config('constants.superadmin_role_id')) {
            $allJobs = $this->getAllJobsForSuperadmin($requestdata);
        } else {
            $allJobs = $this->getAllJobsForRegularUser($requestdata, $consumerType);
        }

        return $limit == 'all' ? $allJobs->get() : $allJobs->paginate(15);
    }

    private function getAllJobsForSuperadmin($requestdata)
    {
        $allJobs = Job::query();

        // Superadmin-specific logic here
        return $allJobs;
    }

    private function getAllJobsForRegularUser($requestdata, $consumerType)
    {
        $allJobs = Job::query();

        // Regular user-specific logic here
        return $allJobs;
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

    public function bookingExpireNoAccepted(Request $request)
{
    $languages = Language::where('active', 1)->orderBy('language')->get();

    $allJobs = Job::with('language')
        ->where('ignore_expired', 0)
        ->where('status', 'pending')
        ->where('due', '>=', $request->carbon('now'));

    // Apply filters based on $request data...

    $allJobs = $allJobs->orderBy('created_at', 'desc')->paginate(15);

    $allCustomers = User::where('user_type', 1)->pluck('email');
    $allTranslators = User::where('user_type', 2)->pluck('email');

    return [
        'allJobs' => $allJobs,
        'languages' => $languages,
        'allCustomers' => $allCustomers,
        'allTranslators' => $allTranslators,
        'requestdata' => $request->all(),
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

    public function reopen(Request $request)
    {
        $jobId = $request['jobid'];
        $userId = $request['userid'];

        $job = Job::find($jobId)->toArray();

        $dataReopen = [
            'status' => 'pending',
            'created_at' => $request->carbon('now'),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], $request->carbon('now')),
        ];

        $jobStatus = $job['status'];
        $job['updated_at'] = $request->carbon('now');
        $job['cust_16_hour_email'] = 0;
        $job['cust_48_hour_email'] = 0;
        $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobId;

        if ($jobStatus != 'timedout') {
            Job::where('id', $jobId)->update($dataReopen);
            $newJobId = $jobId;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = $request->carbon('now');
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], $request->carbon('now'));

            $newJob = Job::create($job);
            $newJobId = $newJob->id;
        }

        Translator::where('job_id', $jobId)->where('cancel_at', null)->update(['cancel_at' => $dataReopen['created_at']]);
        Translator::create([
            'status' => 'cancelled',
            'created_at' => $dataReopen['created_at'],
            'will_expire_at' => $dataReopen['will_expire_at'],
            'updated_at' => $request->carbon('now'),
            'user_id' => $userId,
            'job_id' => $jobId,
            'cancel_at' => $dataReopen['created_at'],
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
