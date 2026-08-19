<?php
/**
 * One role's permission matrix and its members.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $matrix
 * @var list<array<string,mixed>> $members
 * @var bool $canAssign
 */
$this->layout('layouts/app');

$role    = (array) ($matrix['role'] ?? []);
$modules = (array) ($matrix['modules'] ?? []);
$granted = (array) ($matrix['granted'] ?? []);
$system  = (int) ($role['is_system'] ?? 0) === 1;
$unrestricted = (bool) ($matrix['unrestricted'] ?? false);

$this->start('breadcrumbs');
?>
<a href="<?= e(route('roles.index')) ?>">Roles</a>
<span aria-hidden="true">/</span>
<span><?= e((string) ($role['role_name'] ?? '')) ?></span>
<?php
$this->stop();

$this->start('page_subtitle');
echo e((string) ($role['description'] ?? 'No description.'));
$this->stop();

$this->start('page_actions');
?>
<?php if ($canAssign && !$unrestricted): ?>
    <button type="button" class="button button--primary" data-save-permissions="<?= e((string) $role['role_id']) ?>">
        <i class="fa-solid fa-check" aria-hidden="true"></i> Save permissions
    </button>
<?php endif; ?>
<a class="button button--ghost" href="<?= e(route('roles.index')) ?>">
    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> All roles
</a>
<?php
$this->stop();
?>

<?php if ($unrestricted): ?>
    <div class="alert alert--warning" role="note">
        <i class="fa-solid fa-star" aria-hidden="true"></i>
        <span>
            This role holds unrestricted access. Every capability applies by definition, so the
            individual boxes below do not constrain it.
        </span>
    </div>
<?php endif; ?>

<?php if ($system): ?>
    <div class="alert alert--info" role="note">
        <i class="fa-solid fa-lock" aria-hidden="true"></i>
        <span>
            This is a system role. Its identifier and authority level are fixed because code and seed
            data refer to them; its permissions can still be changed.
        </span>
    </div>
<?php endif; ?>

<div class="detail-grid detail-grid--wide">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-key" aria-hidden="true"></i> Permissions</h2>
            <span class="card__note"><?= e((string) count($granted)) ?> granted</span>
        </header>
        <div class="card__body">
            <fieldset class="permission-picker" data-permission-matrix
                      <?= $canAssign && !$unrestricted ? '' : 'disabled' ?>>
                <legend class="visually-hidden">Permissions for this role</legend>
                <?php foreach ($modules as $module => $permissions): ?>
                    <div class="permission-picker__group">
                        <div class="permission-picker__header">
                            <h3 class="permission-picker__module"><?= e(ucfirst(str_replace('_', ' ', (string) $module))) ?></h3>
                            <?php if ($canAssign): ?>
                                <button type="button" class="link-button" data-toggle-group="<?= e((string) $module) ?>">Toggle all</button>
                            <?php endif; ?>
                        </div>
                        <div class="permission-picker__items" data-group="<?= e((string) $module) ?>">
                            <?php foreach ($permissions as $permission): ?>
                                <?php $key = (string) $permission['permission_key']; ?>
                                <label class="field__check<?= (int) ($permission['is_dangerous'] ?? 0) === 1 ? ' field__check--dangerous' : '' ?>">
                                    <input type="checkbox" name="permissions[]" value="<?= e($key) ?>"
                                           <?= $unrestricted || in_array($key, $granted, true) ? 'checked' : '' ?>
                                           <?= $canAssign && !$unrestricted ? '' : 'disabled' ?>>
                                    <span>
                                        <?= e((string) $permission['permission_name']) ?>
                                        <?php if ((int) ($permission['is_dangerous'] ?? 0) === 1): ?>
                                            <span class="badge badge--danger">Sensitive</span>
                                        <?php endif; ?>
                                        <span class="permission-picker__key table__mono"><?= e($key) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </fieldset>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-users" aria-hidden="true"></i> Members</h2>
            <span class="card__note"><?= e((string) count($members)) ?></span>
        </header>
        <div class="card__body card__body--flush">
            <?php if ($members === []): ?>
                <?= $this->component('empty-state', [
                    'message' => 'Nobody holds this role.',
                    'icon'    => 'fa-users',
                    'hint'    => 'A role with no members can be removed safely.',
                ]) ?>
            <?php else: ?>
                <ul class="record-list">
                    <?php foreach ($members as $member): ?>
                        <li class="record-list__item">
                            <a class="record-list__link" href="<?= e(url('/users/' . (string) $member['user_id'])) ?>">
                                <span class="record-list__title"><?= e((string) $member['full_name']) ?></span>
                                <span class="record-list__meta table__mono"><?= e((string) $member['username']) ?></span>
                            </a>
                            <?= $this->component('badge', ['value' => (string) $member['status']]) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
</div>
