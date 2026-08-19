<?php
/**
 * Windshield tag inventory.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $summary
 * @var array<string,int> $statuses
 * @var list<array<string,mixed>> $vehicles
 * @var list<string> $tagTypes
 * @var array<string,bool> $can
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'The physical tags fitted to vehicles. A tag and a visitor card share one UID namespace, because the reader cannot tell them apart.';
$this->stop();

$this->start('page_actions');
?>
<?php if ($can['create']): ?>
    <button type="button" class="button button--primary" data-modal-open="tag-form" data-form-mode="create">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> Add a tag
    </button>
<?php endif; ?>
<button type="button" class="button button--ghost" data-modal-open="uid-lookup">
    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Identify a UID
</button>
<?php
$this->stop();
?>

<section class="stat-grid stat-grid--compact">
    <?= $this->component('stat-card', ['label' => 'Assigned', 'value' => (string) ($statuses['assigned'] ?? 0), 'icon' => 'fa-link', 'tone' => 'success']) ?>
    <?= $this->component('stat-card', ['label' => 'Available', 'value' => (string) ($statuses['available'] ?? 0), 'icon' => 'fa-box-open', 'tone' => 'accent']) ?>
    <?= $this->component('stat-card', ['label' => 'Lost or damaged', 'value' => (string) (($statuses['lost'] ?? 0) + ($statuses['damaged'] ?? 0)), 'icon' => 'fa-triangle-exclamation', 'tone' => 'danger']) ?>
    <?= $this->component('stat-card', ['label' => 'Expired', 'value' => (string) ($statuses['expired'] ?? 0), 'icon' => 'fa-hourglass-end', 'tone' => 'warning']) ?>
</section>

<?php
ob_start();
?>
<label class="visually-hidden" for="t-status">Status</label>
<select id="t-status" class="field__control field__control--sm" data-filter="status">
    <option value="">Any status</option>
    <?php foreach (['available', 'assigned', 'inactive', 'lost', 'damaged', 'expired', 'revoked'] as $status): ?>
        <option value="<?= e($status) ?>"><?= e(ucfirst($status)) ?></option>
    <?php endforeach; ?>
</select>

<label class="visually-hidden" for="t-type">Type</label>
<select id="t-type" class="field__control field__control--sm" data-filter="tag_type">
    <option value="">Any tag type</option>
    <?php foreach ($tagTypes as $type): ?>
        <option value="<?= e($type) ?>"><?= e(ucwords(str_replace('_', ' ', $type))) ?></option>
    <?php endforeach; ?>
</select>

<label class="visually-hidden" for="t-assignment">Assignment</label>
<select id="t-assignment" class="field__control field__control--sm" data-filter="assignment">
    <option value="">Assigned or not</option>
    <option value="assigned">On a vehicle</option>
    <option value="unassigned">In the drawer</option>
</select>

<label class="field__check field__check--inline">
    <input type="checkbox" data-filter="expiring" value="1">
    <span>Expiring soon</span>
</label>

<button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
<?php
$filterControls = (string) ob_get_clean();
?>

<?= $this->component('data-table', [
    'id'           => 'tags-table',
    'endpoint'     => route('api.rfid.tags'),
    'sort'         => 'created_at',
    'emptyMessage' => 'No tags match these filters.',
    'filterControls' => $filterControls,
    'columns'      => [
        ['key' => 'tag_code',        'label' => 'Code', 'sortable' => true, 'class' => 'table__mono'],
        ['key' => 'rfid_uid',        'label' => 'UID', 'sortable' => true, 'class' => 'table__mono'],
        ['key' => 'tag_type',        'label' => 'Type'],
        ['key' => 'plate_number',    'label' => 'Fitted to', 'empty' => 'Unassigned'],
        ['key' => 'expiration_date', 'label' => 'Expires', 'sortable' => true, 'format' => 'date', 'empty' => 'Never'],
        ['key' => 'scan_count',      'label' => 'Scans', 'sortable' => true, 'format' => 'number'],
        ['key' => 'last_scanned_at', 'label' => 'Last read', 'sortable' => true, 'format' => 'datetime', 'empty' => 'Never'],
        ['key' => 'status',          'label' => 'Status', 'sortable' => true, 'format' => 'badge'],
    ],
]) ?>

<?php if ($can['create'] || $can['update']): ?>
    <?php ob_start(); ?>
    <form data-ajax-form data-endpoint="<?= e(route('api.rfid.tags.store')) ?>"
          data-update-endpoint="<?= e(url('/api/v1/rfid/tags/{id}')) ?>"
          data-method="POST" data-success="The tag was saved." data-reload-on-success="true">
        <input type="hidden" name="id" data-record-id>
        <div class="field-grid">
            <div class="field field--half">
                <label class="field__label" for="tag-uid">RFID UID<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="tag-uid" name="rfid_uid" required maxlength="32"
                       autocomplete="off" spellcheck="false" data-uppercase data-lock-on-edit>
                <p class="field__help">Hexadecimal, 8 to 32 characters. Cannot be changed later — a different UID is different hardware.</p>
            </div>
            <div class="field field--half">
                <label class="field__label" for="tag-code">Tag code</label>
                <input class="field__control" type="text" id="tag-code" name="tag_code" maxlength="20"
                       placeholder="Generated automatically if left empty">
            </div>
            <div class="field field--half">
                <label class="field__label" for="tag-type">Tag type</label>
                <select class="field__control" id="tag-type" name="tag_type">
                    <?php foreach ($tagTypes as $type): ?>
                        <option value="<?= e($type) ?>"><?= e(ucwords(str_replace('_', ' ', $type))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field--half">
                <label class="field__label" for="tag-frequency">Frequency</label>
                <input class="field__control" type="text" id="tag-frequency" name="frequency" maxlength="30"
                       placeholder="e.g. 865-868 MHz">
            </div>
            <div class="field field--half">
                <label class="field__label" for="tag-serial">Serial number</label>
                <input class="field__control" type="text" id="tag-serial" name="serial_number" maxlength="60">
            </div>
            <div class="field field--half">
                <label class="field__label" for="tag-activation">Activation date</label>
                <input class="field__control" type="date" id="tag-activation" name="activation_date">
            </div>
            <div class="field field--half">
                <label class="field__label" for="tag-expiry">Expiry date</label>
                <input class="field__control" type="date" id="tag-expiry" name="expiration_date">
                <p class="field__help">Leave empty for a tag that does not expire.</p>
            </div>
            <div class="field field--full">
                <label class="field__label" for="tag-remarks">Remarks</label>
                <textarea class="field__control" id="tag-remarks" name="remarks" rows="2"></textarea>
            </div>
        </div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'tag-form', 'title' => 'RFID tag', 'size' => 'lg', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="tag-form">Save tag</button>',
    ]) ?>
<?php endif; ?>

<?php ob_start(); ?>
<div class="lookup" data-uid-lookup data-endpoint="<?= e(route('api.rfid.lookup')) ?>">
    <p class="form-note">
        Hold a tag or card against a reader, or type its UID, to see whether the system already knows it.
    </p>
    <div class="field field--full">
        <label class="field__label" for="lookup-uid">UID</label>
        <input class="field__control" type="text" id="lookup-uid" data-uid-input autocomplete="off"
               spellcheck="false" data-uppercase placeholder="e.g. 04A2B3C4">
    </div>
    <div class="lookup__result" data-uid-result></div>
</div>
<?php $body = (string) ob_get_clean(); ?>
<?= $this->component('modal', [
    'id' => 'uid-lookup', 'title' => 'Identify a credential', 'size' => 'md', 'body' => $body,
    'footer' => '<button type="button" class="button button--ghost" data-modal-close>Close</button>',
]) ?>
