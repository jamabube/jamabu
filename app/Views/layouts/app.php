<?php
/**
 * The signed-in application shell: sidebar, top bar, content, footer.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var \App\DTO\AuthenticatedUser|null $currentUser
 * @var array<string,mixed> $flash
 * @var string $cspNonce
 */
$this->layout('layouts/base');

$this->start('body_class');
echo 'app-shell';
$this->stop();
?>
<div class="layout" id="layout">
    <?= $this->include('partials/sidebar') ?>

    <div class="layout__main">
        <?= $this->include('partials/topbar') ?>

        <main class="content" id="main-content" tabindex="-1">
            <?= $this->include('partials/page-header') ?>
            <?= $this->include('partials/flash') ?>

            <?= $this->section('content') ?>
        </main>

        <?= $this->include('partials/footer') ?>
    </div>
</div>

<div class="scrim" id="sidebar-scrim" hidden></div>

<?php
/*
 * Values the scripts need that only the server knows. Emitted as JSON in a
 * data island rather than as inline script, so the strict Content-Security
 * Policy needs no exception for it.
 */
$bootstrapData = [
    'csrfToken'      => csrf_token(),
    'csrfHeader'     => (string) config('security.csrf.header_name', 'X-CSRF-Token'),
    'pollInterval'   => (int) ($pollInterval ?? 5),
    'refreshSeconds' => (int) ($refreshSeconds ?? 15),
    'sessionTimeout' => (int) ($sessionTimeout ?? 1800),
    'idleWarning'    => (int) ($idleWarning ?? 120),
    'notifications'  => [
        'pollInterval' => (int) config('notifications.poll_interval', 30),
        'unread'       => (int) ($unreadCount ?? 0),
    ],
    'user' => $currentUser === null ? null : [
        'id'   => $currentUser->id,
        'name' => $currentUser->fullName,
        'role' => $currentUser->roleName,
    ],
    'route' => (string) ($currentRoute ?? ''),
];
?>
<script type="application/json" id="app-bootstrap"><?= \App\Core\Support\Html::js($bootstrapData) ?></script>
