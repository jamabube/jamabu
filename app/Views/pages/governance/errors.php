<?php
/**
 * The error register.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var list<string> $modules
 * @var int $unresolved
 * @var array<string,bool> $can
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'Errors are grouped by signature and counted, so a fault that happened four thousand times appears once rather than burying everything else.';
$this->stop();

$this->start('page_actions');
?>
<button type="button" class="button button--ghost" data-modal-open="reference-lookup">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Find by reference
</button>
<?php
$this->stop();

ob_start();
?>
<label class="visually-hidden" for="e-severity">Severity</label>
<select id="e-severity" class="field__control field__control--sm" data-filter="severity">
    <option value="">Any severity</option>
    <option value="critical">Critical</option>
    <option value="error">Error</option>
    <option value="warning">Warning</option>
    <option value="notice">Notice</option>
</select>

<label class="visually-hidden" for="e-module">Module</label>
<select id="e-module" class="field__control field__control--sm" data-filter="module">
    <option value="">Any module</option>
    <?php foreach ($modules as $module): ?>
        <option value="<?= e((string) $module) ?>"><?= e((string) $module) ?></option>
    <?php endforeach; ?>
</select>

<label class="visually-hidden" for="e-resolved">Resolution</label>
<select id="e-resolved" class="field__control field__control--sm" data-filter="resolved">
    <option value="">Resolved and unresolved</option>
    <option value="0">Unresolved</option>
    <option value="1">Resolved</option>
</select>

<label class="visually-hidden" for="e-from">From</label>
<input type="date" id="e-from" class="field__control field__control--sm" data-filter="date_from">
<label class="visually-hidden" for="e-to">To</label>
<input type="date" id="e-to" class="field__control field__control--sm" data-filter="date_to">
<button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
<?php
$filterControls = (string) ob_get_clean();
?>

<?php if ($unresolved > 0): ?>
    <div class="alert alert--warning" role="status">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <span><?= e((string) $unresolved) ?> unresolved error group(s).</span>
    </div>
<?php endif; ?>

<div data-error-register data-can-resolve="<?= $can['resolve'] ? '1' : '0' ?>">
    <?= $this->component('data-table', [
        'id'           => 'errors-table',
        'endpoint'     => route('api.errors'),
        'sort'         => 'last_seen_at',
        'emptyMessage' => 'No errors match these filters.',
        'filterControls' => $filterControls,
        'columns'      => [
            ['key' => 'last_seen_at',    'label' => 'Last seen', 'sortable' => true, 'format' => 'datetime'],
            ['key' => 'severity',        'label' => 'Severity', 'sortable' => true, 'format' => 'badge'],
            ['key' => 'module',          'label' => 'Module'],
            ['key' => 'message',         'label' => 'Message'],
            ['key' => 'occurrence_count', 'label' => 'Times', 'sortable' => true, 'format' => 'number'],
            ['key' => 'reference',       'label' => 'Reference', 'class' => 'table__mono'],
            ['key' => 'resolved',        'label' => 'Resolved', 'format' => 'boolean'],
        ],
    ]) ?>
</div>

<?php ob_start(); ?>
<div class="lookup" data-reference-lookup data-endpoint="<?= e(url('/api/v1/errors/reference/')) ?>">
    <p class="form-note">
        The reference is the code shown on an error page. Pasting it here turns "something went wrong"
        into a diagnosis.
    </p>
    <div class="field field--full">
        <label class="field__label" for="ref-input">Reference</label>
        <input class="field__control" type="text" id="ref-input" data-reference-input autocomplete="off"
               spellcheck="false" data-uppercase placeholder="e.g. A1B2C3D4E5F6">
    </div>
    <div class="lookup__result" data-reference-result></div>
</div>
<?php $body = (string) ob_get_clean(); ?>
<?= $this->component('modal', [
    'id' => 'reference-lookup', 'title' => 'Find an error by reference', 'size' => 'md', 'body' => $body,
    'footer' => '<button type="button" class="button button--ghost" data-modal-close>Close</button>',
]) ?>
