<?php
/**
 * The permission catalogue: what exists, and which roles hold each one.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,list<array<string,mixed>>> $modules
 * @var array<string,int> $roleCounts
 * @var list<array<string,mixed>> $roles
 */
$this->layout('layouts/app');

$total = array_sum(array_map('count', $modules));

$this->start('page_subtitle');
printf('%d permissions across %d modules. This page is a reference; grants are made on a role.', $total, count($modules));
$this->stop();
?>

<div class="permission-index" data-filter-source>
    <div class="data-table__toolbar">
        <div class="data-table__search">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <label class="visually-hidden" for="perm-filter">Filter permissions</label>
            <input type="search" id="perm-filter" placeholder="Filter by key or name…"
                   data-filter-list=".permission-index" autocomplete="off">
        </div>
    </div>

    <?php foreach ($modules as $module => $permissions): ?>
        <section class="card">
            <header class="card__header">
                <h2 class="card__title">
                    <i class="fa-solid <?= e((string) ($permissions[0]['icon'] ?? 'fa-cube')) ?>" aria-hidden="true"></i>
                    <?= e((string) ($permissions[0]['module_name'] ?? ucfirst(str_replace('_', ' ', (string) $module)))) ?>
                </h2>
                <span class="card__note"><?= e((string) count($permissions)) ?> permission(s)</span>
            </header>
            <div class="card__body card__body--flush">
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">Key</th>
                                <th scope="col">Name</th>
                                <th scope="col">What it allows</th>
                                <th scope="col">Roles holding it</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($permissions as $permission): ?>
                                <?php $key = (string) $permission['permission_key']; ?>
                                <tr>
                                    <td class="table__mono"><?= e($key) ?></td>
                                    <td>
                                        <?= e((string) $permission['permission_name']) ?>
                                        <?php if ((int) ($permission['is_dangerous'] ?? 0) === 1): ?>
                                            <span class="badge badge--danger">Sensitive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e((string) ($permission['description'] ?? '—')) ?></td>
                                    <td>
                                        <?php $count = (int) ($roleCounts[$key] ?? 0); ?>
                                        <?php if ($count === 0): ?>
                                            <span class="badge badge--warning">No role</span>
                                        <?php else: ?>
                                            <?= e((string) $count) ?> of <?= e((string) count($roles)) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
</div>
