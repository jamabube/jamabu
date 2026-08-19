<?php
/**
 * One monitoring station: its configuration, health and recent traffic.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $device
 * @var array<string,mixed> $diagnostics
 * @var list<array<string,mixed>> $heartbeats
 */
$this->layout('layouts/app');

$health = $device['health_score'] === null ? null : (int) $device['health_score'];

$this->start('breadcrumbs');
?>
<a href="<?= e(route('devices.index')) ?>">ESP32 devices</a>
<span aria-hidden="true">/</span>
<span><?= e((string) $device['device_code']) ?></span>
<?php
$this->stop();

$this->start('page_actions');
?>
<?php if (can('devices.rotate_key')): ?>
    <button type="button" class="button button--warning"
            data-rotate-key="<?= e((string) $device['device_id']) ?>"
            data-device-name="<?= e((string) $device['device_name']) ?>">
        <i class="fa-solid fa-key" aria-hidden="true"></i> Rotate API key
    </button>
<?php endif; ?>
<a class="button button--ghost" href="<?= e(route('devices.index')) ?>">
    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> All stations
</a>
<?php
$this->stop();
?>

<?php if ((string) $device['status'] === 'suspended'): ?>
    <div class="alert alert--danger" role="alert">
        <i class="fa-solid fa-ban" aria-hidden="true"></i>
        <div>
            <strong>This station is suspended and every call from it is being refused.</strong>
            <p><?= e((string) ($device['suspend_reason'] ?? 'No reason recorded.')) ?>
               Until <?= e((string) ($device['suspended_until'] ?? 'further notice')) ?>.</p>
        </div>
    </div>
<?php endif; ?>

<section class="stat-grid stat-grid--compact">
    <?= $this->component('stat-card', [
        'label' => 'Connectivity', 'value' => ucfirst(str_replace('_', ' ', (string) $device['connectivity'])),
        'icon' => 'fa-signal', 'tone' => (string) $device['connectivity'] === 'online' ? 'success' : 'danger',
    ]) ?>
    <?= $this->component('stat-card', [
        'label' => 'Health', 'value' => $health === null ? '—' : $health . '/100',
        'icon' => 'fa-heart-pulse',
        'tone' => $health === null ? 'neutral' : ($health >= 80 ? 'success' : ($health >= 50 ? 'warning' : 'danger')),
    ]) ?>
    <?= $this->component('stat-card', [
        'label' => 'Calls received', 'value' => (string) $device['communication_count'],
        'icon' => 'fa-tower-broadcast', 'tone' => 'neutral',
    ]) ?>
    <?= $this->component('stat-card', [
        'label' => 'Errors reported', 'value' => (string) $device['error_count'],
        'icon' => 'fa-triangle-exclamation', 'tone' => (int) $device['error_count'] > 0 ? 'warning' : 'neutral',
    ]) ?>
</section>

<div class="detail-grid">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-microchip" aria-hidden="true"></i> Configuration</h2>
            <?= $this->component('badge', ['value' => (string) $device['status']]) ?>
        </header>
        <div class="card__body">
            <dl class="definition-list definition-list--two">
                <div class="definition-list__row"><dt>Name</dt><dd><strong><?= e((string) $device['device_name']) ?></strong></dd></div>
                <div class="definition-list__row"><dt>Code</dt><dd class="table__mono"><?= e((string) $device['device_code']) ?></dd></div>
                <div class="definition-list__row"><dt>Gate role</dt><dd><?= e(ucfirst((string) $device['gate_type'])) ?></dd></div>
                <div class="definition-list__row"><dt>Gate label</dt><dd><?= e((string) ($device['gate_label'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Location</dt><dd><?= e((string) ($device['location'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>MAC</dt><dd class="table__mono"><?= e((string) ($device['mac_address'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Last IP</dt><dd class="table__mono"><?= e((string) ($device['ip_address'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Firmware</dt><dd><?= e((string) ($device['firmware_version'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Heartbeat every</dt><dd><?= e((string) $device['heartbeat_interval']) ?> s</dd></div>
                <div class="definition-list__row"><dt>Installed</dt><dd><?= e((string) ($device['installation_date'] ?? '—')) ?></dd></div>
            </dl>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-stethoscope" aria-hidden="true"></i> Diagnostics</h2>
        </header>
        <div class="card__body">
            <dl class="definition-list">
                <div class="definition-list__row">
                    <dt>Last heartbeat</dt>
                    <dd>
                        <?php if (($device['last_heartbeat_at'] ?? null) === null): ?>
                            Never
                        <?php else: ?>
                            <time data-relative-time="<?= e((string) $device['last_heartbeat_at']) ?>"><?= e((string) $device['last_heartbeat_at']) ?></time>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="definition-list__row"><dt>Last scan</dt>
                    <dd><?= e((string) ($device['last_scan_at'] ?? 'Never')) ?></dd></div>
                <div class="definition-list__row"><dt>Signal strength</dt>
                    <dd><?= $device['signal_strength'] === null ? '—' : e((string) $device['signal_strength']) . ' dBm' ?></dd></div>
                <div class="definition-list__row"><dt>Restarts</dt><dd><?= e((string) $device['restart_count']) ?></dd></div>
                <div class="definition-list__row"><dt>Authentication failures</dt><dd><?= e((string) $device['auth_failure_count']) ?></dd></div>
                <div class="definition-list__row">
                    <dt>Health band</dt>
                    <dd><?= $this->component('badge', ['value' => (string) ($diagnostics['health_band'] ?? 'unknown')]) ?></dd>
                </div>
                <?php $averages = (array) ($diagnostics['averages'] ?? []); ?>
                <?php if ($averages !== []): ?>
                    <div class="definition-list__row">
                        <dt>Average signal, 24 h</dt>
                        <dd><?= e((string) $averages['signal']) ?> dBm</dd>
                    </div>
                    <div class="definition-list__row">
                        <dt>Average memory / CPU, 24 h</dt>
                        <dd><?= e((string) $averages['memory']) ?>% / <?= e((string) $averages['cpu']) ?>%</dd>
                    </div>
                    <div class="definition-list__row">
                        <dt>Average temperature, 24 h</dt>
                        <dd><?= e((string) $averages['temperature']) ?> °C</dd>
                    </div>
                <?php endif; ?>
                <?php $operator = $diagnostics['operator'] ?? null; ?>
                <div class="definition-list__row">
                    <dt>Operator on duty</dt>
                    <dd><?= $operator === null ? 'Nobody signed on' : e((string) ($operator['full_name'] ?? 'Unknown')) ?></dd>
                </div>
            </dl>
        </div>
    </section>
</div>

<section class="card">
    <header class="card__header">
        <h2 class="card__title"><i class="fa-solid fa-wave-square" aria-hidden="true"></i> Heartbeats, last six hours</h2>
    </header>
    <div class="card__body">
        <?php if ($heartbeats === []): ?>
            <?= $this->component('empty-state', [
                'message' => 'No heartbeats in the last six hours.',
                'icon'    => 'fa-heart-crack',
                'hint'    => 'Either the station is powered down or it cannot reach the server.',
            ]) ?>
        <?php else: ?>
            <div class="chart" data-chart="heartbeats" data-chart-type="line"
                 data-chart-payload="<?= e(\App\Core\Support\Html::js($heartbeats)) ?>">
                <canvas aria-label="Heartbeat signal strength" role="img"></canvas>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if (can('api.logs')): ?>
    <?= $this->component('data-table', [
        'id'           => 'device-requests',
        'endpoint'     => route('api.api-logs'),
        'sort'         => 'created_at',
        'emptyMessage' => 'This station has made no API calls.',
        'searchable'   => false,
        'filters'      => ['device_id' => (int) $device['device_id']],
        'columns'      => [
            ['key' => 'created_at',   'label' => 'When', 'sortable' => true, 'format' => 'datetime'],
            ['key' => 'method',       'label' => 'Method'],
            ['key' => 'endpoint',     'label' => 'Endpoint', 'class' => 'table__mono'],
            ['key' => 'status_code',  'label' => 'Status', 'sortable' => true, 'format' => 'status-code'],
            ['key' => 'duration_ms',  'label' => 'Took', 'sortable' => true, 'format' => 'milliseconds'],
            ['key' => 'ip_address',   'label' => 'From', 'class' => 'table__mono'],
        ],
    ]) ?>
<?php endif; ?>
