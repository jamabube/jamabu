<?php

declare(strict_types=1);

/**
 * REST API routes, version 1.
 *
 * Two populations use this API and they authenticate in completely different
 * ways, so they are kept in separate groups:
 *
 *   /api/v1/device/*  — the ESP32 stations. Signed with an HMAC over the
 *                       request, no session, no cookies, no CSRF.
 *   /api/v1/*         — the dashboard's own AJAX. Session-authenticated,
 *                       CSRF-protected, permission-checked per route.
 *
 * Every route declares the permission it requires. A route with no permission
 * is reachable by any authenticated user, and that is a decision recorded here
 * rather than an omission — the security:audit command checks this file for
 * exactly that.
 *
 * @var \App\Core\Routing\Router $router
 */

use App\Controllers\Api\V1\AccessController;
use App\Controllers\Api\V1\ApiLogController;
use App\Controllers\Api\V1\AuditController;
use App\Controllers\Api\V1\AuthController;
use App\Controllers\Api\V1\BackupController;
use App\Controllers\Api\V1\DashboardController;
use App\Controllers\Api\V1\DeviceController;
use App\Controllers\Api\V1\DeviceManagementController;
use App\Controllers\Api\V1\DriverController;
use App\Controllers\Api\V1\ErrorLogController;
use App\Controllers\Api\V1\FingerprintController;
use App\Controllers\Api\V1\HealthController;
use App\Controllers\Api\V1\MonitoringController;
use App\Controllers\Api\V1\NotificationController;
use App\Controllers\Api\V1\OwnerController;
use App\Controllers\Api\V1\ReferenceController;
use App\Controllers\Api\V1\ReportController;
use App\Controllers\Api\V1\RfidController;
use App\Controllers\Api\V1\RoleController;
use App\Controllers\Api\V1\SearchController;
use App\Controllers\Api\V1\SecurityController;
use App\Controllers\Api\V1\SettingsController;
use App\Controllers\Api\V1\UserController;
use App\Controllers\Api\V1\VehicleController;
use App\Controllers\Api\V1\VisitorController;
use App\Middleware\AuthenticateMiddleware;
use App\Middleware\AuthorizeMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\DeviceAuthenticationMiddleware;
use App\Middleware\JsonRequestMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\SessionMiddleware;

// ---------------------------------------------------------------------------
// Unauthenticated
// ---------------------------------------------------------------------------

/*
 * The liveness probe is intentionally open and intentionally thin: an uptime
 * monitor must be able to reach it without credentials, and it reveals only
 * whether the application can serve and reach its database.
 */
$router->get('/api/v1/health', [HealthController::class, 'liveness'])
    ->name('api.health');

// ---------------------------------------------------------------------------
// Device API — the ESP32 monitoring stations
// ---------------------------------------------------------------------------

$router->group([
    'prefix'     => 'api/v1/device',
    'name'       => 'api.device.',
    'middleware' => [
        JsonRequestMiddleware::class,
        DeviceAuthenticationMiddleware::class,
        RateLimitMiddleware::class,
    ],
], static function ($router): void {
    $router->post('/authenticate', [DeviceController::class, 'authenticate'])
        ->throttle('device-auth')
        ->name('authenticate');

    $router->get('/configuration', [DeviceController::class, 'configuration'])
        ->name('configuration');

    $router->post('/heartbeat', [DeviceController::class, 'heartbeat'])
        ->throttle('device-heartbeat')
        ->name('heartbeat');

    $router->get('/status', [DeviceController::class, 'status'])
        ->name('status');

    $router->post('/error', [DeviceController::class, 'error'])
        ->name('error');

    $router->post('/fingerprint/verify', [DeviceController::class, 'verifyFingerprint'])
        ->name('fingerprint.verify');

    $router->post('/fingerprint/sign-out', [DeviceController::class, 'signOutOperator'])
        ->name('fingerprint.sign-out');

    $router->post('/fingerprint/synchronise', [DeviceController::class, 'synchroniseFingerprints'])
        ->name('fingerprint.synchronise');

    /*
     * The three that record movement. They share the access-scan bucket, which
     * is sized for a busy gate rather than for a browser.
     */
    $router->post('/access/entry', [AccessController::class, 'entry'])
        ->throttle('access-scan')
        ->name('access.entry');

    $router->post('/access/exit', [AccessController::class, 'exit'])
        ->throttle('access-scan')
        ->name('access.exit');

    $router->post('/access/scan', [AccessController::class, 'scan'])
        ->throttle('access-scan')
        ->name('access.scan');
});

// ---------------------------------------------------------------------------
// Session API — the dashboard's own AJAX
// ---------------------------------------------------------------------------

/*
 * Sign-in and password assessment sit outside the authenticated group for the
 * obvious reason, but still inside the session and CSRF middleware: the login
 * form is posted from a page this application rendered.
 */
$router->group([
    'prefix'     => 'api/v1',
    'name'       => 'api.',
    'middleware' => [SessionMiddleware::class, CsrfMiddleware::class, RateLimitMiddleware::class],
], static function ($router): void {
    $router->post('/login', [AuthController::class, 'login'])
        ->throttle('login')
        ->name('login');

    $router->post('/password/strength', [AuthController::class, 'passwordStrength'])
        ->throttle('password-reset')
        ->name('password.strength');
});

$router->group([
    'prefix'     => 'api/v1',
    'name'       => 'api.',
    'middleware' => [
        SessionMiddleware::class,
        AuthenticateMiddleware::class,
        CsrfMiddleware::class,
        AuthorizeMiddleware::class,
        RateLimitMiddleware::class,
    ],
], static function ($router): void {
    // -- Session and profile ------------------------------------------------
    // No permission: every signed-in user may manage their own session and
    // their own details, whatever their role.
    $router->post('/logout', [AuthController::class, 'logout'])->name('logout');
    $router->get('/session', [AuthController::class, 'session'])->name('session');
    $router->post('/session/extend', [AuthController::class, 'extendSession'])->name('session.extend');

    $router->get('/profile', [AuthController::class, 'profile'])
        ->permission('profile.view')->name('profile');
    $router->put('/profile', [AuthController::class, 'updateProfile'])
        ->permission('profile.update')->name('profile.update');
    $router->post('/password/change', [AuthController::class, 'changePassword'])
        ->permission('profile.change_password')->name('password.change');

    // -- Reference data -----------------------------------------------------
    // Dropdown content. Any signed-in user may read it; it is the same list
    // their forms already show.
    $router->get('/reference', [ReferenceController::class, 'index'])->name('reference');
    $router->get('/reference/vehicle-types', [ReferenceController::class, 'vehicleTypes'])
        ->name('reference.vehicle-types');
    $router->get('/reference/visitor-types', [ReferenceController::class, 'visitorTypes'])
        ->name('reference.visitor-types');
    $router->get('/reference/notification-types', [ReferenceController::class, 'notificationTypes'])
        ->permission('settings.view')->name('reference.notification-types');

    // -- Dashboard ----------------------------------------------------------
    $router->get('/dashboard', [DashboardController::class, 'index'])
        ->permission('dashboard.view')->name('dashboard');
    $router->get('/dashboard/poll', [DashboardController::class, 'poll'])
        ->permission('dashboard.refresh')->name('dashboard.poll');
    $router->get('/dashboard/cards', [DashboardController::class, 'cards'])
        ->permission('dashboard.view')->name('dashboard.cards');
    $router->get('/dashboard/charts', [DashboardController::class, 'charts'])
        ->permission('dashboard.view')->name('dashboard.charts');
    $router->get('/dashboard/devices', [DashboardController::class, 'devices'])
        ->permission('dashboard.view')->name('dashboard.devices');
    $router->get('/dashboard/alerts', [DashboardController::class, 'alerts'])
        ->permission('dashboard.view')->name('dashboard.alerts');
    $router->get('/dashboard/overstaying', [DashboardController::class, 'overstaying'])
        ->permission('dashboard.view')->name('dashboard.overstaying');
    $router->get('/dashboard/health', [DashboardController::class, 'health'])
        ->permission('dashboard.view')->name('dashboard.health');

    // -- Monitoring ---------------------------------------------------------
    $router->get('/access/live', [AccessController::class, 'live'])
        ->permission('monitoring.view')->name('access.live');
    $router->get('/access/inside', [AccessController::class, 'inside'])
        ->permission('monitoring.view')->name('access.inside');
    $router->get('/access/history', [AccessController::class, 'history'])
        ->permission('monitoring.view')->name('access.history');

    $router->get('/monitoring/denials', [MonitoringController::class, 'denials'])
        ->permission('monitoring.view')->name('monitoring.denials');
    $router->get('/monitoring/denials/breakdown', [MonitoringController::class, 'denialBreakdown'])
        ->permission('monitoring.view')->name('monitoring.denials.breakdown');
    $router->get('/monitoring/statistics', [MonitoringController::class, 'statistics'])
        ->permission('monitoring.view')->name('monitoring.statistics');
    $router->post('/monitoring/manual', [MonitoringController::class, 'manual'])
        ->permission('monitoring.manual')->name('monitoring.manual');

    /*
     * Registered last among the /access routes: "{id}" would otherwise swallow
     * "live", "inside" and "history", because the router matches in
     * registration order.
     */
    $router->get('/access/{id}', [AccessController::class, 'show'])
        ->whereNumber('id')->permission('monitoring.view')->name('access.show');
    $router->post('/monitoring/{id}/force-close', [MonitoringController::class, 'forceClose'])
        ->whereNumber('id')->permission('monitoring.force_close')->name('monitoring.force-close');
    $router->post('/monitoring/{id}/annotate', [MonitoringController::class, 'annotate'])
        ->whereNumber('id')->permission('monitoring.annotate')->name('monitoring.annotate');

    // -- Vehicles -----------------------------------------------------------
    $router->get('/vehicles', [VehicleController::class, 'index'])
        ->permission('vehicles.view')->name('vehicles');
    $router->get('/vehicles/select', [VehicleController::class, 'select'])
        ->permission('vehicles.view')->name('vehicles.select');
    $router->get('/vehicles/summary', [VehicleController::class, 'summary'])
        ->permission('vehicles.view')->name('vehicles.summary');
    $router->post('/vehicles', [VehicleController::class, 'store'])
        ->permission('vehicles.create')->name('vehicles.store');
    $router->get('/vehicles/{id}', [VehicleController::class, 'show'])
        ->whereNumber('id')->permission('vehicles.view')->name('vehicles.show');
    $router->get('/vehicles/{id}/timeline', [VehicleController::class, 'timeline'])
        ->whereNumber('id')->permission('vehicles.view')->name('vehicles.timeline');
    $router->put('/vehicles/{id}', [VehicleController::class, 'update'])
        ->whereNumber('id')->permission('vehicles.update')->name('vehicles.update');
    $router->delete('/vehicles/{id}', [VehicleController::class, 'destroy'])
        ->whereNumber('id')->permission('vehicles.delete')->name('vehicles.destroy');
    $router->post('/vehicles/{id}/restore', [VehicleController::class, 'restore'])
        ->whereNumber('id')->permission('vehicles.restore')->name('vehicles.restore');

    // -- Drivers ------------------------------------------------------------
    $router->get('/drivers', [DriverController::class, 'index'])
        ->permission('drivers.view')->name('drivers');
    $router->get('/drivers/select', [DriverController::class, 'select'])
        ->permission('drivers.view')->name('drivers.select');
    $router->post('/drivers', [DriverController::class, 'store'])
        ->permission('drivers.create')->name('drivers.store');
    $router->get('/drivers/{id}', [DriverController::class, 'show'])
        ->whereNumber('id')->permission('drivers.view')->name('drivers.show');
    $router->put('/drivers/{id}', [DriverController::class, 'update'])
        ->whereNumber('id')->permission('drivers.update')->name('drivers.update');
    $router->delete('/drivers/{id}', [DriverController::class, 'destroy'])
        ->whereNumber('id')->permission('drivers.delete')->name('drivers.destroy');

    // -- Owners -------------------------------------------------------------
    $router->get('/owners', [OwnerController::class, 'index'])
        ->permission('owners.view')->name('owners');
    $router->get('/owners/select', [OwnerController::class, 'select'])
        ->permission('owners.view')->name('owners.select');
    $router->post('/owners', [OwnerController::class, 'store'])
        ->permission('owners.create')->name('owners.store');
    $router->get('/owners/{id}', [OwnerController::class, 'show'])
        ->whereNumber('id')->permission('owners.view')->name('owners.show');
    $router->put('/owners/{id}', [OwnerController::class, 'update'])
        ->whereNumber('id')->permission('owners.update')->name('owners.update');
    $router->delete('/owners/{id}', [OwnerController::class, 'destroy'])
        ->whereNumber('id')->permission('owners.delete')->name('owners.destroy');

    // -- Visitors and passes ------------------------------------------------
    // The pass routes precede "/visitors/{id}" for the same reason as above.
    $router->get('/visitors/passes', [VisitorController::class, 'passes'])
        ->permission('visitors.view')->name('visitors.passes');
    $router->get('/visitors/passes/inside', [VisitorController::class, 'inside'])
        ->permission('visitors.view')->name('visitors.passes.inside');
    $router->post('/visitors/passes', [VisitorController::class, 'issuePass'])
        ->permission('visitors.issue_pass')->name('visitors.passes.issue');
    $router->get('/visitors/passes/{id}', [VisitorController::class, 'showPass'])
        ->whereNumber('id')->permission('visitors.view')->name('visitors.passes.show');
    $router->post('/visitors/passes/{id}/revoke', [VisitorController::class, 'revokePass'])
        ->whereNumber('id')->permission('visitors.revoke_pass')->name('visitors.passes.revoke');
    $router->get('/visitors/cards/available', [VisitorController::class, 'availableCards'])
        ->permission('visitors.issue_pass')->name('visitors.cards.available');

    $router->get('/visitors', [VisitorController::class, 'index'])
        ->permission('visitors.view')->name('visitors');
    $router->get('/visitors/select', [VisitorController::class, 'select'])
        ->permission('visitors.view')->name('visitors.select');
    $router->get('/visitors/summary', [VisitorController::class, 'summary'])
        ->permission('visitors.view')->name('visitors.summary');
    $router->post('/visitors', [VisitorController::class, 'store'])
        ->permission('visitors.create')->name('visitors.store');
    $router->get('/visitors/{id}', [VisitorController::class, 'show'])
        ->whereNumber('id')->permission('visitors.view')->name('visitors.show');
    $router->put('/visitors/{id}', [VisitorController::class, 'update'])
        ->whereNumber('id')->permission('visitors.update')->name('visitors.update');
    $router->post('/visitors/{id}/blacklist', [VisitorController::class, 'setBlacklist'])
        ->whereNumber('id')->permission('visitors.blacklist')->name('visitors.blacklist');

    // -- RFID inventory -----------------------------------------------------
    $router->get('/rfid/summary', [RfidController::class, 'summary'])
        ->permission('rfid.view')->name('rfid.summary');
    $router->get('/rfid/lookup', [RfidController::class, 'lookup'])
        ->permission('rfid.view')->name('rfid.lookup');

    $router->get('/rfid/tags', [RfidController::class, 'tags'])
        ->permission('rfid.view')->name('rfid.tags');
    $router->get('/rfid/tags/available', [RfidController::class, 'availableTags'])
        ->permission('rfid.assign')->name('rfid.tags.available');
    $router->post('/rfid/tags', [RfidController::class, 'storeTag'])
        ->permission('rfid.create')->name('rfid.tags.store');
    $router->post('/rfid/tags/assign', [RfidController::class, 'assignTag'])
        ->permission('rfid.assign')->name('rfid.tags.assign');
    $router->get('/rfid/tags/{id}', [RfidController::class, 'showTag'])
        ->whereNumber('id')->permission('rfid.view')->name('rfid.tags.show');
    $router->put('/rfid/tags/{id}', [RfidController::class, 'updateTag'])
        ->whereNumber('id')->permission('rfid.update')->name('rfid.tags.update');
    $router->post('/rfid/tags/{id}/status', [RfidController::class, 'setTagStatus'])
        ->whereNumber('id')->permission('rfid.deactivate')->name('rfid.tags.status');

    $router->get('/rfid/cards', [RfidController::class, 'cards'])
        ->permission('rfid.view')->name('rfid.cards');
    $router->post('/rfid/cards', [RfidController::class, 'storeCard'])
        ->permission('rfid.create')->name('rfid.cards.store');
    $router->post('/rfid/cards/{id}/status', [RfidController::class, 'setCardStatus'])
        ->whereNumber('id')->permission('rfid.deactivate')->name('rfid.cards.status');

    // -- Fingerprints -------------------------------------------------------
    $router->get('/fingerprints', [FingerprintController::class, 'index'])
        ->permission('fingerprints.view')->name('fingerprints');
    $router->get('/fingerprints/summary', [FingerprintController::class, 'summary'])
        ->permission('fingerprints.view')->name('fingerprints.summary');
    $router->get('/fingerprints/next-slot', [FingerprintController::class, 'nextSlot'])
        ->permission('fingerprints.enroll')->name('fingerprints.next-slot');
    $router->get('/fingerprints/verifications', [FingerprintController::class, 'verifications'])
        ->permission('fingerprints.view')->name('fingerprints.verifications');
    $router->get('/fingerprints/operators', [FingerprintController::class, 'operatorSessions'])
        ->permission('fingerprints.view')->name('fingerprints.operators');
    $router->post('/fingerprints/operators/{id}/close', [FingerprintController::class, 'closeOperatorSession'])
        ->whereNumber('id')->permission('fingerprints.verify')->name('fingerprints.operators.close');
    $router->post('/fingerprints/synchronise', [FingerprintController::class, 'synchronise'])
        ->permission('fingerprints.sync')->name('fingerprints.synchronise');
    $router->post('/fingerprints', [FingerprintController::class, 'store'])
        ->permission('fingerprints.enroll')->name('fingerprints.store');
    $router->get('/fingerprints/{id}', [FingerprintController::class, 'show'])
        ->whereNumber('id')->permission('fingerprints.view')->name('fingerprints.show');
    $router->delete('/fingerprints/{id}', [FingerprintController::class, 'destroy'])
        ->whereNumber('id')->permission('fingerprints.delete')->name('fingerprints.destroy');

    // -- Monitoring stations ------------------------------------------------
    $router->get('/devices', [DeviceManagementController::class, 'index'])
        ->permission('devices.view')->name('devices');
    $router->get('/devices/status', [DeviceManagementController::class, 'status'])
        ->permission('devices.view')->name('devices.status');
    $router->get('/devices/summary', [DeviceManagementController::class, 'summary'])
        ->permission('devices.view')->name('devices.summary');
    $router->post('/devices', [DeviceManagementController::class, 'store'])
        ->permission('devices.create')->name('devices.store');
    $router->get('/devices/{id}', [DeviceManagementController::class, 'show'])
        ->whereNumber('id')->permission('devices.view')->name('devices.show');
    $router->get('/devices/{id}/diagnostics', [DeviceManagementController::class, 'diagnostics'])
        ->whereNumber('id')->permission('devices.diagnostics')->name('devices.diagnostics');
    $router->put('/devices/{id}', [DeviceManagementController::class, 'update'])
        ->whereNumber('id')->permission('devices.update')->name('devices.update');
    $router->post('/devices/{id}/rotate-key', [DeviceManagementController::class, 'rotateKey'])
        ->whereNumber('id')->permission('devices.rotate_key')->name('devices.rotate-key');
    $router->post('/devices/{id}/suspend', [DeviceManagementController::class, 'suspend'])
        ->whereNumber('id')->permission('devices.suspend')->name('devices.suspend');
    $router->post('/devices/{id}/reinstate', [DeviceManagementController::class, 'reinstate'])
        ->whereNumber('id')->permission('devices.suspend')->name('devices.reinstate');
    $router->delete('/devices/{id}', [DeviceManagementController::class, 'destroy'])
        ->whereNumber('id')->permission('devices.delete')->name('devices.destroy');

    // -- Reports ------------------------------------------------------------
    $router->get('/reports', [ReportController::class, 'index'])
        ->permission('reports.view')->name('reports');
    $router->get('/reports/analytics/overview', [ReportController::class, 'analytics'])
        ->permission('reports.view')->throttle('reports')->name('reports.analytics');
    $router->get('/reports/{key}/export/{format}', [ReportController::class, 'export'])
        ->where('key', '[a-z0-9_-]+')->where('format', '[a-z]+')
        ->permission('reports.export')->throttle('export')->name('reports.export');
    $router->get('/reports/{key}', [ReportController::class, 'generate'])
        ->where('key', '[a-z0-9_-]+')
        ->permission('reports.generate')->throttle('reports')->name('reports.generate');

    // -- Notifications ------------------------------------------------------
    $router->get('/notifications', [NotificationController::class, 'index'])
        ->permission('notifications.view')->name('notifications');
    $router->get('/notifications/recent', [NotificationController::class, 'recent'])
        ->permission('notifications.view')->name('notifications.recent');
    $router->get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->permission('notifications.view')->name('notifications.unread-count');
    $router->post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->permission('notifications.view')->name('notifications.read-all');
    $router->post('/notifications/{id}/read', [NotificationController::class, 'markRead'])
        ->whereNumber('id')->permission('notifications.view')->name('notifications.read');
    $router->post('/notifications/{id}/unread', [NotificationController::class, 'markUnread'])
        ->whereNumber('id')->permission('notifications.view')->name('notifications.unread');
    $router->post('/notifications/{id}/archive', [NotificationController::class, 'archive'])
        ->whereNumber('id')->permission('notifications.view')->name('notifications.archive');
    $router->delete('/notifications/{id}', [NotificationController::class, 'destroy'])
        ->whereNumber('id')->permission('notifications.delete')->name('notifications.destroy');

    // -- Audit trail --------------------------------------------------------
    $router->get('/audit', [AuditController::class, 'index'])
        ->permission('audit.view')->name('audit');
    $router->get('/audit/filters', [AuditController::class, 'filters'])
        ->permission('audit.view')->name('audit.filters');
    $router->get('/audit/summary', [AuditController::class, 'summary'])
        ->permission('audit.view')->name('audit.summary');
    $router->get('/audit/record/{type}/{id}', [AuditController::class, 'forRecord'])
        ->where('type', '[a-z_]+')->whereNumber('id')
        ->permission('audit.view')->name('audit.record');

    // -- Security -----------------------------------------------------------
    $router->get('/security/events', [SecurityController::class, 'index'])
        ->permission('security.view')->name('security.events');
    $router->get('/security/summary', [SecurityController::class, 'summary'])
        ->permission('security.view')->name('security.summary');
    $router->get('/security/login-attempts', [SecurityController::class, 'loginAttempts'])
        ->permission('security.view')->name('security.login-attempts');
    $router->get('/security/rules', [SecurityController::class, 'rules'])
        ->permission('security.view')->name('security.rules');
    $router->put('/security/rules/{id}', [SecurityController::class, 'updateRule'])
        ->whereNumber('id')->permission('security.manage_rules')->name('security.rules.update');
    $router->get('/security/events/{id}', [SecurityController::class, 'show'])
        ->whereNumber('id')->permission('security.view')->name('security.events.show');
    $router->post('/security/events/{id}/acknowledge', [SecurityController::class, 'acknowledge'])
        ->whereNumber('id')->permission('security.acknowledge')->name('security.events.acknowledge');

    // -- Error register -----------------------------------------------------
    $router->get('/errors', [ErrorLogController::class, 'index'])
        ->permission('errors.view')->name('errors');
    $router->get('/errors/summary', [ErrorLogController::class, 'summary'])
        ->permission('errors.view')->name('errors.summary');
    $router->get('/errors/reference/{reference}', [ErrorLogController::class, 'byReference'])
        ->where('reference', '[A-Za-z0-9-]+')
        ->permission('errors.view')->name('errors.reference');
    $router->get('/errors/{id}', [ErrorLogController::class, 'show'])
        ->whereNumber('id')->permission('errors.view')->name('errors.show');
    $router->post('/errors/{id}/resolve', [ErrorLogController::class, 'resolve'])
        ->whereNumber('id')->permission('errors.resolve')->name('errors.resolve');
    $router->post('/errors/{id}/reopen', [ErrorLogController::class, 'reopen'])
        ->whereNumber('id')->permission('errors.resolve')->name('errors.reopen');
    $router->post('/errors/{id}/assign', [ErrorLogController::class, 'assign'])
        ->whereNumber('id')->permission('errors.resolve')->name('errors.assign');

    // -- API traffic --------------------------------------------------------
    $router->get('/api-logs', [ApiLogController::class, 'index'])
        ->permission('api.logs')->name('api-logs');
    $router->get('/api-logs/performance', [ApiLogController::class, 'performance'])
        ->permission('api.logs')->name('api-logs.performance');

    // -- Users --------------------------------------------------------------
    $router->get('/users/sessions', [UserController::class, 'sessions'])
        ->permission('users.sessions')->name('users.sessions');
    $router->get('/users/summary', [UserController::class, 'summary'])
        ->permission('users.view')->name('users.summary');
    $router->get('/users/assignable-roles', [UserController::class, 'assignableRoles'])
        ->permission('users.create')->name('users.assignable-roles');
    $router->get('/users', [UserController::class, 'index'])
        ->permission('users.view')->name('users');
    $router->post('/users', [UserController::class, 'store'])
        ->permission('users.create')->name('users.store');
    $router->get('/users/{id}', [UserController::class, 'show'])
        ->whereNumber('id')->permission('users.view')->name('users.show');
    $router->get('/users/{id}/activity', [UserController::class, 'activity'])
        ->whereNumber('id')->permission('users.view')->name('users.activity');
    $router->put('/users/{id}', [UserController::class, 'update'])
        ->whereNumber('id')->permission('users.update')->name('users.update');
    $router->post('/users/{id}/reset-password', [UserController::class, 'resetPassword'])
        ->whereNumber('id')->permission('users.reset_password')->name('users.reset-password');
    $router->post('/users/{id}/lock', [UserController::class, 'lock'])
        ->whereNumber('id')->permission('users.lock')->name('users.lock');
    $router->post('/users/{id}/unlock', [UserController::class, 'unlock'])
        ->whereNumber('id')->permission('users.unlock')->name('users.unlock');
    $router->post('/users/{id}/restore', [UserController::class, 'restore'])
        ->whereNumber('id')->permission('users.delete')->name('users.restore');
    $router->delete('/users/{id}/sessions/{session}', [UserController::class, 'terminateSession'])
        ->whereNumber('id')->whereNumber('session')
        ->permission('users.sessions')->name('users.sessions.terminate');
    $router->delete('/users/{id}', [UserController::class, 'destroy'])
        ->whereNumber('id')->permission('users.delete')->name('users.destroy');

    // -- Roles and permissions ----------------------------------------------
    $router->get('/roles', [RoleController::class, 'index'])
        ->permission('roles.view')->name('roles');
    $router->post('/roles', [RoleController::class, 'store'])
        ->permission('roles.create')->name('roles.store');
    $router->get('/permissions', [RoleController::class, 'permissions'])
        ->permission('permissions.view')->name('permissions');
    $router->get('/roles/{id}', [RoleController::class, 'show'])
        ->whereNumber('id')->permission('roles.view')->name('roles.show');
    $router->put('/roles/{id}', [RoleController::class, 'update'])
        ->whereNumber('id')->permission('roles.update')->name('roles.update');
    $router->put('/roles/{id}/permissions', [RoleController::class, 'syncPermissions'])
        ->whereNumber('id')->permission('permissions.assign')->name('roles.permissions');
    $router->post('/roles/{id}/duplicate', [RoleController::class, 'duplicate'])
        ->whereNumber('id')->permission('roles.create')->name('roles.duplicate');
    $router->delete('/roles/{id}', [RoleController::class, 'destroy'])
        ->whereNumber('id')->permission('roles.delete')->name('roles.destroy');

    // -- Departments --------------------------------------------------------
    $router->get('/departments', [ReferenceController::class, 'departments'])
        ->permission('users.view')->name('departments');
    $router->post('/departments', [ReferenceController::class, 'storeDepartment'])
        ->permission('users.create')->name('departments.store');
    $router->put('/departments/{id}', [ReferenceController::class, 'updateDepartment'])
        ->whereNumber('id')->permission('users.update')->name('departments.update');
    $router->delete('/departments/{id}', [ReferenceController::class, 'destroyDepartment'])
        ->whereNumber('id')->permission('users.delete')->name('departments.destroy');

    // -- Settings -----------------------------------------------------------
    $router->get('/settings', [SettingsController::class, 'index'])
        ->permission('settings.view')->name('settings');
    $router->put('/settings', [SettingsController::class, 'update'])
        ->permission('settings.update')->name('settings.update');
    $router->post('/settings/{key}/reset', [SettingsController::class, 'reset'])
        ->where('key', '[a-z0-9_.]+')
        ->permission('settings.update')->name('settings.reset');

    // -- Backups ------------------------------------------------------------
    $router->get('/backups', [BackupController::class, 'index'])
        ->permission('backup.view')->name('backups');
    $router->get('/backups/summary', [BackupController::class, 'summary'])
        ->permission('backup.view')->name('backups.summary');
    $router->post('/backups', [BackupController::class, 'store'])
        ->permission('backup.create')->throttle('backup')->name('backups.store');
    $router->post('/backups/reconcile', [BackupController::class, 'reconcile'])
        ->permission('backup.view')->name('backups.reconcile');
    $router->get('/backups/{id}/download', [BackupController::class, 'download'])
        ->whereNumber('id')->permission('backup.download')->name('backups.download');
    $router->post('/backups/{id}/restore', [BackupController::class, 'restore'])
        ->whereNumber('id')->permission('backup.restore')->throttle('backup')->name('backups.restore');
    $router->delete('/backups/{id}', [BackupController::class, 'destroy'])
        ->whereNumber('id')->permission('backup.delete')->name('backups.destroy');

    // -- System health ------------------------------------------------------
    $router->get('/health/report', [HealthController::class, 'report'])
        ->permission('system.health')->name('health.report');
    $router->get('/health/environment', [HealthController::class, 'environment'])
        ->permission('system.health')->name('health.environment');

    // -- Search -------------------------------------------------------------
    // No permission of its own: each provider inside the service checks the
    // permission for the module it searches, so the results are already
    // filtered to what this user may see.
    $router->get('/search', [SearchController::class, 'index'])->name('search');
    $router->get('/search/quick', [SearchController::class, 'quick'])->name('search.quick');
});
