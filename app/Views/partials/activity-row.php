<?php
/**
 * One row of the live activity feed.
 *
 * Shared by the server-rendered first paint and — through its data attributes
 * mirrored in assets/js/ui.js — by the rows the poller appends, so a movement
 * looks identical whichever produced it.
 *
 * @var array<string,mixed> $movement
 */
$inside  = (string) ($movement['status'] ?? '') === 'inside';
$visitor = (int) ($movement['is_visitor'] ?? 0) === 1;
$time    = (string) ($inside ? ($movement['entry_time'] ?? '') : ($movement['exit_time'] ?? $movement['entry_time'] ?? ''));
$who     = (string) ($visitor ? ($movement['visitor_name'] ?? 'Visitor') : ($movement['owner_name'] ?? '—'));
?>
<li class="feed__item" data-access-log-id="<?= e((string) ($movement['access_log_id'] ?? '')) ?>">
    <span class="feed__direction feed__direction--<?= $inside ? 'in' : 'out' ?>" aria-hidden="true">
        <i class="fa-solid <?= $inside ? 'fa-right-to-bracket' : 'fa-right-from-bracket' ?>"></i>
    </span>
    <span class="feed__body">
        <span class="feed__headline">
            <a class="feed__plate" href="<?= e(url('/monitoring/' . (string) ($movement['access_log_id'] ?? ''))) ?>">
                <?= e((string) ($movement['plate_number'] ?? 'Unknown')) ?>
            </a>
            <?php if ($visitor): ?>
                <span class="badge badge--info">Visitor</span>
            <?php endif; ?>
        </span>
        <span class="feed__meta">
            <?= e($who) ?>
            <span aria-hidden="true">·</span>
            <?= e((string) ($movement['vehicle_type'] ?? 'Unclassified')) ?>
            <span aria-hidden="true">·</span>
            <?= e((string) ($inside ? ($movement['entry_device_name'] ?? '') : ($movement['exit_device_name'] ?? $movement['entry_device_name'] ?? ''))) ?>
        </span>
    </span>
    <span class="feed__time">
        <time datetime="<?= e($time) ?>" data-relative-time="<?= e($time) ?>"><?= e($time) ?></time>
        <?= $this->component('badge', ['value' => (string) ($movement['status'] ?? '')]) ?>
    </span>
</li>
