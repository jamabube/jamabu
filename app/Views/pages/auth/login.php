<?php
/**
 * Sign-in page.
 *
 * Two panels: the organisation on the left, the form on the right. The left
 * panel collapses away below the tablet breakpoint, so a phone gets the form
 * and nothing competing with it.
 *
 * Posts to the server rather than through AJAX, so a workstation with
 * JavaScript blocked can still sign in. A gate that cannot open because a
 * script failed to load is not an acceptable failure mode. Everything the
 * scripts add here — the clock, the password reveal — is decoration on top of
 * a page that already works without them.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $flash
 * @var array<string,list<string>> $errors
 * @var bool $timed_out
 * @var string $intended
 * @var string $greeting
 * @var string $server_time
 * @var bool $database_reachable
 */
$this->layout('layouts/base');

$this->start('body_class');
echo 'auth-page';
$this->stop();

/*
 * Fixed dark. The signed-out pages are one designed surface rather than part
 * of the shell an operator has themed, and the panel was drawn against this
 * background.
 */
$this->start('theme');
echo 'dark';
$this->stop();

$organisation = (string) config('app.organization', 'Forest Lawn Memorial Park');
$systemName   = (string) config('app.name', 'Vehicle Access Monitoring System');
$version      = (string) config('app.version', '1.0.0');

/*
 * The four capabilities named on the brand panel. They are labels, not links:
 * nothing here is reachable until somebody signs in, and a tile that looks
 * like a button and refuses to open is worse than a tile that never claimed
 * to be one.
 */
$capabilities = [
    ['icon' => 'fa-video',        'label' => 'Gate Monitoring'],
    ['icon' => 'fa-car',          'label' => 'Vehicle Registry'],
    ['icon' => 'fa-id-card',      'label' => 'Visitor Passes'],
    ['icon' => 'fa-chart-column', 'label' => 'Reports'],
];
?>
<main class="signin">
    <section class="signin__brand">
        <img class="signin__logo" src="<?= e(asset('assets/img/logo.svg')) ?>"
             alt="" width="96" height="96">

        <h1 class="signin__name"><?= e($organisation) ?></h1>
        <p class="signin__tagline">Vehicle Access Monitoring</p>
        <p class="signin__descriptor">
            RFID and biometric gate control, with every movement recorded.
        </p>

        <div class="signin__status" role="status">
            <?php /*
             * Inline, not a Font Awesome glyph. The vendor libraries are
             * optional — assets:fetch needs internet the guardhouse may not
             * have — and an icon that fails to load leaves an empty circle
             * that reads as a broken page rather than a missing nicety.
             */ ?>
            <span class="signin__status-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M5 17a9 9 0 0 1 0-10M19 7a9 9 0 0 1 0 10"/>
                    <path d="M8.5 14.5a5 5 0 0 1 0-5M15.5 9.5a5 5 0 0 1 0 5"/>
                    <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none"/>
                </svg>
            </span>
            <span class="signin__status-text">
                <strong>System Status</strong>
                <?php if ($database_reachable): ?>
                    <span>All services operational</span>
                <?php else: ?>
                    <span>Service degraded — contact the administrator</span>
                <?php endif; ?>
            </span>
            <span class="signin__dot <?= $database_reachable ? 'is-up' : 'is-down' ?>" aria-hidden="true"></span>
        </div>

        <ul class="signin__tiles">
            <?php foreach ($capabilities as $capability): ?>
                <li class="signin__tile">
                    <i class="fa-solid <?= e($capability['icon']) ?>" aria-hidden="true"></i>
                    <span><?= e($capability['label']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="signin__card">
        <div class="signin__welcome">
            <span class="signin__avatar" aria-hidden="true">
                <svg viewBox="0 0 96 96" width="34" height="34" fill="none"
                     stroke="#ffffff" stroke-width="6" stroke-linejoin="round">
                    <path d="M48 18l21 9v18.6c0 13.2-8.4 25.2-21 29.4-12.6-4.2-21-16.2-21-29.4V27l21-9z"/>
                    <path d="M37.5 48.6l7.8 7.8 13.8-15.6" stroke-linecap="round"/>
                </svg>
            </span>
            <h2 class="signin__greeting"><?= e($greeting) ?></h2>
            <p class="signin__prompt">Sign in to continue</p>
        </div>

        <?= $this->include('partials/flash') ?>

        <?php if ($timed_out): ?>
            <div class="alert alert--warning" role="status">
                <i class="fa-solid fa-clock" aria-hidden="true"></i>
                <span>Your session ended after a period of inactivity. Please sign in again.</span>
            </div>
        <?php endif; ?>

        <form class="signin__form" method="post" action="<?= e(route('login.submit')) ?>"
              autocomplete="on" novalidate>
            <?= csrf_field() ?>
            <?php if ($intended !== ''): ?>
                <input type="hidden" name="intended" value="<?= e($intended) ?>">
            <?php endif; ?>

            <div class="field field--full">
                <label class="field__label visually-hidden" for="username">Username</label>
                <div class="field__group">
                    <i class="fa-solid fa-user field__adornment" aria-hidden="true"></i>
                    <input class="field__control<?= isset($errors['username']) ? ' is-invalid' : '' ?>"
                           type="text" id="username" name="username" placeholder="Username"
                           value="<?= e((string) old('username', '')) ?>"
                           autocomplete="username" autocapitalize="none" spellcheck="false"
                           required autofocus>
                </div>
                <?php if (isset($errors['username'])): ?>
                    <p class="field__error"><?= e((string) $errors['username'][0]) ?></p>
                <?php endif; ?>
            </div>

            <div class="field field--full">
                <label class="field__label visually-hidden" for="password">Password</label>
                <div class="field__group">
                    <i class="fa-solid fa-lock field__adornment" aria-hidden="true"></i>
                    <input class="field__control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                           type="password" id="password" name="password" placeholder="Password"
                           autocomplete="current-password" required>
                    <button type="button" class="field__reveal" data-password-toggle="password"
                            aria-label="Show password">
                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                    </button>
                </div>
                <?php if (isset($errors['password'])): ?>
                    <p class="field__error"><?= e((string) $errors['password'][0]) ?></p>
                <?php endif; ?>
            </div>

            <div class="signin__row">
                <label class="field__check">
                    <input type="checkbox" name="remember" value="1" <?= old('remember') ? 'checked' : '' ?>>
                    <span>Remember me</span>
                </label>

                <?php /*
                 * Not a link. There is no self-service reset: a password here
                 * opens a gate, so it is issued by an administrator who can
                 * confirm who is asking. Offering a dead link would be worse
                 * than saying so.
                 */ ?>
                <span class="signin__hint">Forgotten? Ask an administrator.</span>
            </div>

            <button type="submit" class="button button--primary button--block">
                <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> Sign in
            </button>
        </form>

        <ul class="signin__chips">
            <li class="signin__chip signin__chip--up">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i> Online
            </li>
            <li class="signin__chip signin__chip--secure">
                <i class="fa-solid fa-lock" aria-hidden="true"></i> Secure
            </li>
            <li class="signin__chip <?= $database_reachable ? 'signin__chip--info' : 'signin__chip--down' ?>">
                <i class="fa-solid fa-database" aria-hidden="true"></i>
                <?= $database_reachable ? 'Database' : 'No database' ?>
            </li>
        </ul>

        <dl class="signin__stats">
            <div class="signin__stat">
                <dt><i class="fa-solid fa-server" aria-hidden="true"></i></dt>
                <dd class="signin__stat-value"><?= $database_reachable ? 'Online' : 'Degraded' ?></dd>
                <dd class="signin__stat-label">Status</dd>
            </div>
            <div class="signin__stat">
                <dt><i class="fa-solid fa-clock" aria-hidden="true"></i></dt>
                <?php /* ui.js ticks this every second; the server value is what
                         shows when scripts are unavailable. */ ?>
                <dd class="signin__stat-value" data-clock><?= e($server_time) ?></dd>
                <dd class="signin__stat-label">Time</dd>
            </div>
            <div class="signin__stat">
                <dt><i class="fa-solid fa-code-branch" aria-hidden="true"></i></dt>
                <dd class="signin__stat-value">v<?= e($version) ?></dd>
                <dd class="signin__stat-label">Version</dd>
            </div>
        </dl>

        <footer class="signin__footer">
            <img class="signin__footer-logo" src="<?= e(asset('assets/img/logo.svg')) ?>"
                 alt="" width="32" height="32">
            <p class="signin__footer-name"><?= e($organisation) ?></p>
            <p class="signin__footer-line"><?= e($systemName) ?></p>
            <p class="signin__footer-line">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                Access is logged.
            </p>
            <p class="signin__footer-line">&copy; <?= e(date('Y')) ?> <?= e((string) config('app.copyright', $organisation)) ?></p>
        </footer>
    </section>
</main>
