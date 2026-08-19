<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\DashboardService;

/**
 * The dashboard page.
 *
 * The first render carries a complete snapshot so the screen is useful before
 * any script has run; the polling endpoint then keeps it current. A guardhouse
 * monitor that shows nothing until an AJAX call returns is a monitor that
 * shows nothing when the network hiccups.
 *
 * @package App\Controllers\Web
 * @version 1.0.0
 */
final class DashboardController extends Controller
{
    /**
     * GET /
     */
    public function index(Request $request): Response
    {
        return $this->render('pages/dashboard/index', [
            'title'     => 'Dashboard',
            'dashboard' => $this->service(DashboardService::class)->assemble(),
            'refresh'   => (int) config('monitoring.live.dashboard_refresh', 15),
        ]);
    }
}
