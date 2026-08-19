<?php
/**
 * Sign-in page.
 *
 * Posts to the server rather than through AJAX, so a workstation with
 * JavaScript blocked can still sign in. A gate that cannot open because a
 * script failed to load is not an acceptable failure mode.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $flash
 * @var array<string,list<string>> $errors
 * @var bool $timed_out
 * @var string $intended
 */
$this->layout('layouts/base');

$this->start('body_class');
echo 'auth-page';
$this->stop();
?>
<main class="auth">
    <div class="auth__panel">
        <div class="auth__brand">
            <span class="auth__mark" aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></span>
            <h1 class="auth__title"><?= e((string) config('app.name', 'Vehicle Access Monitoring')) ?></h1>
            <p class="auth__subtitle"><?= e((string) config('app.organization', '')) ?></p>
        </div>

        <?= $this->include('partials/flash') ?>

        <?php if ($timed_out): ?>
            <div class="alert alert--warning" role="status">
                <i class="fa-solid fa-clock" aria-hidden="true"></i>
                <span>Your session ended after a period of inactivity. Please sign in again.</span>
            </div>
        <?php endif; ?>

        <form class="auth__form" method="post" action="<?= e(route('login.submit')) ?>" autocomplete="on" novalidate>
            <?= csrf_field() ?>
            <?php if ($intended !== ''): ?>
                <input type="hidden" name="intended" value="<?= e($intended) ?>">
            <?php endif; ?>

            <div class="field field--full">
                <label class="field__label" for="username">Username</label>
                <div class="field__group">
                    <i class="fa-solid fa-user field__adornment" aria-hidden="true"></i>
                    <input class="field__control<?= isset($errors['username']) ? ' is-invalid' : '' ?>"
                           type="text" id="username" name="username"
                           value="<?= e((string) old('username', '')) ?>"
                           autocomplete="username" autocapitalize="none" spellcheck="false"
                           required autofocus>
                </div>
                <?php if (isset($errors['username'])): ?>
                    <p class="field__error"><?= e((string) $errors['username'][0]) ?></p>
                <?php endif; ?>
            </div>

            <div class="field field--full">
                <label class="field__label" for="password">Password</label>
                <div class="field__group">
                    <i class="fa-solid fa-lock field__adornment" aria-hidden="true"></i>
                    <input class="field__control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                           type="password" id="password" name="password"
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

            <label class="field__check auth__remember">
                <input type="checkbox" name="remember" value="1" <?= old('remember') ? 'checked' : '' ?>>
                <span>Keep me signed in on this workstation</span>
            </label>

            <button type="submit" class="button button--primary button--block">
                <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> Sign in
            </button>
        </form>

        <p class="auth__note">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            Access is logged. Contact the system administrator if you cannot sign in.
        </p>
    </div>

    <footer class="auth__footer">
        <?= e((string) config('app.copyright', '')) ?> · v<?= e((string) config('app.version', '1.0.0')) ?>
    </footer>
</main>
