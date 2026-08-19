<?php
/**
 * @var string $copyright
 * @var string $appVersion
 * @var string $supportContact
 */
?>
<footer class="footer">
    <span><?= e($copyright !== '' ? $copyright : (string) config('app.organization', '')) ?></span>
    <span class="footer__meta">
        <span>Version <?= e($appVersion) ?></span>
        <?php if ($supportContact !== ''): ?>
            <span aria-hidden="true">·</span>
            <span>Support: <?= e($supportContact) ?></span>
        <?php endif; ?>
    </span>
</footer>
