<?php
/**
 * Shown where a table or list would be, when there is nothing to show.
 *
 * @var string      $message
 * @var string|null $icon
 * @var string|null $hint
 * @var string|null $action pre-rendered HTML
 */
?>
<div class="empty-state">
    <i class="fa-solid <?= e($icon ?? 'fa-inbox') ?> empty-state__icon" aria-hidden="true"></i>
    <p class="empty-state__message"><?= e($message) ?></p>
    <?php if (isset($hint) && $hint !== ''): ?>
        <p class="empty-state__hint"><?= e((string) $hint) ?></p>
    <?php endif; ?>
    <?php if (isset($action) && $action !== ''): ?>
        <div class="empty-state__action"><?= $action ?></div>
    <?php endif; ?>
</div>
