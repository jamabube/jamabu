<?php
/**
 * A titled panel. Body content is passed pre-rendered as $body, because a
 * component cannot wrap a block in this engine.
 *
 * @var string      $title
 * @var string      $body
 * @var string|null $icon
 * @var string|null $actions  pre-rendered HTML for the header's right side
 * @var string|null $footer
 * @var string|null $class
 */
?>
<section class="card <?= e($class ?? '') ?>">
    <header class="card__header">
        <h2 class="card__title">
            <?php if (isset($icon) && $icon !== ''): ?>
                <i class="fa-solid <?= e((string) $icon) ?>" aria-hidden="true"></i>
            <?php endif; ?>
            <?= e($title) ?>
        </h2>
        <?php if (isset($actions) && $actions !== ''): ?>
            <div class="card__actions"><?= $actions ?></div>
        <?php endif; ?>
    </header>
    <div class="card__body"><?= $body ?></div>
    <?php if (isset($footer) && $footer !== ''): ?>
        <footer class="card__footer"><?= $footer ?></footer>
    <?php endif; ?>
</section>
