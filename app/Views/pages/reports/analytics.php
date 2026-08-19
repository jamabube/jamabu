<?php
/**
 * Analytics — the shape of the traffic rather than the individual records.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $analytics
 * @var string $date_from
 * @var string $date_to
 */
$this->layout('layouts/app');

$movements = (array) ($analytics['movements'] ?? []);

$this->start('page_subtitle');
printf('%s to %s', e($date_from), e($date_to));
$this->stop();

$this->start('page_actions');
?>
<form class="inline-form" method="get" action="">
    <label class="visually-hidden" for="an-from">From</label>
    <input class="field__control field__control--sm" type="date" id="an-from" name="date_from" value="<?= e($date_from) ?>">
    <label class="visually-hidden" for="an-to">To</label>
    <input class="field__control field__control--sm" type="date" id="an-to" name="date_to" value="<?= e($date_to) ?>">
    <button type="submit" class="button button--primary button--sm">Apply</button>
</form>
<?php
$this->stop();

$averageSeconds = (int) ($movements['average_stay_seconds'] ?? 0);
$averageStay    = $averageSeconds > 0
    ? sprintf('%dh %02dm', intdiv($averageSeconds, 3600), intdiv($averageSeconds % 3600, 60))
    : '—';
$peakEntry = $movements['peak_entry_hour'];
?>

<section class="stat-grid">
    <?= $this->component('stat-card', ['label' => 'Total visits', 'value' => (string) ($movements['total_visits'] ?? 0), 'icon' => 'fa-arrows-rotate', 'tone' => 'accent']) ?>
    <?= $this->component('stat-card', ['label' => 'Unique vehicles', 'value' => (string) ($movements['unique_vehicles'] ?? 0), 'icon' => 'fa-car', 'tone' => 'neutral']) ?>
    <?= $this->component('stat-card', ['label' => 'Visitor visits', 'value' => (string) ($movements['visitor_visits'] ?? 0), 'icon' => 'fa-user-clock', 'tone' => 'info']) ?>
    <?= $this->component('stat-card', ['label' => 'Average stay', 'value' => $averageStay, 'icon' => 'fa-hourglass-half', 'tone' => 'neutral']) ?>
    <?= $this->component('stat-card', [
        'label' => 'Busiest hour',
        'value' => $peakEntry === null ? '—' : sprintf('%02d:00', (int) $peakEntry),
        'icon'  => 'fa-clock', 'tone' => 'neutral', 'caption' => 'for arrivals',
    ]) ?>
    <?= $this->component('stat-card', [
        'label' => 'Refusal rate',
        'value' => number_format((float) ($analytics['rejection_rate'] ?? 0), 1) . '%',
        'icon'  => 'fa-ban',
        'tone'  => ((float) ($analytics['rejection_rate'] ?? 0)) > 5 ? 'warning' : 'neutral',
    ]) ?>
</section>

<div class="chart-grid">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-chart-line" aria-hidden="true"></i> Daily traffic</h2>
        </header>
        <div class="card__body">
            <div class="chart chart--tall" data-chart="daily" data-chart-type="line"
                 data-chart-payload="<?= e(\App\Core\Support\Html::js($analytics['daily'] ?? [])) ?>">
                <canvas aria-label="Daily traffic" role="img"></canvas>
            </div>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-chart-column" aria-hidden="true"></i> Movements by hour</h2>
        </header>
        <div class="card__body">
            <div class="chart chart--tall" data-chart="hourly" data-chart-type="bar"
                 data-chart-payload="<?= e(\App\Core\Support\Html::js($analytics['hourly'] ?? [])) ?>">
                <canvas aria-label="Movements by hour" role="img"></canvas>
            </div>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-ban" aria-hidden="true"></i> Why scans were refused</h2>
        </header>
        <div class="card__body">
            <div class="chart" data-chart="denials" data-chart-type="bar-horizontal"
                 data-chart-payload="<?= e(\App\Core\Support\Html::js($analytics['denials'] ?? [])) ?>">
                <canvas aria-label="Refusal reasons" role="img"></canvas>
            </div>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Security events</h2>
        </header>
        <div class="card__body">
            <div class="chart" data-chart="security" data-chart-type="line"
                 data-chart-payload="<?= e(\App\Core\Support\Html::js($analytics['security_trend'] ?? [])) ?>">
                <canvas aria-label="Security event trend" role="img"></canvas>
            </div>
        </div>
    </section>
</div>

<div class="detail-grid detail-grid--three">
    <?php
    $tables = [
        ['title' => 'Busiest vehicles', 'icon' => 'fa-car', 'rows' => (array) ($analytics['top_vehicles'] ?? []),
         'label' => 'plate_number', 'value' => 'visits', 'unit' => 'visit(s)'],
        ['title' => 'Busiest drivers', 'icon' => 'fa-id-card', 'rows' => (array) ($analytics['top_drivers'] ?? []),
         'label' => 'full_name', 'value' => 'visits', 'unit' => 'visit(s)'],
        ['title' => 'Most-used tags', 'icon' => 'fa-tags', 'rows' => (array) ($analytics['top_tags'] ?? []),
         'label' => 'tag_code', 'value' => 'scans', 'unit' => 'scan(s)'],
    ];
    ?>
    <?php foreach ($tables as $table): ?>
        <section class="card">
            <header class="card__header">
                <h2 class="card__title"><i class="fa-solid <?= e($table['icon']) ?>" aria-hidden="true"></i> <?= e($table['title']) ?></h2>
            </header>
            <div class="card__body card__body--flush">
                <?php if ($table['rows'] === []): ?>
                    <?= $this->component('empty-state', ['message' => 'Nothing in this period.', 'icon' => 'fa-chart-simple']) ?>
                <?php else: ?>
                    <ol class="rank-list">
                        <?php foreach ($table['rows'] as $index => $row): ?>
                            <li class="rank-list__item">
                                <span class="rank-list__position"><?= e((string) ($index + 1)) ?></span>
                                <span class="rank-list__label"><?= e((string) ($row[$table['label']] ?? '—')) ?></span>
                                <span class="rank-list__value">
                                    <?= e((string) ($row[$table['value']] ?? 0)) ?> <?= e($table['unit']) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
