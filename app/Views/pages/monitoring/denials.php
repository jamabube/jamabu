<?php
/**
 * Refused scans.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var list<array<string,mixed>> $devices
 * @var array<string,string> $results
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'Every scan the system turned away, and why. A cluster of the same reason at one station usually means a hardware fault rather than an intruder.';
$this->stop();

ob_start();
?>
<label class="visually-hidden" for="denial-reason">Reason</label>
<select id="denial-reason" class="field__control field__control--sm" data-filter="reason_code">
    <option value="">Any reason</option>
    <?php foreach ($results as $code => $label): ?>
        <?php if ($code === 'granted') { continue; } ?>
        <option value="<?= e((string) $code) ?>"><?= e((string) $label) ?></option>
    <?php endforeach; ?>
</select>

<label class="visually-hidden" for="denial-device">Station</label>
<select id="denial-device" class="field__control field__control--sm" data-filter="device_id">
    <option value="">Any station</option>
    <?php foreach ($devices as $device): ?>
        <option value="<?= e((string) $device['device_id']) ?>"><?= e((string) $device['device_name']) ?></option>
    <?php endforeach; ?>
</select>

<label class="visually-hidden" for="denial-type">Attempted</label>
<select id="denial-type" class="field__control field__control--sm" data-filter="attempted_type">
    <option value="">Entry or exit</option>
    <option value="entry">Entry</option>
    <option value="exit">Exit</option>
</select>

<label class="visually-hidden" for="denial-from">From</label>
<input type="date" id="denial-from" class="field__control field__control--sm" data-filter="date_from">
<label class="visually-hidden" for="denial-to">To</label>
<input type="date" id="denial-to" class="field__control field__control--sm" data-filter="date_to">
<button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
<?php
$filterControls = (string) ob_get_clean();
?>

<div class="chart-grid chart-grid--compact">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-chart-simple" aria-hidden="true"></i> Why scans were refused</h2>
        </header>
        <div class="card__body">
            <div class="chart" data-chart="denial-breakdown" data-chart-type="bar-horizontal"
                 data-chart-endpoint="<?= e(route('api.monitoring.denials.breakdown')) ?>"
                 data-chart-path="by_reason">
                <canvas aria-label="Refusal reasons" role="img"></canvas>
            </div>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-percent" aria-hidden="true"></i> Refusal rate</h2>
        </header>
        <div class="card__body card__body--centre">
            <p class="metric" data-metric-endpoint="<?= e(route('api.monitoring.denials.breakdown')) ?>"
               data-metric-path="rejection_rate" data-metric-suffix="%">—</p>
            <p class="metric__caption">of all scans in the last thirty days were turned away</p>
        </div>
    </section>
</div>

<?= $this->component('data-table', [
    'id'           => 'denials-table',
    'endpoint'     => route('api.monitoring.denials'),
    'sort'         => 'occurred_at',
    'emptyMessage' => 'No refused scans match these filters.',
    'filterControls' => $filterControls,
    'columns'      => [
        ['key' => 'occurred_at',    'label' => 'When',    'sortable' => true, 'format' => 'datetime'],
        ['key' => 'scanned_uid',    'label' => 'UID',     'class' => 'table__mono'],
        ['key' => 'plate_number',   'label' => 'Plate',   'empty' => 'Unknown'],
        ['key' => 'attempted_type', 'label' => 'Attempted'],
        ['key' => 'reason',         'label' => 'Reason'],
        ['key' => 'device_name',    'label' => 'Station'],
        ['key' => 'operator_name',  'label' => 'Operator', 'empty' => 'None on duty'],
        ['key' => 'ip_address',     'label' => 'Source',  'class' => 'table__mono'],
    ],
]) ?>
