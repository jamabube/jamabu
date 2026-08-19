<?php
/**
 * API management: traffic, performance and the stations generating it.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var list<array<string,mixed>> $devices
 * @var array<string,mixed> $performance
 * @var list<array<string,mixed>> $busiest
 * @var bool $canViewLogs
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'Every call a station makes is recorded with its outcome and duration — which is how "is the entry gate actually talking to us?" gets answered without walking to the gate.';
$this->stop();
?>

<section class="stat-grid stat-grid--compact">
    <?= $this->component('stat-card', ['label' => 'Requests, 24 h', 'value' => (string) ($performance['total'] ?? 0), 'icon' => 'fa-arrow-right-arrow-left', 'tone' => 'accent']) ?>
    <?= $this->component('stat-card', ['label' => 'Failures, 24 h', 'value' => (string) ($performance['failed'] ?? 0), 'icon' => 'fa-circle-xmark', 'tone' => ((int) ($performance['failed'] ?? 0)) > 0 ? 'warning' : 'success']) ?>
    <?= $this->component('stat-card', ['label' => 'Average response', 'value' => (string) round((float) ($performance['average_ms'] ?? 0)) . ' ms', 'icon' => 'fa-gauge-high', 'tone' => 'neutral']) ?>
    <?= $this->component('stat-card', ['label' => 'Slowest', 'value' => (string) round((float) ($performance['slowest_ms'] ?? 0)) . ' ms', 'icon' => 'fa-hourglass-half', 'tone' => 'neutral']) ?>
</section>

<div class="detail-grid">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-ranking-star" aria-hidden="true"></i> Busiest endpoints, 24 h</h2>
        </header>
        <div class="card__body card__body--flush">
            <?php if ($busiest === []): ?>
                <?= $this->component('empty-state', ['message' => 'No API traffic in the last day.', 'icon' => 'fa-plug']) ?>
            <?php else: ?>
                <ol class="rank-list">
                    <?php foreach ($busiest as $index => $endpoint): ?>
                        <li class="rank-list__item">
                            <span class="rank-list__position"><?= e((string) ($index + 1)) ?></span>
                            <span class="rank-list__label table__mono">
                                <?= e((string) $endpoint['method']) ?> <?= e((string) $endpoint['endpoint']) ?>
                            </span>
                            <span class="rank-list__value">
                                <?= e((string) $endpoint['calls']) ?> call(s)
                                · <?= e((string) round((float) ($endpoint['average_ms'] ?? 0))) ?> ms
                                <?php if ((int) ($endpoint['failures'] ?? 0) > 0): ?>
                                    · <?= e((string) $endpoint['failures']) ?> failed
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-microchip" aria-hidden="true"></i> Stations</h2>
            <a class="link-button" href="<?= e(route('devices.index')) ?>">Manage</a>
        </header>
        <div class="card__body card__body--flush">
            <ul class="device-list">
                <?php foreach ($devices as $device): ?>
                    <li class="device-list__item">
                        <span class="device-list__dot device-list__dot--<?= e((string) $device['connectivity']) ?>" aria-hidden="true"></span>
                        <span class="device-list__body">
                            <span class="device-list__name"><?= e((string) $device['device_name']) ?></span>
                            <span class="device-list__meta">
                                <?= e((string) $device['communication_count']) ?> call(s) all time
                                <?php if ((int) $device['auth_failure_count'] > 0): ?>
                                    <span aria-hidden="true">·</span>
                                    <?= e((string) $device['auth_failure_count']) ?> authentication failure(s)
                                <?php endif; ?>
                            </span>
                        </span>
                        <?= $this->component('badge', ['value' => (string) $device['connectivity']]) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($devices === []): ?>
                <?= $this->component('empty-state', ['message' => 'No stations registered.', 'icon' => 'fa-microchip']) ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php if ($canViewLogs): ?>
    <?php
    ob_start();
    ?>
    <label class="visually-hidden" for="l-device">Station</label>
    <select id="l-device" class="field__control field__control--sm" data-filter="device_id">
        <option value="">Any station</option>
        <?php foreach ($devices as $device): ?>
            <option value="<?= e((string) $device['device_id']) ?>"><?= e((string) $device['device_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="visually-hidden" for="l-outcome">Outcome</label>
    <select id="l-outcome" class="field__control field__control--sm" data-filter="outcome">
        <option value="">Any outcome</option>
        <option value="succeeded">Succeeded</option>
        <option value="failed">Failed</option>
    </select>
    <label class="visually-hidden" for="l-method">Method</label>
    <select id="l-method" class="field__control field__control--sm" data-filter="method">
        <option value="">Any method</option>
        <option value="GET">GET</option>
        <option value="POST">POST</option>
        <option value="PUT">PUT</option>
        <option value="DELETE">DELETE</option>
    </select>
    <label class="field__check field__check--inline">
        <input type="checkbox" data-filter="slow_only" value="1">
        <span>Slow only</span>
    </label>
    <button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
    <?php
    $filterControls = (string) ob_get_clean();
    ?>

    <?= $this->component('data-table', [
        'id'           => 'api-logs-table',
        'endpoint'     => route('api.api-logs'),
        'sort'         => 'created_at',
        'emptyMessage' => 'No API calls match these filters.',
        'filterControls' => $filterControls,
        'columns'      => [
            ['key' => 'created_at',   'label' => 'When', 'sortable' => true, 'format' => 'datetime'],
            ['key' => 'device_code',  'label' => 'Station', 'empty' => 'Browser'],
            ['key' => 'method',       'label' => 'Method'],
            ['key' => 'endpoint',     'label' => 'Endpoint', 'class' => 'table__mono'],
            ['key' => 'status_code',  'label' => 'Status', 'sortable' => true, 'format' => 'status-code'],
            ['key' => 'duration_ms',  'label' => 'Took', 'sortable' => true, 'format' => 'milliseconds'],
            ['key' => 'ip_address',   'label' => 'From', 'class' => 'table__mono'],
        ],
    ]) ?>
<?php endif; ?>
