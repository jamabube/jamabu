<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\SearchService;

/**
 * Global search results page.
 *
 * @package App\Controllers\Web
 * @version 1.0.0
 */
final class SearchController extends Controller
{
    /**
     * GET /search
     */
    public function index(Request $request): Response
    {
        $term = trim($request->string('q'));

        return $this->render('pages/search/index', [
            'title'   => $term === '' ? 'Search' : sprintf('Search: %s', $term),
            'term'    => $term,
            'results' => mb_strlen($term) < 2 ? null : $this->service(SearchService::class)->search($term),
        ]);
    }
}
