<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Services\SettingsService;

/**
 * Runtime settings administration.
 *
 * Each setting carries its own validation rule and type in the database, so
 * the rules a value is checked against travel with the value rather than being
 * duplicated here. Sensitive values are masked on the way out and redacted in
 * the audit entry they produce.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class SettingsController extends Controller
{
    /**
     * GET /api/v1/settings
     */
    public function index(Request $request): JsonResponse
    {
        return $this->json('Settings retrieved.', $this->service(SettingsService::class)->groupedForDisplay());
    }

    /**
     * PUT /api/v1/settings
     *
     * Accepts a partial map. Unknown and read-only keys are ignored rather
     * than rejected, so a form that posts a field the deployment does not have
     * still saves everything else.
     */
    public function update(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $submitted */
        $submitted = $request->array('settings');

        if ($submitted === []) {
            return $this->failure('NOTHING_SUBMITTED', 'No settings were supplied.', 422);
        }

        $changed = $this->service(SettingsService::class)->updateMany(
            $submitted,
            $this->auth->id(),
            $this->validator
        );

        return $this->json(
            $changed === [] ? 'No settings needed changing.' : 'The settings were saved.',
            ['changed' => $changed],
            200,
            ['changed_count' => count($changed)]
        );
    }

    /**
     * POST /api/v1/settings/{key}/reset
     */
    public function reset(Request $request): JsonResponse
    {
        $key = (string) $request->route('key', '');

        $this->service(SettingsService::class)->resetToDefault($key, $this->auth->id());

        return $this->json('The setting was restored to its default.', ['key' => $key]);
    }
}
