<?php
/**
 * A headline figure with an optional trend and link.
 *
 * @var string      $label
 * @var string|int  $value
 * @var string|null $icon
 * @var string|null $tone     accent | success | warning | danger | neutral
 * @var string|null $caption
 * @var string|null $href
 * @var string|null $bind     data-bind key, for the poller to update in place
 */
$tone  = $tone ?? 'neutral';
$inner = sprintf(
    '<span class="stat-card__icon stat-card__icon--%1$s" aria-hidden="true"><i class="fa-solid %2$s"></i></span>'
    . '<span class="stat-card__body">'
    . '<span class="stat-card__value"%3$s>%4$s</span>'
    . '<span class="stat-card__label">%5$s</span>'
    . '%6$s'
    . '</span>',
    e($tone),
    e($icon ?? 'fa-chart-simple'),
    isset($bind) ? ' data-bind="' . e((string) $bind) . '"' : '',
    e((string) $value),
    e($label),
    isset($caption) && $caption !== '' ? '<span class="stat-card__caption">' . e((string) $caption) . '</span>' : ''
);
?>
<?php if (isset($href) && $href !== ''): ?>
    <a class="stat-card stat-card--link" href="<?= e((string) $href) ?>"><?= $inner ?></a>
<?php else: ?>
    <div class="stat-card"><?= $inner ?></div>
<?php endif; ?>
