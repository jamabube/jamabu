<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Services\SearchService;

/**
 * Global search.
 *
 * The guardhouse does not know which module a plate number, a name or a UID
 * belongs to; it just wants an answer. One field searches everything the
 * signed-in user is permitted to see, and results the user cannot open are
 * never returned in the first place.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class SearchController extends Controller
{
    /**
     * GET /api/v1/search
     */
    public function index(Request $request): JsonResponse
    {
        $term = trim($request->string('q'));

        if (mb_strlen($term) < 2) {
            return $this->json('Enter at least two characters to search.', [
                'term'    => $term,
                'groups'  => [],
                'total'   => 0,
            ]);
        }

        $results = $this->service(SearchService::class)->search($term);

        return $this->json('Search complete.', $results);
    }

    /**
     * GET /api/v1/search/quick
     *
     * The type-ahead list behind the header search box.
     */
    public function quick(Request $request): JsonResponse
    {
        $term = trim($request->string('q'));

        if (mb_strlen($term) < 2) {
            return $this->json('Enter at least two characters to search.', []);
        }

        $limit = min(25, max(1, $request->integer('limit', 10)));

        return $this->json('Suggestions retrieved.', $this->service(SearchService::class)->quick($term, $limit));
    }
}
