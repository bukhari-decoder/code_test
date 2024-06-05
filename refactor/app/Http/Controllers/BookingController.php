<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;
use Illuminate\Http\JsonResponse;

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
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user_id = $request->get('user_id');

        if ($user_id) {
            $response = $this->repository->getUsersJobs($user_id);
        } elseif ($this->isAdmin($request)) {
            $response = $this->repository->getAll($request);
        }

        return response()->json($response);
    }

    /**
     * @param $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);

        return response()->json($job);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->all();
        $response = $this->repository->store($request->__authenticatedUser, $data);

        return response()->json($response);
    }

    /**
     * @param $id
     * @param Request $request
     * @return JsonResponse
     */
    public function update($id, Request $request): JsonResponse
    {
        $data = $request->except(['_token', 'submit']);
        $response = $this->repository->updateJob($id, $data, $request->__authenticatedUser);

        return response()->json($response);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function immediateJobEmail(Request $request): JsonResponse
    {
        $data = $request->all();
        $response = $this->repository->storeJobEmail($data);

        return response()->json($response);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function getHistory(Request $request): JsonResponse
    {
        $user_id = $request->get('user_id');

        if ($user_id) {
            $response = $this->repository->getUsersJobsHistory($user_id, $request);
            return response()->json($response);
        }

        return response()->json(null, 204);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function acceptJob(Request $request): JsonResponse
    {
        $data = $request->all();
        $response = $this->repository->acceptJob($data, $request->__authenticatedUser);

        return response()->json($response);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function acceptJobWithId(Request $request): JsonResponse
    {
        $job_id = $request->get('job_id');
        $response = $this->repository->acceptJobWithId($job_id, $request->__authenticatedUser);

        return response()->json($response);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function cancelJob(Request $request): JsonResponse
    {
        $data = $request->all();
        $response = $this->repository->cancelJobAjax($data, $request->__authenticatedUser);

        return response()->json($response);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function endJob(Request $request): JsonResponse
    {
        $data = $request->all();
        $response = $this->repository->endJob($data);

        return response()->json($response);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function customerNotCall(Request $request): JsonResponse
    {
        $data = $request->all();
        $response = $this->repository->customerNotCall($data);

        return response()->json($response);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function getPotentialJobs(Request $request): JsonResponse
    {
        $response = $this->repository->getPotentialJobs($request->__authenticatedUser);

        return response()->json($response);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function distanceFeed(Request $request): JsonResponse
    {
        $data = $request->all();

        $distance = $data['distance'] ?? '';
        $time = $data['time'] ?? '';
        $jobid = $data['jobid'] ?? '';
        $session = $data['session_time'] ?? '';
        $flagged = $data['flagged'] == 'true' ? 'yes' : 'no';
        $manuallyHandled = $data['manually_handled'] == 'true' ? 'yes' : 'no';
        $byAdmin = $data['by_admin'] == 'true' ? 'yes' : 'no';
        $adminComment = $data['admincomment'] ?? '';

        if ($flagged === 'yes' && empty($adminComment)) {
            return response()->json(['error' => 'Please, add comment'], 400);
        }

        if ($time || $distance) {
            Distance::where('job_id', $jobid)->update(compact('distance', 'time'));
        }

        if ($adminComment || $session || $flagged || $manuallyHandled || $byAdmin) {
            Job::where('id', $jobid)->update(compact('adminComment', 'session', 'flagged', 'manuallyHandled', 'byAdmin'));
        }

        return response()->json(['success' => 'Record updated!']);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function reopen(Request $request): JsonResponse
    {
        $data = $request->all();
        $response = $this->repository->reopen($data);

        return response()->json($response);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function resendNotifications(Request $request): JsonResponse
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $jobData = $this->repository->jobToData($job);
        $this->repository->sendNotificationTranslator($job, $jobData, '*');

        return response()->json(['success' => 'Push sent']);
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return JsonResponse
     */
    public function resendSMSNotifications(Request $request): JsonResponse
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $jobData = $this->repository->jobToData($job);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return response()->json(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Check if the user is an admin.
     *
     * @param Request $request
     * @return bool
     */
    protected function isAdmin(Request $request): bool
    {
        return in_array($request->__authenticatedUser->user_type, [
            env('ADMIN_ROLE_ID'),
            env('SUPERADMIN_ROLE_ID')
        ]);
    }
}
