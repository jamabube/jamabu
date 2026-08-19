<?php
/**
 * User accounts.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $summary
 * @var list<array<string,mixed>> $roles
 * @var list<array<string,mixed>> $departments
 * @var array<string,bool> $can
 */
$this->layout('layouts/app');

$statuses = (array) ($summary['statuses'] ?? []);

$this->start('page_subtitle');
echo 'Accounts are deactivated, never deleted: the audit trail names people, and a removed row would make years of accountable history anonymous.';
$this->stop();

$this->start('page_actions');
?>
<?php if ($can['create']): ?>
    <button type="button" class="button button--primary" data-modal-open="user-form" data-form-mode="create">
        <i class="fa-solid fa-user-plus" aria-hidden="true"></i> Create an account
    </button>
<?php endif; ?>
<?php if ($can['sessions']): ?>
    <button type="button" class="button button--ghost" data-modal-open="sessions-panel">
        <i class="fa-solid fa-users" aria-hidden="true"></i> Active sessions
    </button>
<?php endif; ?>
<?php
$this->stop();
?>

<section class="stat-grid stat-grid--compact">
    <?= $this->component('stat-card', ['label' => 'Active', 'value' => (string) ($statuses['active'] ?? 0), 'icon' => 'fa-user-check', 'tone' => 'success']) ?>
    <?= $this->component('stat-card', ['label' => 'Locked', 'value' => (string) ($summary['locked'] ?? 0), 'icon' => 'fa-lock', 'tone' => ($summary['locked'] ?? 0) > 0 ? 'danger' : 'neutral']) ?>
    <?= $this->component('stat-card', ['label' => 'Inactive', 'value' => (string) ($statuses['inactive'] ?? 0), 'icon' => 'fa-user-slash', 'tone' => 'neutral']) ?>
    <?= $this->component('stat-card', ['label' => 'Expired passwords', 'value' => (string) ($summary['expired_passwords'] ?? 0), 'icon' => 'fa-key', 'tone' => ($summary['expired_passwords'] ?? 0) > 0 ? 'warning' : 'neutral']) ?>
</section>

<?php
ob_start();
?>
<label class="visually-hidden" for="u-role">Role</label>
<select id="u-role" class="field__control field__control--sm" data-filter="role_id">
    <option value="">Any role</option>
    <?php foreach ($roles as $role): ?>
        <option value="<?= e((string) $role['role_id']) ?>"><?= e((string) $role['role_name']) ?></option>
    <?php endforeach; ?>
</select>

<label class="visually-hidden" for="u-department">Department</label>
<select id="u-department" class="field__control field__control--sm" data-filter="department_id">
    <option value="">Any department</option>
    <?php foreach ($departments as $department): ?>
        <option value="<?= e((string) $department['department_id']) ?>"><?= e((string) $department['department_name']) ?></option>
    <?php endforeach; ?>
</select>

<label class="visually-hidden" for="u-status">Status</label>
<select id="u-status" class="field__control field__control--sm" data-filter="status">
    <option value="">Any status</option>
    <option value="active">Active</option>
    <option value="inactive">Inactive</option>
    <option value="suspended">Suspended</option>
</select>

<label class="field__check field__check--inline">
    <input type="checkbox" data-filter="locked" value="1">
    <span>Locked only</span>
</label>

<button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
<?php
$filterControls = (string) ob_get_clean();
?>

<div data-user-admin
     data-can-lock="<?= $can['lock'] ? '1' : '0' ?>"
     data-can-unlock="<?= $can['unlock'] ? '1' : '0' ?>"
     data-can-reset="<?= $can['reset'] ? '1' : '0' ?>">
    <?= $this->component('data-table', [
        'id'           => 'users-table',
        'endpoint'     => route('api.users'),
        'sort'         => 'created_at',
        'rowLink'      => url('/users/{user_id}'),
        'emptyMessage' => 'No accounts match these filters.',
        'filterControls' => $filterControls,
        'columns'      => [
            ['key' => 'employee_number', 'label' => 'Employee', 'class' => 'table__mono', 'empty' => '—'],
            ['key' => 'full_name',       'label' => 'Name', 'sortable' => true, 'format' => 'strong'],
            ['key' => 'username',        'label' => 'Username', 'sortable' => true, 'class' => 'table__mono'],
            ['key' => 'role_name',       'label' => 'Role', 'sortable' => true],
            ['key' => 'department_name', 'label' => 'Department', 'empty' => '—'],
            ['key' => 'last_login_at',   'label' => 'Last signed in', 'sortable' => true, 'format' => 'datetime', 'empty' => 'Never'],
            ['key' => 'is_locked',       'label' => 'Locked', 'format' => 'boolean'],
            ['key' => 'status',          'label' => 'Status', 'sortable' => true, 'format' => 'badge'],
        ],
    ]) ?>
</div>

<?php if ($can['create'] || $can['update']): ?>
    <?php ob_start(); ?>
    <form data-ajax-form data-endpoint="<?= e(route('api.users.store')) ?>"
          data-update-endpoint="<?= e(url('/api/v1/users/{id}')) ?>"
          data-method="POST" data-success="The account was saved."
          data-secret-field="password"
          data-secret-message="Give this password to the account holder now. It is stored only as a hash and cannot be shown again; they must change it at first sign-in.">
        <input type="hidden" name="id" data-record-id>
        <div class="field-grid">
            <div class="field field--third">
                <label class="field__label" for="usr-first">First name<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="usr-first" name="first_name" required maxlength="60">
            </div>
            <div class="field field--third">
                <label class="field__label" for="usr-middle">Middle name</label>
                <input class="field__control" type="text" id="usr-middle" name="middle_name" maxlength="60">
            </div>
            <div class="field field--third">
                <label class="field__label" for="usr-last">Last name<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="usr-last" name="last_name" required maxlength="60">
            </div>

            <div class="field field--half">
                <label class="field__label" for="usr-username">Username<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="usr-username" name="username" required maxlength="50"
                       autocomplete="off" spellcheck="false" data-create-only data-lock-on-edit>
                <p class="field__help">Cannot be changed later — the audit trail refers to it.</p>
            </div>
            <div class="field field--half">
                <label class="field__label" for="usr-email">Email<span class="field__required">*</span></label>
                <input class="field__control" type="email" id="usr-email" name="email" required maxlength="150">
            </div>

            <div class="field field--half">
                <label class="field__label" for="usr-role">Role<span class="field__required">*</span></label>
                <select class="field__control" id="usr-role" name="role_id" required>
                    <option value="">Select a role</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e((string) $role['role_id']) ?>"><?= e((string) $role['role_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="field__help">Only roles at or below your own authority are listed.</p>
            </div>
            <div class="field field--half">
                <label class="field__label" for="usr-department">Department</label>
                <select class="field__control" id="usr-department" name="department_id">
                    <option value="">Not assigned</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department['department_id']) ?>"><?= e((string) $department['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field field--half">
                <label class="field__label" for="usr-employee">Employee number</label>
                <input class="field__control" type="text" id="usr-employee" name="employee_number" maxlength="30"
                       placeholder="Generated automatically if left empty">
            </div>
            <div class="field field--half">
                <label class="field__label" for="usr-position">Position</label>
                <input class="field__control" type="text" id="usr-position" name="position" maxlength="80">
            </div>

            <div class="field field--half">
                <label class="field__label" for="usr-mobile">Mobile number</label>
                <input class="field__control" type="text" id="usr-mobile" name="mobile_number" maxlength="30">
            </div>
            <div class="field field--half">
                <label class="field__label" for="usr-status">Status</label>
                <select class="field__control" id="usr-status" name="status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>

            <div class="field field--full" data-create-only>
                <label class="field__label" for="usr-password">Initial password</label>
                <input class="field__control" type="text" id="usr-password" name="password" maxlength="128"
                       autocomplete="off" placeholder="Leave empty to generate a strong one">
                <p class="field__help">Whichever is used, the account must change it at first sign-in.</p>
            </div>
        </div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'user-form', 'title' => 'User account', 'size' => 'lg', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="user-form">Save account</button>',
    ]) ?>
<?php endif; ?>

<?php if ($can['sessions']): ?>
    <?php ob_start(); ?>
    <div data-sessions-panel data-endpoint="<?= e(route('api.users.sessions')) ?>">
        <p class="form-note">Every session currently open, on any workstation. Ending one signs that person out immediately.</p>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">User</th>
                        <th scope="col">Signed in</th>
                        <th scope="col">Last activity</th>
                        <th scope="col">From</th>
                        <th scope="col" class="table__actions">Action</th>
                    </tr>
                </thead>
                <tbody data-sessions-body>
                    <tr class="table__placeholder"><td colspan="5">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'sessions-panel', 'title' => 'Active sessions', 'size' => 'lg', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Close</button>',
    ]) ?>
<?php endif; ?>
