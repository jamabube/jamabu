<?php
/**
 * Flash banners and validation summaries from the previous request.
 *
 * @var array<string,mixed> $flash
 * @var array<string,list<string>> $errors
 */
$levels = [
    'success' => ['class' => 'alert--success', 'icon' => 'fa-circle-check'],
    'error'   => ['class' => 'alert--danger',  'icon' => 'fa-circle-exclamation'],
    'warning' => ['class' => 'alert--warning', 'icon' => 'fa-triangle-exclamation'],
    'info'    => ['class' => 'alert--info',    'icon' => 'fa-circle-info'],
];
?>
<div class="flash-stack" data-flash-stack>
    <?php foreach ($levels as $key => $style): ?>
        <?php $message = $flash[$key] ?? null; ?>
        <?php if (!is_string($message) || $message === '') { continue; } ?>
        <div class="alert <?= e($style['class']) ?>" role="<?= $key === 'error' ? 'alert' : 'status' ?>">
            <i class="fa-solid <?= e($style['icon']) ?>" aria-hidden="true"></i>
            <span><?= e($message) ?></span>
            <button type="button" class="alert__close" data-dismiss aria-label="Dismiss">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
    <?php endforeach; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <div>
                <strong>The form could not be saved.</strong>
                <ul class="alert__list">
                    <?php foreach ($errors as $messages): ?>
                        <?php foreach ((array) $messages as $message): ?>
                            <li><?= e((string) $message) ?></li>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
            <button type="button" class="alert__close" data-dismiss aria-label="Dismiss">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
    <?php endif; ?>
</div>
