<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\Setting;
use App\Services\ActivityService;
use Illuminate\Http\JsonResponse;

class AdminSettingController extends Controller
{
    public function __construct(
        protected ActivityService $activityService
    ) {}

    // GET /api/v1/admin/settings

    /**
     * List runtime settings
     *
     * Values the platform reads at runtime — the crypto wallet address, credit
     * costs, payment expiry. Changing these takes effect without a deploy.
     *
     * @authenticated
     *
     * @group Admin — Settings
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Settings retrieved successfully",
     *   "data": [
     *     {
     *       "key": "credit_cost_deep_scan",
     *       "value": 10,
     *       "type": "integer",
     *       "description": "Credits charged for a Shopify deep scan"
     *     },
     *     {
     *       "key": "crypto_wallet_address",
     *       "value": "TXyz...",
     *       "type": "string",
     *       "description": "TRC20 USDT wallet payments are sent to"
     *     }
     *   ]
     * }
     * @response 403 {"success": false, "message": "Unauthorized. Admin access required.", "errors": null}
     */
    public function index(): JsonResponse
    {
        $settings = Setting::query()
            ->orderBy('key')
            ->get()
            ->map(fn (Setting $setting): array => [
                'key' => $setting->key,
                // Read through the accessor so the response reflects the cast type
                // rather than the raw string column.
                'value' => Setting::get($setting->key),
                'type' => $setting->type,
                'description' => $setting->description,
            ])
            ->all();

        return $this->successResponse(
            data: $settings,
            message: 'Settings retrieved successfully'
        );
    }

    // PUT /api/v1/admin/settings

    /**
     * Update runtime settings
     *
     * Accepts a batch of key/value pairs. Only keys that already exist can be
     * written: the application reads specific keys by name, so an unrecognised key
     * would silently do nothing while appearing to succeed.
     *
     * Each value is validated against the type stored on its row, and the cached
     * value is invalidated so the change is live immediately.
     *
     * @authenticated
     *
     * @group Admin — Settings
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Settings updated successfully",
     *   "data": {"updated": ["crypto_wallet_address", "credit_cost_deep_scan"]}
     * }
     * @response 422 {
     *   "success": false,
     *   "message": "Validation failed",
     *   "errors": {
     *     "settings.0.value": ["The value for credit_cost_deep_scan must be of type integer."]
     *   }
     * }
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $updated = [];

        foreach ($request->validated('settings') as $row) {
            $type = Setting::where('key', $row['key'])->value('type') ?? 'string';

            // Setting::set writes the row and forgets the cache key.
            Setting::set($row['key'], $row['value'], $type);
            $updated[] = $row['key'];
        }

        $this->activityService->log(
            userId: $request->user()->id,
            type: 'admin_settings_updated',
            description: 'Updated runtime settings: '.implode(', ', $updated),
            metadata: ['keys' => $updated],
            request: $request
        );

        return $this->successResponse(
            data: ['updated' => $updated],
            message: 'Settings updated successfully'
        );
    }
}
