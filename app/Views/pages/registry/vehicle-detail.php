<?php
/**
 * One vehicle: its registration, its tag and its movement history.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $detail
 * @var array<string,mixed> $statistics
 */
$this->layout('layouts/app');

$vehicle  = (array) ($detail['vehicle'] ?? []);
$timeline = (array) ($detail['timeline'] ?? []);

$this->start('breadcrumbs');
?>
<a href="<?= e(route('vehicles.index')) ?>">Vehicles</a>
<span aria-hidden="true">/</span>
<span><?= e((string) ($vehicle['plate_number'] ?? '')) ?></span>
<?php
$this->stop();

$this->start('page_actions');
?>
<a class="button button--ghost" href="<?= e(route('vehicles.index')) ?>">
    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> All vehicles
</a>
<?php
$this->stop();
?>

<section class="stat-grid stat-grid--compact">
    <?= $this->component('stat-card', [
        'label' => 'Total visits', 'value' => (string) ($statistics['total_visits'] ?? 0),
        'icon' => 'fa-arrows-rotate', 'tone' => 'accent',
    ]) ?>
    <?php
    $averageSeconds = (int) ($statistics['average_stay_seconds'] ?? 0);
    $averageStay    = $averageSeconds > 0
        ? sprintf('%dh %02dm', intdiv($averageSeconds, 3600), intdiv($averageSeconds % 3600, 60))
        : '—';
    ?>
    <?= $this->component('stat-card', [
        'label' => 'Average stay', 'value' => $averageStay,
        'icon' => 'fa-hourglass-half', 'tone' => 'neutral',
    ]) ?>
    <?= $this->component('stat-card', [
        'label' => 'Last entry', 'value' => (string) ($statistics['last_entry'] ?? 'Never'),
        'icon' => 'fa-right-to-bracket', 'tone' => 'neutral',
    ]) ?>
    <?= $this->component('stat-card', [
        'label' => 'Last exit', 'value' => (string) ($statistics['last_exit'] ?? 'Never'),
        'icon' => 'fa-right-from-bracket', 'tone' => 'neutral',
    ]) ?>
</section>

<div class="detail-grid">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-car" aria-hidden="true"></i> Registration</h2>
            <?= $this->component('badge', ['value' => (string) ($vehicle['status'] ?? 'unknown')]) ?>
        </header>
        <div class="card__body">
            <dl class="definition-list definition-list--two">
                <div class="definition-list__row"><dt>Plate</dt><dd><strong><?= e((string) ($vehicle['plate_number'] ?? '—')) ?></strong></dd></div>
                <div class="definition-list__row"><dt>Code</dt><dd class="table__mono"><?= e((string) ($vehicle['vehicle_code'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Type</dt><dd><?= e((string) ($vehicle['vehicle_type'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Make and model</dt><dd><?= e(trim((string) ($vehicle['brand'] ?? '') . ' ' . (string) ($vehicle['model'] ?? '')) ?: '—') ?></dd></div>
                <div class="definition-list__row"><dt>Colour</dt><dd><?= e((string) ($vehicle['colour'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Year</dt><dd><?= e((string) ($vehicle['year_model'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Chassis</dt><dd class="table__mono"><?= e((string) ($vehicle['chassis_number'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Engine</dt><dd class="table__mono"><?= e((string) ($vehicle['engine_number'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Insurance</dt><dd><?= e((string) ($vehicle['insurance_provider'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Insurance expiry</dt><dd><?= e((string) ($vehicle['insurance_expiry'] ?? '—')) ?></dd></div>
            </dl>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-user" aria-hidden="true"></i> People and credential</h2>
        </header>
        <div class="card__body">
            <dl class="definition-list">
                <div class="definition-list__row">
                    <dt>Owner</dt>
                    <dd>
                        <?php if (($vehicle['owner_id'] ?? null) !== null && can('owners.view')): ?>
                            <a href="<?= e(url('/owners/' . (string) $vehicle['owner_id'])) ?>"><?= e((string) ($vehicle['owner_name'] ?? '—')) ?></a>
                        <?php else: ?>
                            <?= e((string) ($vehicle['owner_name'] ?? '—')) ?>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="definition-list__row">
                    <dt>Driver</dt>
                    <dd>
                        <?php if (($vehicle['driver_id'] ?? null) !== null && can('drivers.view')): ?>
                            <a href="<?= e(url('/drivers/' . (string) $vehicle['driver_id'])) ?>"><?= e((string) ($vehicle['driver_name'] ?? '—')) ?></a>
                        <?php else: ?>
                            <?= e((string) ($vehicle['driver_name'] ?? 'Not assigned')) ?>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="definition-list__row">
                    <dt>RFID tag</dt>
                    <dd class="table__mono"><?= e((string) ($vehicle['rfid_uid'] ?? 'None assigned')) ?></dd>
                </div>
                <div class="definition-list__row">
                    <dt>Tag status</dt>
                    <dd><?= $this->component('badge', ['value' => (string) ($vehicle['tag_status'] ?? 'unknown')]) ?></dd>
                </div>
                <div class="definition-list__row">
                    <dt>Tag expiry</dt>
                    <dd><?= e((string) ($vehicle['tag_expiration'] ?? 'Does not expire')) ?></dd>
                </div>
                <div class="definition-list__row">
                    <dt>Currently</dt>
                    <dd><?= $this->component('badge', ['value' => (string) ($vehicle['presence'] ?? 'unknown')]) ?></dd>
                </div>
            </dl>
        </div>
    </section>
</div>

<section class="card">
    <header class="card__header">
        <h2 class="card__title"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Movement history</h2>
        <span class="card__note"><?= e((string) count($timeline)) ?> most recent</span>
    </header>
    <div class="card__body card__body--flush">
        <?php if ($timeline === []): ?>
            <?= $this->component('empty-state', [
                'message' => 'This vehicle has never been recorded at a gate.',
                'icon'    => 'fa-road',
            ]) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Reference</th>
                            <th scope="col">Entered</th>
                            <th scope="col">Exited</th>
                            <th scope="col">Stay</th>
                            <th scope="col">Station</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($timeline as $visit): ?>
                            <tr data-href="<?= e(url('/monitoring/' . (string) $visit['access_log_id'])) ?>">
                                <td class="table__mono"><?= e((string) $visit['transaction_reference']) ?></td>
                                <td><?= e((string) $visit['entry_time']) ?></td>
                                <td><?= e((string) ($visit['exit_time'] ?? 'Still inside')) ?></td>
                                <td data-duration="<?= e((string) ($visit['duration_seconds'] ?? '')) ?>"></td>
                                <td><?= e((string) ($visit['entry_device_name'] ?? '—')) ?></td>
                                <td><?= $this->component('badge', ['value' => (string) $visit['status']]) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
