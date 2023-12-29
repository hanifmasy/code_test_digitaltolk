<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;
use app\Models\UserRoles;
use Illuminate\Support\Facades\Validator;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        if($user_id = $request->get('user_id')) {

            $response = $this->repository->getUsersJobs($user_id);

        }
        elseif($request->__authenticatedUser->user_type == UserRoles::ADMIN_ROLE_ID || $request->__authenticatedUser->user_type == UserRoles::SUPERADMIN_ROLE_ID)
        {
            $response = $this->repository->getAll($request);
        }

        return response($response);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);

        return response($job);
    }

    /**
     * @param Request $request
     * @return mixed
     */
     public function store(Request $request)
     {
     try {
         $data = $request->all();
         $authenticatedUser = $request->__authenticatedUser;

         $response = $this->repository->store($authenticatedUser, $data);

         if ($response) {
             return response($response);
         } else {
             return response(['error' => 'Failed to create.'], 500);
         }
     } catch (\Exception $e) {
         // Log the exception for further investigation
         \Log::error('Error in store method: ' . $e->getMessage());

         return response(['error' => 'An unexpected error occurred.'], 500);
     }
   }

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
     public function update($id, Request $request)
     {
     try {
         // Verify required data in the request
         $requiredFields = ['field1', 'field2']; // Add the required field names
         foreach ($requiredFields as $field) {
             if (!array_key_exists($field, $request->all())) {
                 return response(['error' => 'Required field ' . $field . ' is missing.'], 400);
             }
         }

         $data = $request->all();
         $cuser = $request->__authenticatedUser;

         $response = $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $cuser);

         if ($response) {
             return response($response);
         } else {
             return response(['error' => 'Failed to update.'], 500);
         }
     } catch (\Exception $e) {
         // Log the exception for further investigation
         \Log::error('Error in update method: ' . $e->getMessage());

         return response(['error' => 'An unexpected error occurred.'], 500);
     }
   }

    /**
     * @param Request $request
     * @return mixed
     */
     public function immediateJobEmail(Request $request)
     {
     try {
         // Validate input data
         $validator = Validator::make($request->all(), [
             'key1' => 'required',
             'key2' => 'required',
             // Add other validation rules as needed
         ]);

         if ($validator->fails()) {
             return response(['error' => $validator->errors()], 400);
         }

         $adminSenderEmail = config('app.adminemail');
         $data = $request->all();

         $response = $this->repository->storeJobEmail($data);

         if ($response) {
             return response($response);
         } else {
             return response(['error' => 'Failed to process job email.'], 500);
         }
     } catch (\Exception $e) {
         // Log the exception for further investigation
         \Log::error('Error in immediateJobEmail method: ' . $e->getMessage());

         return response(['error' => 'An unexpected error occurred.'], 500);
     }
   }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        if($user_id = $request->get('user_id')) {

            $response = $this->repository->getUsersJobsHistory($user_id, $request);
            return response($response);
        }

        return null;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        $response = $this->repository->acceptJob($data, $user);

        return response($response);
    }

    public function acceptJobWithId(Request $request)
    {
        $data = $request->get('job_id');
        $user = $request->__authenticatedUser;

        $response = $this->repository->acceptJobWithId($data, $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        $response = $this->repository->cancelJobAjax($data, $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $data = $request->all();

        $response = $this->repository->endJob($data);

        return response($response);

    }

    public function customerNotCall(Request $request)
    {
        $data = $request->all();

        $response = $this->repository->customerNotCall($data);

        return response($response);

    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        $response = $this->repository->getPotentialJobs($user);

        return response($response);
    }


    public function distanceFeed(Request $request)
    {
    try {
        // Validate input data
        $validator = Validator::make($request->all(), [
            'distance' => 'sometimes|required',
            'time' => 'sometimes|required',
            'jobid' => 'required',
            'session_time' => 'sometimes|required',
            'flagged' => 'required|in:true,false',
            'manually_handled' => 'required|in:true,false',
            'by_admin' => 'required|in:true,false',
            'admincomment' => 'required_if:flagged,true',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 400);
        }

        $data = $request->all();

        $distance = $data['distance'] ?? '';
        $time = $data['time'] ?? '';
        $jobid = $data['jobid'];

        $session = $data['session_time'] ?? '';
        $flagged = $data['flagged'] === 'true' ? 'yes' : 'no';
        $manually_handled = $data['manually_handled'] === 'true' ? 'yes' : 'no';
        $by_admin = $data['by_admin'] === 'true' ? 'yes' : 'no';
        $admincomment = $data['admincomment'] ?? '';

        if ($time || $distance) {
            Distance::where('job_id', '=', $jobid)->update(['distance' => $distance, 'time' => $time]);
        }

        if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {
            Job::where('id', '=', $jobid)->update([
                'admin_comments' => $admincomment,
                'flagged' => $flagged,
                'session_time' => $session,
                'manually_handled' => $manually_handled,
                'by_admin' => $by_admin,
            ]);
        }

        return response('Record updated!');
      } catch (\Exception $e) {
        // Log the exception for further investigation
        \Log::error('Error in distanceFeed method: ' . $e->getMessage());

        return response(['error' => 'An unexpected error occurred.'], 500);
      }
    }


    public function reopen(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->reopen($data);

        return response($response);
    }

    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);
        $this->repository->sendNotificationTranslator($job, $job_data, '*');

        return response(['success' => 'Push sent']);
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }

}
