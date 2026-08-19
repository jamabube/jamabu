<?php
/**
 * Roles and their grants.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var list<array<string,mixed>> $roles
 * @var array<string,list<array<string,mixed>>> $modules
 * @var array<string,bool> $can
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'Permissions are granted to roles, never to individuals. That is what keeps "what can a guard do?" answerable in one place.';
$this->stop();

$this->start('page_actions');
?>
<?php if ($can['create']): ?>
    <button type="button" class="button button--primary" data-modal-open="role-form">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> Create a role
    </button>
<?php endif; ?>
<?php
$this->stop();
?>

<div class="role-grid">
    <?php foreach ($roles as $role): ?>
        <article class="role-card">
            <header class="role-card__header">
                <div>
                    <h2 class="role-card__name">
                        <a href="<?= e(url('/roles/' . (string) $role['role_id'])) ?>"><?= e((string) $role['role_name']) ?></a>
                    </h2>
                    <p class="role-card__slug table__mono"><?= e((string) $role['role_slug']) ?></p>
                </div>
                <?php if ((int) ($role['is_system'] ?? 0) === 1): ?>
                    <span class="badge badge--neutral" title="Referenced by code and seed data">System</span>
                <?php endif; ?>
            </header>

            <p class="role-card__description"><?= e((string) ($role['description'] ?? 'No description.')) ?></p>

            <dl class="role-card__stats">
                <div><dt>Authority</dt><dd><?= e((string) $role['priority']) ?></dd></div>
                <div><dt>Members</dt><dd><?= e((string) ($role['user_count'] ?? 0)) ?></dd></div>
                <div><dt>Permissions</dt><dd><?= e((string) ($role['permission_count'] ?? 0)) ?></dd></div>
            </dl>

            <footer class="role-card__actions">
                <a class="button button--sm button--ghost" href="<?= e(url('/roles/' . (string) $role['role_id'])) ?>">
                    Permissions
                </a>
                <?php if ($can['create']): ?>
                    <button type="button" class="button button--sm button--ghost"
                            data-duplicate-role="<?= e((string) $role['role_id']) ?>"
                            data-role-name="<?= e((string) $role['role_name']) ?>">Duplicate</button>
                <?php endif; ?>
                <?php if ($can['delete'] && (int) ($role['is_system'] ?? 0) !== 1): ?>
                    <button type="button" class="button button--sm button--danger-ghost"
                            data-delete-role="<?= e((string) $role['role_id']) ?>"
                            data-role-name="<?= e((string) $role['role_name']) ?>"
                            data-member-count="<?= e((string) ($role['user_count'] ?? 0)) ?>">Remove</button>
                <?php endif; ?>
            </footer>
        </article>
    <?php endforeach; ?>
</div>

<?php if ($can['create']): ?>
    <?php ob_start(); ?>
    <form data-ajax-form data-endpoint="<?= e(route('api.roles.store')) ?>"
          data-method="POST" data-success="The role was created." data-reload-on-success="true">
        <p class="form-note">
            Starting from a copy of an existing role is usually safer than assembling one from nothing —
            omissions only show up at the gate.
        </p>
        <div class="field-grid">
            <div class="field field--half">
                <label class="field__label" for="role-name">Role name<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="role-name" name="role_name" required maxlength="60">
            </div>
            <div class="field field--half">
                <label class="field__label" for="role-priority">Authority level</label>
                <input class="field__control" type="number" id="role-priority" name="priority" min="1" max="999" value="100">
                <p class="field__help">Lower numbers rank higher. You cannot create a role above your own.</p>
            </div>
            <div class="field field--full">
                <label class="field__label" for="role-description">Description</label>
                <input class="field__control" type="text" id="role-description" name="description" maxlength="255">
            </div>
        </div>

        <fieldset class="permission-picker">
            <legend class="permission-picker__legend">Permissions</legend>
            <?php foreach ($modules as $module => $permissions): ?>
                <div class="permission-picker__group">
                    <div class="permission-picker__header">
                        <h3 class="permission-picker__module"><?= e(ucfirst(str_replace('_', ' ', (string) $module))) ?></h3>
                        <button type="button" class="link-button" data-toggle-group="<?= e((string) $module) ?>">Toggle all</button>
                    </div>
                    <div class="permission-picker__items" data-group="<?= e((string) $module) ?>">
                        <?php foreach ($permissions as $permission): ?>
                            <label class="field__check">
                                <input type="checkbox" name="permissions[]" value="<?= e((string) $permission['permission_key']) ?>">
                                <span>
                                    <?= e((string) $permission['permission_name']) ?>
                                    <span class="permission-picker__key table__mono"><?= e((string) $permission['permission_key']) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </fieldset>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'role-form', 'title' => 'Create a role', 'size' => 'lg', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="role-form">Create role</button>',
    ]) ?>
<?php endif; ?>
