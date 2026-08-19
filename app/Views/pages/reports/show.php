<?php
/**
 * A generated report.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $report
 * @var bool $canExport
 */
$this->layout('layouts/app');

$headers = (array) $report['headers'];
$columns = (array) $report['columns'];
$rows    = (array) $report['rows'];
$filters = (array) $report['filters'];

$this->start('breadcrumbs');
?>
<a href="<?= e(route('reports.index')) ?>">Reports</a>
<span aria-hidden="true">/</span>
<span><?= e((string) $report['title']) ?></span>
<?php
$this->stop();

$this->start('page_subtitle');
echo e((string) $report['description']);
$this->stop();

$this->start('page_actions');
?>
<?php if ($canExport): ?>
    <?php $query = http_build_query(array_filter([
        'date_from' => $filters['date_from'] ?? null,
        'date_to'   => $filters['date_to'] ?? null,
    ])); ?>
    <a class="button button--ghost" href="<?= e(url('/api/v1/reports/' . (string) $report['key'] . '/export/pdf?' . $query)) ?>">
        <i class="fa-solid fa-file-pdf" aria-hidden="true"></i> PDF
    </a>
    <a class="button button--ghost" href="<?= e(url('/api/v1/reports/' . (string) $report['key'] . '/export/excel?' . $query)) ?>">
        <i class="fa-solid fa-file-excel" aria-hidden="true"></i> Excel
    </a>
    <a class="button button--ghost" href="<?= e(url('/api/v1/reports/' . (string) $report['key'] . '/export/csv?' . $query)) ?>">
        <i class="fa-solid fa-file-csv" aria-hidden="true"></i> CSV
    </a>
<?php endif; ?>
<button type="button" class="button button--ghost" data-print>
    <i class="fa-solid fa-print" aria-hidden="true"></i> Print
</button>
<?php
$this->stop();
?>

<form class="card card--filters" method="get" action="">
    <div class="card__body">
        <div class="field-grid field-grid--inline">
            <div class="field field--third">
                <label class="field__label" for="report-from">From</label>
                <input class="field__control" type="date" id="report-from" name="date_from"
                       value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
            </div>
            <div class="field field--third">
                <label class="field__label" for="report-to">To</label>
                <input class="field__control" type="date" id="report-to" name="date_to"
                       value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
            </div>
            <div class="field field--third field--actions">
                <button type="submit" class="button button--primary">
                    <i class="fa-solid fa-filter" aria-hidden="true"></i> Apply
                </button>
                <a class="button button--ghost" href="<?= e(url('/reports/' . (string) $report['key'])) ?>">Reset</a>
            </div>
        </div>
    </div>
</form>

<?php if ((bool) $report['truncated']): ?>
    <div class="alert alert--warning" role="status">
        <i class="fa-solid fa-scissors" aria-hidden="true"></i>
        <span>
            This report was truncated because the range returned more rows than can be rendered.
            Narrow the dates, or export it, to see everything.
        </span>
    </div>
<?php endif; ?>

<?php if ((array) $report['summary'] !== []): ?>
    <section class="stat-grid stat-grid--compact">
        <?php foreach ((array) $report['summary'] as $label => $value): ?>
            <?= $this->component('stat-card', [
                'label' => ucwords(str_replace('_', ' ', (string) $label)),
                'value' => is_scalar($value) ? (string) $value : '—',
                'icon'  => 'fa-calculator',
                'tone'  => 'neutral',
            ]) ?>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<section class="card">
    <header class="card__header">
        <h2 class="card__title"><i class="fa-solid fa-table" aria-hidden="true"></i> <?= e((string) $report['title']) ?></h2>
        <span class="card__note"><?= e((string) count($rows)) ?> row(s)</span>
    </header>
    <div class="card__body card__body--flush">
        <?php if ($rows === []): ?>
            <?= $this->component('empty-state', [
                'message' => 'This report returned no rows for the chosen period.',
                'icon'    => 'fa-table',
                'hint'    => 'Widen the date range and run it again.',
            ]) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table table--report">
                    <thead>
                        <tr>
                            <?php foreach ($headers as $header): ?>
                                <th scope="col"><?= e((string) $header) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <?php foreach ($columns as $column): ?>
                                    <?php $value = $row[$column] ?? null; ?>
                                    <td><?= e(is_scalar($value) ? (string) $value : '') ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <footer class="card__footer">
        Generated <?= e(now()->format('j F Y, H:i')) ?>
        <?php if (($filters['date_from'] ?? '') !== ''): ?>
            · covering <?= e((string) $filters['date_from']) ?> to <?= e((string) ($filters['date_to'] ?? '')) ?>
        <?php endif; ?>
    </footer>
</section>
