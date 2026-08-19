<?php
/**
 * A dialog shell. The body is passed pre-rendered.
 *
 * @var string      $id
 * @var string      $title
 * @var string      $body
 * @var string|null $footer
 * @var string|null $size   sm | md | lg
 */
?>
<div class="modal" id="<?= e($id) ?>" data-modal hidden role="dialog" aria-modal="true" aria-labelledby="<?= e($id) ?>-title">
    <div class="modal__backdrop" data-modal-close></div>
    <div class="modal__dialog modal__dialog--<?= e($size ?? 'md') ?>" role="document">
        <header class="modal__header">
            <h2 class="modal__title" id="<?= e($id) ?>-title"><?= e($title) ?></h2>
            <button type="button" class="modal__close" data-modal-close aria-label="Close">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>
        <div class="modal__body"><?= $body ?></div>
        <?php if (isset($footer) && $footer !== ''): ?>
            <footer class="modal__footer"><?= $footer ?></footer>
        <?php endif; ?>
    </div>
</div>
