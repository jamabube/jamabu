<?php
/**
 * Shared frame for the error pages.
 *
 * Deliberately independent of the application shell: an error page must render
 * even when whatever the shell depends on is the thing that broke.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var string $code
 * @var string $heading
 * @var string $message
 * @var string|null $reference
 */
$this->layout('layouts/base');
?>
<main class="error-page">
    <div class="error-page__panel">
        <p class="error-page__code"><?= e($code) ?></p>
        <h1 class="error-page__heading"><?= e($heading) ?></h1>
        <p class="error-page__message"><?= e($message) ?></p>

        <?php if (isset($reference) && $reference !== ''): ?>
            <p class="error-page__reference">
                Reference <code><?= e((string) $reference) ?></code> — quote this to the administrator.
            </p>
        <?php endif; ?>

        <div class="error-page__actions">
            <a class="button button--primary" href="<?= e(url('/')) ?>">
                <i class="fa-solid fa-house" aria-hidden="true"></i> Back to the dashboard
            </a>
            <button type="button" class="button button--ghost" data-history-back>
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Go back
            </button>
        </div>
    </div>
</main>
