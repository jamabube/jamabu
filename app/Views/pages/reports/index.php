<?php
/**
 * The report catalogue.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,array<string,mixed>> $reports
 * @var list<array<string,mixed>> $devices
 * @var list<array<string,mixed>> $vehicleTypes
 * @var bool $canExport
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'One definition drives the screen, the CSV, the spreadsheet and the PDF, so an exported figure can never disagree with the one shown here.';
$this->stop();
?>

<?php if ($reports === []): ?>
    <?= $this->component('empty-state', [
        'message' => 'No reports are available to your role.',
        'icon'    => 'fa-file-lines',
    ]) ?>
<?php else: ?>
    <div class="report-grid">
        <?php foreach ($reports as $key => $report): ?>
            <article class="report-card">
                <header class="report-card__header">
                    <span class="report-card__icon" aria-hidden="true"><i class="fa-solid fa-file-lines"></i></span>
                    <h2 class="report-card__title"><?= e((string) $report['title']) ?></h2>
                </header>
                <p class="report-card__description"><?= e((string) $report['description']) ?></p>
                <p class="report-card__columns">
                    <?= e(implode(' · ', array_slice((array) $report['headers'], 0, 5))) ?><?php
                        echo count((array) $report['headers']) > 5 ? ' …' : '';
                    ?>
                </p>
                <footer class="report-card__actions">
                    <a class="button button--sm button--primary" href="<?= e(url('/reports/' . (string) $key)) ?>">
                        <i class="fa-solid fa-play" aria-hidden="true"></i> Run
                    </a>
                    <?php if ($canExport): ?>
                        <div class="dropdown" data-dropdown>
                            <button type="button" class="button button--sm button--ghost" data-dropdown-toggle aria-expanded="false">
                                <i class="fa-solid fa-download" aria-hidden="true"></i> Export
                            </button>
                            <div class="dropdown__menu" data-dropdown-menu hidden>
                                <a class="dropdown__item" href="<?= e(url('/api/v1/reports/' . (string) $key . '/export/pdf')) ?>">PDF</a>
                                <a class="dropdown__item" href="<?= e(url('/api/v1/reports/' . (string) $key . '/export/excel')) ?>">Excel</a>
                                <a class="dropdown__item" href="<?= e(url('/api/v1/reports/' . (string) $key . '/export/csv')) ?>">CSV</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </footer>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
