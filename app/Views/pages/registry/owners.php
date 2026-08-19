<?php
/**
 * Vehicle owners.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var list<array<string,mixed>> $departments
 * @var list<string> $categories
 * @var array<string,bool> $can
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'The person or organisation accountable for each registered vehicle.';
$this->stop();

$this->start('page_actions');
?>
<?php if ($can['create']): ?>
    <button type="button" class="button button--primary" data-modal-open="owner-form" data-form-mode="create">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> Register an owner
    </button>
<?php endif; ?>
<?php
$this->stop();

ob_start();
?>
<label class="visually-hidden" for="o-category">Category</label>
<select id="o-category" class="field__control field__control--sm" data-filter="owner_category">
    <option value="">Any category</option>
    <?php foreach ($categories as $category): ?>
        <option value="<?= e($category) ?>"><?= e(ucfirst($category)) ?></option>
    <?php endforeach; ?>
</select>

<label class="visually-hidden" for="o-department">Department</label>
<select id="o-department" class="field__control field__control--sm" data-filter="department_id">
    <option value="">Any department</option>
    <?php foreach ($departments as $department): ?>
        <option value="<?= e((string) $department['department_id']) ?>"><?= e((string) $department['department_name']) ?></option>
    <?php endforeach; ?>
</select>

<label class="visually-hidden" for="o-status">Status</label>
<select id="o-status" class="field__control field__control--sm" data-filter="status">
    <option value="">Any status</option>
    <option value="active">Active</option>
    <option value="inactive">Inactive</option>
</select>

<button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
<?php
$filterControls = (string) ob_get_clean();
?>

<?= $this->component('data-table', [
    'id'           => 'owners-table',
    'endpoint'     => route('api.owners'),
    'sort'         => 'created_at',
    'rowLink'      => url('/owners/{owner_id}'),
    'emptyMessage' => 'No owners match these filters.',
    'filterControls' => $filterControls,
    'columns'      => [
        ['key' => 'owner_code',      'label' => 'Code', 'sortable' => true, 'class' => 'table__mono'],
        ['key' => 'full_name',       'label' => 'Name', 'sortable' => true, 'format' => 'strong'],
        ['key' => 'owner_category',  'label' => 'Category', 'sortable' => true],
        ['key' => 'company',         'label' => 'Company', 'empty' => '—'],
        ['key' => 'department_name', 'label' => 'Department', 'empty' => '—'],
        ['key' => 'contact_number',  'label' => 'Contact', 'empty' => '—'],
        ['key' => 'vehicle_count',   'label' => 'Vehicles', 'format' => 'number'],
        ['key' => 'status',          'label' => 'Status', 'format' => 'badge'],
    ],
]) ?>

<?php if ($can['create'] || $can['update']): ?>
    <?php ob_start(); ?>
    <form data-ajax-form
          data-endpoint="<?= e(route('api.owners.store')) ?>"
          data-update-endpoint="<?= e(url('/api/v1/owners/{id}')) ?>"
          data-method="POST" data-success="The owner was saved." data-reload-on-success="true">
        <input type="hidden" name="id" data-record-id>
        <div class="field-grid">
            <div class="field field--third">
                <label class="field__label" for="own-first">First name<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="own-first" name="first_name" required maxlength="60">
            </div>
            <div class="field field--third">
                <label class="field__label" for="own-middle">Middle name</label>
                <input class="field__control" type="text" id="own-middle" name="middle_name" maxlength="60">
            </div>
            <div class="field field--third">
                <label class="field__label" for="own-last">Last name<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="own-last" name="last_name" required maxlength="60">
            </div>

            <div class="field field--half">
                <label class="field__label" for="own-category">Category<span class="field__required">*</span></label>
                <select class="field__control" id="own-category" name="owner_category" required>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e($category) ?>"><?= e(ucfirst($category)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field--half">
                <label class="field__label" for="own-department">Department</label>
                <select class="field__control" id="own-department" name="department_id">
                    <option value="">Not applicable</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department['department_id']) ?>"><?= e((string) $department['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field field--half">
                <label class="field__label" for="own-company">Company</label>
                <input class="field__control" type="text" id="own-company" name="company" maxlength="120">
            </div>
            <div class="field field--half">
                <label class="field__label" for="own-govid">Government ID</label>
                <input class="field__control" type="text" id="own-govid" name="government_id" maxlength="60">
            </div>

            <div class="field field--half">
                <label class="field__label" for="own-contact">Contact number</label>
                <input class="field__control" type="text" id="own-contact" name="contact_number" maxlength="30">
            </div>
            <div class="field field--half">
                <label class="field__label" for="own-email">Email</label>
                <input class="field__control" type="email" id="own-email" name="email" maxlength="150">
            </div>

            <div class="field field--full">
                <label class="field__label" for="own-address">Address</label>
                <input class="field__control" type="text" id="own-address" name="address" maxlength="255">
            </div>
            <div class="field field--half">
                <label class="field__label" for="own-status">Status</label>
                <select class="field__control" id="own-status" name="status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="field field--full">
                <label class="field__label" for="own-remarks">Remarks</label>
                <textarea class="field__control" id="own-remarks" name="remarks" rows="2"></textarea>
            </div>
        </div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'owner-form', 'title' => 'Vehicle owner', 'size' => 'lg', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="owner-form">Save owner</button>',
    ]) ?>
<?php endif; ?>
