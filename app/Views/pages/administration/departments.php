<?php
/**
 * Departments.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,bool> $can
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'Used to group users and vehicle owners. A department with people still attached cannot be removed.';
$this->stop();

$this->start('page_actions');
?>
<?php if ($can['create']): ?>
    <button type="button" class="button button--primary" data-modal-open="department-form" data-form-mode="create">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> Add a department
    </button>
<?php endif; ?>
<?php
$this->stop();

ob_start();
?>
<label class="visually-hidden" for="dep-status">Status</label>
<select id="dep-status" class="field__control field__control--sm" data-filter="status">
    <option value="">Any status</option>
    <option value="active">Active</option>
    <option value="inactive">Inactive</option>
</select>
<button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
<?php
$filterControls = (string) ob_get_clean();
?>

<div data-department-admin data-can-update="<?= $can['update'] ? '1' : '0' ?>" data-can-delete="<?= $can['delete'] ? '1' : '0' ?>">
    <?= $this->component('data-table', [
        'id'           => 'departments-table',
        'endpoint'     => route('api.departments'),
        'sort'         => 'department_name',
        'direction'    => 'ASC',
        'emptyMessage' => 'No departments defined.',
        'filterControls' => $filterControls,
        'columns'      => [
            ['key' => 'department_code', 'label' => 'Code', 'sortable' => true, 'class' => 'table__mono'],
            ['key' => 'department_name', 'label' => 'Name', 'sortable' => true, 'format' => 'strong'],
            ['key' => 'description',     'label' => 'Description', 'empty' => '—'],
            ['key' => 'contact_number',  'label' => 'Contact', 'empty' => '—'],
            ['key' => 'user_count',      'label' => 'Users', 'format' => 'number'],
            ['key' => 'owner_count',     'label' => 'Owners', 'format' => 'number'],
            ['key' => 'status',          'label' => 'Status', 'sortable' => true, 'format' => 'badge'],
        ],
    ]) ?>
</div>

<?php if ($can['create'] || $can['update']): ?>
    <?php ob_start(); ?>
    <form data-ajax-form data-endpoint="<?= e(route('api.departments.store')) ?>"
          data-update-endpoint="<?= e(url('/api/v1/departments/{id}')) ?>"
          data-method="POST" data-success="The department was saved." data-reload-on-success="true">
        <input type="hidden" name="id" data-record-id>
        <div class="field-grid">
            <div class="field field--half">
                <label class="field__label" for="dep-name">Name<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="dep-name" name="department_name" required maxlength="120">
            </div>
            <div class="field field--half">
                <label class="field__label" for="dep-code">Code</label>
                <input class="field__control" type="text" id="dep-code" name="department_code" maxlength="20"
                       placeholder="Generated automatically if left empty">
            </div>
            <div class="field field--half">
                <label class="field__label" for="dep-contact">Contact number</label>
                <input class="field__control" type="text" id="dep-contact" name="contact_number" maxlength="30">
            </div>
            <div class="field field--half">
                <label class="field__label" for="dep-status-field">Status</label>
                <select class="field__control" id="dep-status-field" name="status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="field field--full">
                <label class="field__label" for="dep-description">Description</label>
                <input class="field__control" type="text" id="dep-description" name="description" maxlength="255">
            </div>
        </div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'department-form', 'title' => 'Department', 'size' => 'md', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="department-form">Save department</button>',
    ]) ?>
<?php endif; ?>
