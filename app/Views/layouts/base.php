<?php
/**
 * The outermost HTML document.
 *
 * Both the application shell and the sign-in page wrap in this, so the head,
 * the asset list and the theme handling are declared once.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var string $title
 * @var string $cspNonce
 * @var string $appName
 */
$pageTitle = trim((string) ($title ?? ''));
$documentTitle = $pageTitle === '' ? $appName : $pageTitle . ' · ' . $appName;
?>
<!DOCTYPE html>
<html lang="<?= e(str_replace('_', '-', (string) config('app.locale', 'en'))) ?>" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?php /* The token is read by the AJAX layer for every state-changing call. */ ?>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="csrf-header" content="<?= e((string) config('security.csrf.header_name', 'X-CSRF-Token')) ?>">
    <meta name="app-base" content="<?= e(rtrim((string) config('app.url', ''), '/')) ?>">
    <title><?= e($documentTitle) ?></title>
    <link rel="icon" href="<?= e(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <?= $this->include('partials/assets-css') ?>
    <?= $this->section('styles') ?>
</head>
<body class="<?= e($this->section('body_class', 'app-shell')) ?>">
<?= $this->section('content') ?>

<?= $this->include('partials/assets-js') ?>
<?= $this->section('scripts') ?>
</body>
</html>
