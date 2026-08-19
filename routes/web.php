<?php

declare(strict_types=1);

/**
 * Browser routes.
 *
 * Every page is a shell plus the reference data its forms need; the tables and
 * live panels are filled from the JSON API in routes/api.php. That split keeps
 * one query behind each screen instead of two, and it means a page and the
 * export of the same data can never disagree.
 *
 * Route names here match the entries in config/navigation.php — the sidebar is
 * generated from that file, so a page cannot appear in the menu without a
 * route to reach it.
 *
 * @var \App\Core\Routing\Router $router
 */

use App\Controllers\Web\AdministrationController;
use App\Controllers\Web\AuthController;
use App\Controllers\Web\DashboardController;
use App\Controllers\Web\DeviceController;
use App\Controllers\Web\FingerprintController;
use App\Controllers\Web\GovernanceController;
use App\Controllers\Web\MonitoringController;
use App\Controllers\Web\NotificationController;
use App\Controllers\Web\ProfileController;
use App\Controllers\Web\RegistryController;
use App\Controllers\Web\ReportController;
use App\Controllers\Web\RfidController;
use App\Controllers\Web\SearchController;
use App\Controllers\Web\VisitorController;
use App\Middleware\AuthenticateMiddleware;
use App\Middleware\AuthorizeMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\SessionMiddleware;

// ---------------------------------------------------------------------------
// Guest
// ---------------------------------------------------------------------------

$router->group([
    'middleware' => [SessionMiddleware::class, CsrfMiddleware::class, GuestMiddleware::class],
], static function ($router): void {
    $router->get('/login', [AuthController::class, 'showLogin'])->name('login');
    $router->post('/login', [AuthController::class, 'login'])->name('login.submit');
});

// ---------------------------------------------------------------------------
// Authenticated
// ---------------------------------------------------------------------------

$router->group([
    'middleware' => [
        SessionMiddleware::class,
        AuthenticateMiddleware::class,
        CsrfMiddleware::class,
        AuthorizeMiddleware::class,
    ],
], static function ($router): void {
    // Sign-out and the forced password change carry no permission: an account
    // whose password has expired must still be able to leave or fix it.
    $router->post('/logout', [AuthController::class, 'logout'])->name('logout');
    $router->get('/profile/password', [AuthController::class, 'showChangePassword'])->name('profile.password');
    $router->post('/profile/password', [AuthController::class, 'changePassword'])->name('profile.password.submit');

    $router->get('/', [DashboardController::class, 'index'])
        ->permission('dashboard.view')->name('dashboard');

    // -- Monitoring ---------------------------------------------------------
    $router->get('/monitoring/live', [MonitoringController::class, 'live'])
        ->permission('monitoring.view')->name('monitoring.live');
    $router->get('/monitoring/history', [MonitoringController::class, 'history'])
        ->permission('monitoring.view')->name('monitoring.history');
    $router->get('/monitoring/inside', [MonitoringController::class, 'inside'])
        ->permission('monitoring.view')->name('monitoring.inside');
    $router->get('/monitoring/denials', [MonitoringController::class, 'denials'])
        ->permission('monitoring.view')->name('monitoring.denials');
    $router->get('/monitoring/{id}', [MonitoringController::class, 'show'])
        ->whereNumber('id')->permission('monitoring.view')->name('monitoring.show');

    // -- Registry -----------------------------------------------------------
    $router->get('/vehicles', [RegistryController::class, 'vehicles'])
        ->permission('vehicles.view')->name('vehicles.index');
    $router->get('/vehicles/{id}', [RegistryController::class, 'vehicle'])
        ->whereNumber('id')->permission('vehicles.view')->name('vehicles.show');

    $router->get('/drivers', [RegistryController::class, 'drivers'])
        ->permission('drivers.view')->name('drivers.index');
    $router->get('/drivers/{id}', [RegistryController::class, 'driver'])
        ->whereNumber('id')->permission('drivers.view')->name('drivers.show');

    $router->get('/owners', [RegistryController::class, 'owners'])
        ->permission('owners.view')->name('owners.index');
    $router->get('/owners/{id}', [RegistryController::class, 'owner'])
        ->whereNumber('id')->permission('owners.view')->name('owners.show');

    $router->get('/visitors', [VisitorController::class, 'index'])
        ->permission('visitors.view')->name('visitors.index');
    $router->get('/visitors/{id}', [VisitorController::class, 'show'])
        ->whereNumber('id')->permission('visitors.view')->name('visitors.show');

    $router->get('/rfid/tags', [RfidController::class, 'tags'])
        ->permission('rfid.view')->name('rfid.tags.index');
    $router->get('/rfid/cards', [RfidController::class, 'cards'])
        ->permission('rfid.view')->name('rfid.cards.index');

    $router->get('/fingerprints', [FingerprintController::class, 'index'])
        ->permission('fingerprints.view')->name('fingerprints.index');

    // -- Infrastructure -----------------------------------------------------
    $router->get('/devices', [DeviceController::class, 'index'])
        ->permission('devices.view')->name('devices.index');
    $router->get('/devices/{id}', [DeviceController::class, 'show'])
        ->whereNumber('id')->permission('devices.view')->name('devices.show');

    $router->get('/health', [GovernanceController::class, 'health'])
        ->permission('system.health')->name('health.index');

    // -- Insight ------------------------------------------------------------
    // "analytics" is registered before "{key}" so the fixed path wins.
    $router->get('/reports', [ReportController::class, 'index'])
        ->permission('reports.view')->name('reports.index');
    $router->get('/reports/analytics', [ReportController::class, 'analytics'])
        ->permission('reports.view')->name('reports.analytics');
    $router->get('/reports/{key}', [ReportController::class, 'show'])
        ->where('key', '[a-z0-9_-]+')
        ->permission('reports.generate')->name('reports.show');

    $router->get('/notifications', [NotificationController::class, 'index'])
        ->permission('notifications.view')->name('notifications.index');

    // -- Governance ---------------------------------------------------------
    $router->get('/audit', [GovernanceController::class, 'audit'])
        ->permission('audit.view')->name('audit.index');
    $router->get('/security', [GovernanceController::class, 'security'])
        ->permission('security.view')->name('security.index');
    $router->get('/errors', [GovernanceController::class, 'errors'])
        ->permission('errors.view')->name('errors.index');
    $router->get('/api-management', [GovernanceController::class, 'api'])
        ->permission('api.manage')->name('api.manage');

    // -- Administration -----------------------------------------------------
    $router->get('/users', [AdministrationController::class, 'users'])
        ->permission('users.view')->name('users.index');
    $router->get('/users/{id}', [AdministrationController::class, 'user'])
        ->whereNumber('id')->permission('users.view')->name('users.show');

    $router->get('/roles', [AdministrationController::class, 'roles'])
        ->permission('roles.view')->name('roles.index');
    $router->get('/roles/{id}', [AdministrationController::class, 'role'])
        ->whereNumber('id')->permission('roles.view')->name('roles.show');

    $router->get('/permissions', [AdministrationController::class, 'permissions'])
        ->permission('permissions.view')->name('permissions.index');
    $router->get('/departments', [AdministrationController::class, 'departments'])
        ->permission('users.view')->name('departments.index');
    $router->get('/settings', [AdministrationController::class, 'settings'])
        ->permission('settings.view')->name('settings.index');
    $router->get('/backups', [AdministrationController::class, 'backups'])
        ->permission('backup.view')->name('backups.index');

    // -- Personal -----------------------------------------------------------
    $router->get('/profile', [ProfileController::class, 'show'])
        ->permission('profile.view')->name('profile');
    $router->post('/profile', [ProfileController::class, 'update'])
        ->permission('profile.update')->name('profile.update');

    $router->get('/search', [SearchController::class, 'index'])->name('search');
});
