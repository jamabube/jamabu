<?php
/**
 * Vehicle registry.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $summary
 * @var list<array<string,mixed>> $vehicleTypes
 * @var list<array<string,mixed>> $owners
 * @var list<array<string,mixed>> $drivers
 * @var list<array<string,mixed>> $tags
 * @var array<string,bool> $can
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'Every vehicle authorised to enter, and the tag that identifies it at the gate.';
$this->stop();

$this->start('page_actions');
?>
<?php if ($can['create']): ?>
    <button type="button" class="button button--primary" data-modal-open="vehicle-form" data-form-mode="create">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> Register a vehicle
    </button>
<?php endif; ?>
<?php if ($can['export']): ?>
    <a class="button button--ghost" href="<?= e(url('/api/v1/reports/vehicle-directory/export/excel')) ?>">
        <i class="fa-solid fa-file-excel" aria-hidden="true"></i> Export
    </a>
<?php endif; ?>
<?php
$this->stop();

$statuses = (array) ($summary['statuses'] ?? []);
?>

<section class="stat-grid stat-grid--compact">
    <?= $this->component('stat-card', ['label' => 'Active', 'value' => (string) ($statuses['active'] ?? 0), 'icon' => 'fa-circle-check', 'tone' => 'success']) ?>
    <?= $this->component('stat-card', ['label' => 'Inactive', 'value' => (string) ($statuses['inactive'] ?? 0), 'icon' => 'fa-circle-pause', 'tone' => 'neutral']) ?>
    <?= $this->component('stat-card', ['label' => 'Suspended', 'value' => (string) ($statuses['suspended'] ?? 0), 'icon' => 'fa-ban', 'tone' => 'danger']) ?>
    <?= $this->component('stat-card', ['label' => 'Without a tag', 'value' => (string) ($summary['untagged'] ?? 0), 'icon' => 'fa-tag', 'tone' => 'warning', 'caption' => 'cannot be read at the gate']) ?>
</section>

<?php
ob_start();
?>
<label class="visually-hidden" for="v-status">Status</label>
<select id="v-status" class="field__control field__control--sm" data-filter="status">
    <option value="">Any status</option>
    <option value="active">Active</option>
    <option value="inactive">Inactive</option>
    <option value="suspended">Suspended</option>
    <option value="archived">Archived</option>
</select>

<label class="visually-hidden" for="v-type">Type</label>
<select id="v-type" class="field__control field__control--sm" data-filter="vehicle_type_id">
    <option value="">Any type</option>
    <?php foreach ($vehicleTypes as $type): ?>
        <option value="<?= e((string) $type['vehicle_type_id']) ?>"><?= e((string) $type['type_name']) ?></option>
    <?php endforeach; ?>
</select>

<label class="visually-hidden" for="v-presence">Presence</label>
<select id="v-presence" class="field__control field__control--sm" data-filter="presence">
    <option value="">Anywhere</option>
    <option value="inside">Inside now</option>
    <option value="outside">Outside</option>
</select>

<label class="visually-hidden" for="v-tag">Tag state</label>
<select id="v-tag" class="field__control field__control--sm" data-filter="tag_state">
    <option value="">Any tag state</option>
    <option value="unassigned">No tag assigned</option>
    <option value="expiring">Tag expiring soon</option>
</select>

<button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
<?php
$filterControls = (string) ob_get_clean();
?>

<?= $this->component('data-table', [
    'id'           => 'vehicles-table',
    'endpoint'     => route('api.vehicles'),
    'sort'         => 'created_at',
    'rowLink'      => url('/vehicles/{vehicle_id}'),
    'emptyMessage' => 'No vehicles match these filters.',
    'filterControls' => $filterControls,
    'columns'      => [
        ['key' => 'plate_number',  'label' => 'Plate', 'sortable' => true, 'format' => 'strong'],
        ['key' => 'vehicle_code',  'label' => 'Code', 'class' => 'table__mono'],
        ['key' => 'vehicle_type',  'label' => 'Type'],
        ['key' => 'brand',         'label' => 'Make and model', 'format' => 'text'],
        ['key' => 'owner_name',    'label' => 'Owner', 'sortable' => false],
        ['key' => 'driver_name',   'label' => 'Driver', 'empty' => 'Not assigned'],
        ['key' => 'rfid_uid',      'label' => 'Tag', 'class' => 'table__mono', 'empty' => 'None'],
        ['key' => 'presence',      'label' => 'Presence', 'format' => 'badge'],
        ['key' => 'status',        'label' => 'Status', 'sortable' => true, 'format' => 'badge'],
    ],
]) ?>

<?php if ($can['create'] || $can['update']): ?>
    <?php
    ob_start();
    ?>
    <form data-ajax-form
          data-endpoint="<?= e(route('api.vehicles.store')) ?>"
          data-update-endpoint="<?= e(url('/api/v1/vehicles/{id}')) ?>"
          data-method="POST"
          data-success="The vehicle was saved."
          data-reload-on-success="true">
        <input type="hidden" name="id" data-record-id>
        <div class="field-grid">
            <div class="field field--half">
                <label class="field__label" for="veh-plate">Plate number<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="veh-plate" name="plate_number" required
                       autocomplete="off" spellcheck="false" maxlength="20" data-uppercase>
                <p class="field__help">Stored in a normalised form, so spacing and case do not matter.</p>
            </div>
            <div class="field field--half">
                <label class="field__label" for="veh-type">Vehicle type<span class="field__required">*</span></label>
                <select class="field__control" id="veh-type" name="vehicle_type_id" required>
                    <option value="">Select a type</option>
                    <?php foreach ($vehicleTypes as $type): ?>
                        <option value="<?= e((string) $type['vehicle_type_id']) ?>"><?= e((string) $type['type_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field--half">
                <label class="field__label" for="veh-owner">Owner<span class="field__required">*</span></label>
                <select class="field__control" id="veh-owner" name="owner_id" required data-searchable>
                    <option value="">Select an owner</option>
                    <?php foreach ($owners as $owner): ?>
                        <option value="<?= e((string) $owner['owner_id']) ?>">
                            <?= e((string) $owner['full_name']) ?> (<?= e((string) $owner['owner_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field--half">
                <label class="field__label" for="veh-driver">Usual driver</label>
                <select class="field__control" id="veh-driver" name="driver_id" data-searchable>
                    <option value="">Not assigned</option>
                    <?php foreach ($drivers as $driver): ?>
                        <option value="<?= e((string) $driver['driver_id']) ?>">
                            <?= e((string) $driver['full_name']) ?> (<?= e((string) $driver['driver_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field--half">
                <label class="field__label" for="veh-tag">RFID tag</label>
                <select class="field__control" id="veh-tag" name="rfid_tag_id"
                        data-refresh-from="<?= e(route('api.rfid.tags.available')) ?>"
                        data-option-value="rfid_tag_id" data-option-label="label">
                    <option value="">No tag yet</option>
                    <?php foreach ($tags as $tag): ?>
                        <option value="<?= e((string) $tag['rfid_tag_id']) ?>">
                            <?= e((string) $tag['tag_code']) ?> — <?= e((string) $tag['rfid_uid']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field__help">Only unassigned tags are listed. A vehicle without one cannot be read at the gate.</p>
            </div>
            <div class="field field--half">
                <label class="field__label" for="veh-status">Status</label>
                <select class="field__control" id="veh-status" name="status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>

            <div class="field field--third">
                <label class="field__label" for="veh-brand">Make</label>
                <input class="field__control" type="text" id="veh-brand" name="brand" maxlength="60">
            </div>
            <div class="field field--third">
                <label class="field__label" for="veh-model">Model</label>
                <input class="field__control" type="text" id="veh-model" name="model" maxlength="60">
            </div>
            <div class="field field--third">
                <label class="field__label" for="veh-colour">Colour</label>
                <input class="field__control" type="text" id="veh-colour" name="colour" maxlength="40">
            </div>
            <div class="field field--third">
                <label class="field__label" for="veh-year">Year</label>
                <input class="field__control" type="number" id="veh-year" name="year_model" min="1900" max="2100">
            </div>
            <div class="field field--third">
                <label class="field__label" for="veh-chassis">Chassis number</label>
                <input class="field__control" type="text" id="veh-chassis" name="chassis_number" maxlength="60">
            </div>
            <div class="field field--third">
                <label class="field__label" for="veh-engine">Engine number</label>
                <input class="field__control" type="text" id="veh-engine" name="engine_number" maxlength="60">
            </div>

            <div class="field field--half">
                <label class="field__label" for="veh-insurer">Insurance provider</label>
                <input class="field__control" type="text" id="veh-insurer" name="insurance_provider" maxlength="120">
            </div>
            <div class="field field--half">
                <label class="field__label" for="veh-insurance">Insurance expiry</label>
                <input class="field__control" type="date" id="veh-insurance" name="insurance_expiry">
            </div>
            <div class="field field--full">
                <label class="field__label" for="veh-remarks">Remarks</label>
                <textarea class="field__control" id="veh-remarks" name="remarks" rows="2"></textarea>
            </div>
        </div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'vehicle-form', 'title' => 'Vehicle', 'size' => 'lg', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="vehicle-form">Save vehicle</button>',
    ]) ?>
<?php endif; ?>
