<?php
/**
 * The audit trail.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,list<mixed>> $filters
 * @var bool $canExport
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'Who did what, when, and from where. The table accepts inserts and nothing else — there is no endpoint here that edits or removes an entry.';
$this->stop();

$this->start('page_actions');
?>
<?php if ($canExport): ?>
    <a class="button button--ghost" href="<?= e(url('/api/v1/reports/audit_trail/export/csv')) ?>">
        <i class="fa-solid fa-download" aria-hidden="true"></i> Export
    </a>
<?php endif; ?>
<?php
$this->stop();

ob_start();
?>
<label class="visually-hidden" for="a-module">Module</label>
<select id="a-module" class="field__control field__control--sm" data-filter="module">
    <option value="">Any module</option>
    <?php foreach ((array) ($filters['modules'] ?? []) as $module): ?>
        <option value="<?= e((string) $module) ?>"><?= e(ucfirst((string) $module)) ?></option>
    <?php endforeach; ?>
</select>

<label class="visually-hidden" for="a-action">Action</label>
<select id="a-action" class="field__control field__control--sm" data-filter="action">
    <option value="">Any action</option>
    <?php foreach ((array) ($filters['actions'] ?? []) as $action): ?>
        <option value="<?= e((string) $action) ?>"><?= e(ucfirst(str_replace('_', ' ', (string) $action))) ?></option>
    <?php endforeach; ?>
</select>

<label class="visually-hidden" for="a-status">Outcome</label>
<select id="a-status" class="field__control field__control--sm" data-filter="status">
    <option value="">Any outcome</option>
    <option value="success">Succeeded</option>
    <option value="failed">Failed</option>
</select>

<label class="visually-hidden" for="a-from">From</label>
<input type="date" id="a-from" class="field__control field__control--sm" data-filter="date_from">
<label class="visually-hidden" for="a-to">To</label>
<input type="date" id="a-to" class="field__control field__control--sm" data-filter="date_to">
<button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
<?php
$filterControls = (string) ob_get_clean();
?>

<?= $this->component('data-table', [
    'id'           => 'audit-table',
    'endpoint'     => route('api.audit'),
    'sort'         => 'created_at',
    'emptyMessage' => 'No audit entries match these filters.',
    'filterControls' => $filterControls,
    'columns'      => [
        ['key' => 'created_at',  'label' => 'When', 'sortable' => true, 'format' => 'datetime'],
        ['key' => 'username',    'label' => 'Who', 'empty' => 'system'],
        ['key' => 'module',      'label' => 'Module', 'sortable' => true],
        ['key' => 'action',      'label' => 'Action', 'sortable' => true],
        ['key' => 'description', 'label' => 'What happened'],
        ['key' => 'record_type', 'label' => 'Record', 'empty' => '—'],
        ['key' => 'ip_address',  'label' => 'From', 'class' => 'table__mono', 'empty' => '—'],
        ['key' => 'status',      'label' => 'Outcome', 'format' => 'badge'],
    ],
]) ?>
