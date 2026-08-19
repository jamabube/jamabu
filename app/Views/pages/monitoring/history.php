<?php
/**
 * Access history — the searchable record of every movement.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var list<array<string,mixed>> $devices
 * @var list<array<string,mixed>> $vehicleTypes
 * @var array<string,string> $results
 * @var bool $canForceClose
 * @var bool $canAnnotate
 * @var bool $canExport
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'Every entry and exit the system has recorded. Records are never edited or removed; corrections are added alongside.';
$this->stop();

$this->start('page_actions');
?>
<?php if ($canExport): ?>
    <div class="dropdown" data-dropdown>
        <button type="button" class="button button--ghost" data-dropdown-toggle aria-expanded="false">
            <i class="fa-solid fa-download" aria-hidden="true"></i> Export
        </button>
        <div class="dropdown__menu" data-dropdown-menu hidden>
            <a class="dropdown__item" data-export="pdf" href="<?= e(url('/api/v1/reports/access-history/export/pdf')) ?>">PDF</a>
            <a class="dropdown__item" data-export="excel" href="<?= e(url('/api/v1/reports/access-history/export/excel')) ?>">Excel</a>
            <a class="dropdown__item" data-export="csv" href="<?= e(url('/api/v1/reports/access-history/export/csv')) ?>">CSV</a>
        </div>
    </div>
<?php endif; ?>
<?php
$this->stop();

/*
 * The filter controls are built here and handed to the table component, which
 * sends any control carrying data-filter as a query parameter.
 */
ob_start();
?>
<label class="visually-hidden" for="filter-status">Status</label>
<select id="filter-status" class="field__control field__control--sm" data-filter="status">
    <option value="">Any status</option>
    <option value="inside">Inside</option>
    <option value="completed">Completed</option>
    <option value="forced_closed">Force-closed</option>
</select>

<label class="visually-hidden" for="filter-type">Vehicle type</label>
<select id="filter-type" class="field__control field__control--sm" data-filter="vehicle_type">
    <option value="">Any vehicle type</option>
    <?php foreach ($vehicleTypes as $type): ?>
        <option value="<?= e((string) $type['type_name']) ?>"><?= e((string) $type['type_name']) ?></option>
    <?php endforeach; ?>
</select>

<label class="visually-hidden" for="filter-device">Station</label>
<select id="filter-device" class="field__control field__control--sm" data-filter="device_id">
    <option value="">Any station</option>
    <?php foreach ($devices as $device): ?>
        <option value="<?= e((string) $device['device_id']) ?>"><?= e((string) $device['device_name']) ?></option>
    <?php endforeach; ?>
</select>

<label class="visually-hidden" for="filter-from">From</label>
<input type="date" id="filter-from" class="field__control field__control--sm" data-filter="date_from"
       value="<?= e((string) ($_GET['date_from'] ?? '')) ?>">

<label class="visually-hidden" for="filter-to">To</label>
<input type="date" id="filter-to" class="field__control field__control--sm" data-filter="date_to">

<button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
<?php
$filterControls = (string) ob_get_clean();
?>

<?= $this->component('data-table', [
    'id'           => 'history-table',
    'endpoint'     => route('api.access.history'),
    'sort'         => 'entry_time',
    'direction'    => 'DESC',
    'rowLink'      => url('/monitoring/{access_log_id}'),
    'emptyMessage' => 'No movements match these filters.',
    'filterControls' => $filterControls,
    'columns'      => [
        ['key' => 'transaction_reference', 'label' => 'Reference', 'sortable' => true, 'class' => 'table__mono'],
        ['key' => 'plate_number',   'label' => 'Plate',   'sortable' => true, 'format' => 'strong'],
        ['key' => 'owner_name',     'label' => 'Owner / visitor'],
        ['key' => 'vehicle_type',   'label' => 'Type'],
        ['key' => 'entry_time',     'label' => 'Entered', 'sortable' => true, 'format' => 'datetime'],
        ['key' => 'exit_time',      'label' => 'Exited',  'sortable' => true, 'format' => 'datetime', 'empty' => 'Still inside'],
        ['key' => 'duration_seconds', 'label' => 'Stay',  'sortable' => true, 'format' => 'duration'],
        ['key' => 'entry_device_name', 'label' => 'Station'],
        ['key' => 'status',         'label' => 'Status',  'sortable' => true, 'format' => 'badge'],
    ],
]) ?>

<?php if ($canForceClose || $canAnnotate): ?>
    <p class="hint">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        Open a record to close a visit whose exit was never scanned, or to add an explanatory note.
    </p>
<?php endif; ?>
