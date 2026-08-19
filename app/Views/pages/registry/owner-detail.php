<?php
/**
 * One owner and the vehicles registered to them.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $owner
 * @var list<array<string,mixed>> $vehicles
 */
$this->layout('layouts/app');

$this->start('breadcrumbs');
?>
<a href="<?= e(route('owners.index')) ?>">Vehicle owners</a>
<span aria-hidden="true">/</span>
<span><?= e((string) $owner['full_name']) ?></span>
<?php
$this->stop();
?>

<div class="detail-grid">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-user-tie" aria-hidden="true"></i> Owner</h2>
            <?= $this->component('badge', ['value' => (string) $owner['status']]) ?>
        </header>
        <div class="card__body">
            <dl class="definition-list definition-list--two">
                <div class="definition-list__row"><dt>Name</dt><dd><strong><?= e((string) $owner['full_name']) ?></strong></dd></div>
                <div class="definition-list__row"><dt>Code</dt><dd class="table__mono"><?= e((string) $owner['owner_code']) ?></dd></div>
                <div class="definition-list__row"><dt>Category</dt><dd><?= e(ucfirst((string) $owner['owner_category'])) ?></dd></div>
                <div class="definition-list__row"><dt>Department</dt><dd><?= e((string) ($owner['department_name'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Company</dt><dd><?= e((string) ($owner['company'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Contact</dt><dd><?= e((string) ($owner['contact_number'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Email</dt><dd><?= e((string) ($owner['email'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Address</dt><dd><?= e((string) ($owner['address'] ?? '—')) ?></dd></div>
            </dl>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-car" aria-hidden="true"></i> Registered vehicles</h2>
            <span class="card__note"><?= e((string) count($vehicles)) ?></span>
        </header>
        <div class="card__body card__body--flush">
            <?php if ($vehicles === []): ?>
                <?= $this->component('empty-state', [
                    'message' => 'No vehicles are registered to this owner.',
                    'icon'    => 'fa-car',
                    'hint'    => 'An owner with no vehicles can be deactivated safely.',
                ]) ?>
            <?php else: ?>
                <ul class="record-list">
                    <?php foreach ($vehicles as $vehicle): ?>
                        <li class="record-list__item">
                            <a class="record-list__link" href="<?= e(url('/vehicles/' . (string) $vehicle['vehicle_id'])) ?>">
                                <span class="record-list__title"><?= e((string) $vehicle['plate_number']) ?></span>
                                <span class="record-list__meta">
                                    <?= e(trim((string) ($vehicle['brand'] ?? '') . ' ' . (string) ($vehicle['model'] ?? '')) ?: 'Unspecified model') ?>
                                </span>
                            </a>
                            <?= $this->component('badge', ['value' => (string) $vehicle['status']]) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
</div>
