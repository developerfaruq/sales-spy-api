<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminActivityController extends Controller
{
    // GET /api/v1/admin/activities

    /**
     * Read the activity audit log
     *
     * Paginated view of `user_activities` across all users, newest first.
     * Filterable by user and activity type.
     *
     * @authenticated
     *
     * @group Admin — Dashboard
     *
     * @queryParam page integer Page number. Example: 1
     * @queryParam per_page integer Results per page, max 100. Example: 25
     * @queryParam user_id integer Only this user's activity. Example: 4
     * @queryParam type string Only this activity type. Example: password_reset_completed
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Activities retrieved successfully",
     *   "data": [
     *     {
     *       "id": 918,
     *       "user_id": 4,
     *       "user_email": "john@example.com",
     *       "type": "password_reset_completed",
     *       "description": "Completed a password reset and signed out all sessions",
     *       "ip_address": "203.0.113.9",
     *       "metadata": null,
     *       "created_at": "2026-08-16T17:12:44.000000Z"
     *     }
     *   ],
     *   "meta": {"current_page": 1, "last_page": 12, "per_page": 25, "total": 288}
     * }
     * @response 403 {"success": false, "message": "Admin access required.", "errors": null}
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 25), 1), 100);

        $query = UserActivity::query()
            // Eager loaded so listing N rows does not issue N user lookups.
            ->with('user:id,email')
            ->latest('created_at')
            ->latest('id');

        if ($userId = $request->get('user_id')) {
            $query->where('user_id', (int) $userId);
        }

        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        $activities = $query->paginate($perPage);

        return $this->successResponse(
            data: $activities->getCollection()->map(fn (UserActivity $activity): array => [
                'id' => $activity->id,
                'user_id' => $activity->user_id,
                'user_email' => $activity->user?->email,
                'type' => $activity->type,
                'description' => $activity->description,
                'ip_address' => $activity->ip_address,
                'metadata' => $activity->metadata,
                'created_at' => $activity->created_at,
            ])->all(),
            message: 'Activities retrieved successfully',
            meta: [
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'per_page' => $activities->perPage(),
                'total' => $activities->total(),
            ]
        );
    }
}
