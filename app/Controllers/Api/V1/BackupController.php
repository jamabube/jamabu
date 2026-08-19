<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\BackupRepository;
use App\Responses\ApiResponse;
use App\Services\BackupService;

/**
 * Backup and restore administration.
 *
 * A restore replaces the live database, so it is the single most destructive
 * operation the system offers. It takes a snapshot of the current state first,
 * without being asked, because the moment somebody needs that snapshot is the
 * moment they will not have thought to take one.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class BackupController extends Controller
{
    /**
     * GET /api/v1/backups
     */
    public function index(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'created_at');
        $paginator = $this->service(BackupRepository::class)->paginate([
            'search'      => $request->string('search'),
            'status'      => $request->string('status'),
            'backup_type' => $request->string('backup_type'),
            'scope'       => $request->string('scope'),
        ], $options);

        return $this->paginated('Backups retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/backups/summary
     */
    public function summary(Request $request): JsonResponse
    {
        return $this->json('Backup summary retrieved.', $this->service(BackupService::class)->summary());
    }

    /**
     * POST /api/v1/backups
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->validate($request, [
            'include_uploads' => 'nullable|boolean',
        ], [
            'include_uploads' => 'Include uploaded files',
        ]);

        $result = $this->service(BackupService::class)->create(
            'manual',
            $this->auth->id(),
            filter_var($payload['include_uploads'] ?? false, FILTER_VALIDATE_BOOLEAN)
        );

        return ApiResponse::created('The backup was created.', $result);
    }

    /**
     * GET /api/v1/backups/{id}/download
     */
    public function download(Request $request): Response
    {
        $archive = $this->service(BackupService::class)->download($request->routeInt('id'));

        return Response::download($archive['contents'], $archive['filename'], $archive['mime']);
    }

    /**
     * POST /api/v1/backups/{id}/restore
     *
     * The confirmation phrase is required in the body rather than being a
     * click-through, so a restore cannot be triggered by a mistyped URL or a
     * replayed request.
     */
    public function restore(Request $request): JsonResponse
    {
        $backupId = $request->routeInt('id');

        $payload = $this->validate($request, [
            'confirmation'   => 'required|string|max:40',
            'snapshot_first' => 'nullable|boolean',
        ], [
            'confirmation'   => 'Confirmation phrase',
            'snapshot_first' => 'Snapshot the current database first',
        ]);

        if (strtoupper(trim((string) $payload['confirmation'])) !== 'RESTORE') {
            return ApiResponse::validationFailed([
                'confirmation' => ['Type RESTORE to confirm that the live database will be replaced.'],
            ]);
        }

        $this->service(BackupService::class)->restore(
            $backupId,
            (int) $this->auth->id(),
            filter_var($payload['snapshot_first'] ?? true, FILTER_VALIDATE_BOOLEAN)
        );

        return $this->json('The database was restored from the archive.', ['backup_id' => $backupId]);
    }

    /**
     * DELETE /api/v1/backups/{id}
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->service(BackupService::class)->delete($request->routeInt('id'), (int) $this->auth->id());

        return ApiResponse::deleted('The archive was removed.');
    }

    /**
     * POST /api/v1/backups/reconcile
     *
     * Compares the register against the archives actually on disk, for the case
     * a file was moved or removed outside the application.
     */
    public function reconcile(Request $request): JsonResponse
    {
        return $this->json('The backup register was reconciled.', $this->service(BackupService::class)->reconcile());
    }
}
