<?php
/**
 * Driver registry.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,int> $statuses
 * @var list<array<string,mixed>> $owners
 * @var array<string,bool> $can
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'The people who drive the registered vehicles, and the licences that identify them.';
$this->stop();

$this->start('page_actions');
?>
<?php if ($can['create']): ?>
    <button type="button" class="button button--primary" data-modal-open="driver-form" data-form-mode="create">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> Register a driver
    </button>
<?php endif; ?>
<?php if ($can['export']): ?>
    <a class="button button--ghost" href="<?= e(url('/api/v1/reports/driver_registry/export/excel')) ?>">
        <i class="fa-solid fa-file-excel" aria-hidden="true"></i> Export
    </a>
<?php endif; ?>
<?php
$this->stop();
?>

<section class="stat-grid stat-grid--compact">
    <?= $this->component('stat-card', ['label' => 'Active', 'value' => (string) ($statuses['active'] ?? 0), 'icon' => 'fa-id-card', 'tone' => 'success']) ?>
    <?= $this->component('stat-card', ['label' => 'Inactive', 'value' => (string) ($statuses['inactive'] ?? 0), 'icon' => 'fa-circle-pause', 'tone' => 'neutral']) ?>
    <?= $this->component('stat-card', ['label' => 'Suspended', 'value' => (string) ($statuses['suspended'] ?? 0), 'icon' => 'fa-ban', 'tone' => 'danger']) ?>
</section>

<?php
ob_start();
?>
<label class="visually-hidden" for="d-status">Status</label>
<select id="d-status" class="field__control field__control--sm" data-filter="status">
    <option value="">Any status</option>
    <option value="active">Active</option>
    <option value="inactive">Inactive</option>
    <option value="suspended">Suspended</option>
</select>

<label class="visually-hidden" for="d-licence">Licence</label>
<select id="d-licence" class="field__control field__control--sm" data-filter="licence">
    <option value="">Any licence state</option>
    <option value="expiring">Expiring within 30 days</option>
    <option value="expired">Expired</option>
</select>

<label class="visually-hidden" for="d-fingerprint">Fingerprint</label>
<select id="d-fingerprint" class="field__control field__control--sm" data-filter="fingerprint">
    <option value="">Any enrolment state</option>
    <option value="enrolled">Fingerprint enrolled</option>
    <option value="missing">No fingerprint</option>
</select>

<button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
<?php
$filterControls = (string) ob_get_clean();
?>

<?= $this->component('data-table', [
    'id'           => 'drivers-table',
    'endpoint'     => route('api.drivers'),
    'sort'         => 'created_at',
    'rowLink'      => url('/drivers/{driver_id}'),
    'emptyMessage' => 'No drivers match these filters.',
    'filterControls' => $filterControls,
    'columns'      => [
        ['key' => 'driver_code',    'label' => 'Code', 'sortable' => true, 'class' => 'table__mono'],
        ['key' => 'full_name',      'label' => 'Name', 'sortable' => true, 'format' => 'strong'],
        ['key' => 'contact_number', 'label' => 'Contact', 'empty' => 'Not recorded'],
        ['key' => 'government_id',  'label' => 'Licence', 'class' => 'table__mono', 'empty' => 'Not recorded'],
        ['key' => 'licence_expiry', 'label' => 'Licence expiry', 'sortable' => true, 'format' => 'date', 'empty' => '—'],
        ['key' => 'vehicle_count',  'label' => 'Vehicles', 'format' => 'number'],
        ['key' => 'status',         'label' => 'Status', 'sortable' => true, 'format' => 'badge'],
    ],
]) ?>

<?php if ($can['create'] || $can['update']): ?>
    <?php ob_start(); ?>
    <form data-ajax-form
          data-endpoint="<?= e(route('api.drivers.store')) ?>"
          data-update-endpoint="<?= e(url('/api/v1/drivers/{id}')) ?>"
          data-method="POST" data-success="The driver was saved." data-reload-on-success="true">
        <input type="hidden" name="id" data-record-id>
        <div class="field-grid">
            <div class="field field--third">
                <label class="field__label" for="drv-first">First name<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="drv-first" name="first_name" required maxlength="60">
            </div>
            <div class="field field--third">
                <label class="field__label" for="drv-middle">Middle name</label>
                <input class="field__control" type="text" id="drv-middle" name="middle_name" maxlength="60">
            </div>
            <div class="field field--third">
                <label class="field__label" for="drv-last">Last name<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="drv-last" name="last_name" required maxlength="60">
            </div>

            <div class="field field--half">
                <label class="field__label" for="drv-licence">Licence number</label>
                <input class="field__control" type="text" id="drv-licence" name="government_id" maxlength="60">
                <p class="field__help">Must be unique: two records sharing a licence make the history ambiguous.</p>
            </div>
            <div class="field field--half">
                <label class="field__label" for="drv-expiry">Licence expiry</label>
                <input class="field__control" type="date" id="drv-expiry" name="licence_expiry">
            </div>

            <div class="field field--half">
                <label class="field__label" for="drv-contact">Contact number</label>
                <input class="field__control" type="text" id="drv-contact" name="contact_number" maxlength="30">
            </div>
            <div class="field field--half">
                <label class="field__label" for="drv-email">Email</label>
                <input class="field__control" type="email" id="drv-email" name="email" maxlength="150">
            </div>

            <div class="field field--half">
                <label class="field__label" for="drv-owner">Linked owner</label>
                <select class="field__control" id="drv-owner" name="owner_id" data-searchable>
                    <option value="">Not linked</option>
                    <?php foreach ($owners as $owner): ?>
                        <option value="<?= e((string) $owner['owner_id']) ?>"><?= e((string) $owner['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="field__help">Set when the driver is also a registered vehicle owner.</p>
            </div>
            <div class="field field--half">
                <label class="field__label" for="drv-status">Status</label>
                <select class="field__control" id="drv-status" name="status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>

            <div class="field field--full">
                <label class="field__label" for="drv-address">Address</label>
                <input class="field__control" type="text" id="drv-address" name="address" maxlength="255">
            </div>
            <div class="field field--half">
                <label class="field__label" for="drv-emg-name">Emergency contact</label>
                <input class="field__control" type="text" id="drv-emg-name" name="emergency_contact_name" maxlength="120">
            </div>
            <div class="field field--half">
                <label class="field__label" for="drv-emg-number">Emergency number</label>
                <input class="field__control" type="text" id="drv-emg-number" name="emergency_contact_number" maxlength="30">
            </div>
            <div class="field field--full">
                <label class="field__label" for="drv-remarks">Remarks</label>
                <textarea class="field__control" id="drv-remarks" name="remarks" rows="2"></textarea>
            </div>
        </div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'driver-form', 'title' => 'Driver', 'size' => 'lg', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="driver-form">Save driver</button>',
    ]) ?>
<?php endif; ?>
