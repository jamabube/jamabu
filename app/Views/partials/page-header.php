<?php
/**
 * The heading strip above the page body.
 *
 * A page contributes its own breadcrumbs and action buttons through the
 * "breadcrumbs" and "page_actions" sections; both are optional.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var string $title
 */
$heading = trim((string) ($title ?? ''));
?>
<?php if ($heading !== '' || $this->hasSection('page_actions')): ?>
    <div class="page-header">
        <div class="page-header__text">
            <?php if ($this->hasSection('breadcrumbs')): ?>
                <nav class="breadcrumbs" aria-label="Breadcrumb">
                    <?= $this->section('breadcrumbs') ?>
                </nav>
            <?php endif; ?>
            <?php if ($heading !== ''): ?>
                <h1 class="page-header__title"><?= e($heading) ?></h1>
            <?php endif; ?>
            <?php if ($this->hasSection('page_subtitle')): ?>
                <p class="page-header__subtitle"><?= $this->section('page_subtitle') ?></p>
            <?php endif; ?>
        </div>

        <?php if ($this->hasSection('page_actions')): ?>
            <div class="page-header__actions"><?= $this->section('page_actions') ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
