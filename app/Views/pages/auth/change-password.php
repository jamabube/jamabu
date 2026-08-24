<?php
/**
 * Password change, reachable both from the profile menu and as a forced step
 * when a password has expired.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var bool $expired
 * @var array<string,mixed> $rules
 * @var string $suggestion
 * @var array<string,list<string>> $errors
 */
$this->layout($expired ? 'layouts/base' : 'layouts/app');

if ($expired) {
    $this->start('body_class');
    echo 'auth-page';
    $this->stop();

    // Reached straight from the sign-in screen, so it matches it. The ordinary
    // route to this page sits inside the themed shell and is left alone.
    $this->start('theme');
    echo 'dark';
    $this->stop();
}

/*
 * The page emits its body directly: the engine captures a template's own
 * output as the "content" section before handing it to the layout, so
 * capturing it here as well would leave the layout with nothing to render.
 */
?>
<div class="<?= $expired ? 'auth' : '' ?>">
    <div class="<?= $expired ? 'auth__panel auth__panel--wide' : 'stack' ?>">
        <?php if ($expired): ?>
            <div class="auth__brand">
                <span class="auth__mark" aria-hidden="true"><i class="fa-solid fa-key"></i></span>
                <h1 class="auth__title">Your password must be changed</h1>
                <p class="auth__subtitle">
                    It has passed the maximum age of <?= e((string) ($rules['max_age_days'] ?? '')) ?> days set for this system.
                </p>
            </div>
            <?= $this->include('partials/flash') ?>
        <?php endif; ?>

        <form class="card" method="post" action="<?= e(route('profile.password.submit')) ?>" novalidate
              data-password-form>
            <?= csrf_field() ?>
            <div class="card__body">
                <div class="field-grid">
                    <div class="field field--full">
                        <label class="field__label" for="current_password">Current password</label>
                        <div class="field__group">
                            <input class="field__control<?= isset($errors['current_password']) ? ' is-invalid' : '' ?>"
                                   type="password" id="current_password" name="current_password"
                                   autocomplete="current-password" required>
                            <button type="button" class="field__reveal" data-password-toggle="current_password"
                                    aria-label="Show password">
                                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <?php if (isset($errors['current_password'])): ?>
                            <p class="field__error"><?= e((string) $errors['current_password'][0]) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="field field--full">
                        <label class="field__label" for="new_password">New password</label>
                        <div class="field__group">
                            <input class="field__control<?= isset($errors['new_password']) ? ' is-invalid' : '' ?>"
                                   type="password" id="new_password" name="new_password"
                                   autocomplete="new-password" required
                                   data-strength-input data-strength-endpoint="<?= e(route('api.password.strength')) ?>">
                            <button type="button" class="field__reveal" data-password-toggle="new_password"
                                    aria-label="Show password">
                                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="strength" data-strength hidden>
                            <div class="strength__bar"><span class="strength__fill" data-strength-fill></span></div>
                            <span class="strength__label" data-strength-label></span>
                        </div>
                        <ul class="strength__failures" data-strength-failures></ul>
                        <?php if (isset($errors['new_password'])): ?>
                            <p class="field__error"><?= e((string) $errors['new_password'][0]) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="field field--full">
                        <label class="field__label" for="new_password_confirmation">Confirm the new password</label>
                        <input class="field__control" type="password" id="new_password_confirmation"
                               name="new_password_confirmation" autocomplete="new-password" required>
                    </div>
                </div>

                <div class="notice">
                    <h3 class="notice__title">What the policy requires</h3>
                    <ul class="notice__list">
                        <li>At least <?= e((string) $rules['min_length']) ?> characters.</li>
                        <?php if ($rules['require_uppercase']): ?><li>An upper-case letter.</li><?php endif; ?>
                        <?php if ($rules['require_lowercase']): ?><li>A lower-case letter.</li><?php endif; ?>
                        <?php if ($rules['require_numeric']): ?><li>A digit.</li><?php endif; ?>
                        <?php if ($rules['require_special']): ?><li>A symbol.</li><?php endif; ?>
                        <li>Not one of your last <?= e((string) $rules['history_depth']) ?> passwords.</li>
                        <li>Not similar to your username, and not a commonly used password.</li>
                    </ul>
                    <p class="notice__hint">
                        Suggested: <code class="notice__code" data-copy="<?= e($suggestion) ?>"><?= e($suggestion) ?></code>
                        <button type="button" class="link-button" data-copy-trigger="<?= e($suggestion) ?>">Copy</button>
                    </p>
                </div>
            </div>

            <footer class="card__footer card__footer--actions">
                <?php if (!$expired): ?>
                    <a class="button button--ghost" href="<?= e(route('profile')) ?>">Cancel</a>
                <?php endif; ?>
                <button type="submit" class="button button--primary">
                    <i class="fa-solid fa-check" aria-hidden="true"></i> Change password
                </button>
            </footer>
        </form>

        <?php if ($expired): ?>
            <form method="post" action="<?= e(route('logout')) ?>" class="auth__signout">
                <?= csrf_field() ?>
                <button type="submit" class="link-button">Sign out instead</button>
            </form>
        <?php endif; ?>
    </div>
</div>
