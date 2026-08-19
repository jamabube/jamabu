<?php
/**
 * One driver and the vehicles they are assigned to.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $detail
 */
$this->layout('layouts/app');

$driver   = (array) ($detail['driver'] ?? []);
$vehicles = (array) ($detail['vehicles'] ?? []);

$this->start('breadcrumbs');
?>
<a href="<?= e(route('drivers.index')) ?>">Drivers</a>
<span aria-hidden="true">/</span>
<span><?= e((string) ($driver['full_name'] ?? '')) ?></span>
<?php
$this->stop();
?>

<div class="detail-grid">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-id-card" aria-hidden="true"></i> Driver</h2>
            <?= $this->component('badge', ['value' => (string) ($driver['status'] ?? 'unknown')]) ?>
        </header>
        <div class="card__body">
            <dl class="definition-list definition-list--two">
                <div class="definition-list__row"><dt>Name</dt><dd><strong><?= e((string) ($driver['full_name'] ?? '—')) ?></strong></dd></div>
                <div class="definition-list__row"><dt>Code</dt><dd class="table__mono"><?= e((string) ($driver['driver_code'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Licence</dt><dd class="table__mono"><?= e((string) ($driver['government_id'] ?? 'Not recorded')) ?></dd></div>
                <div class="definition-list__row"><dt>Licence expiry</dt><dd><?= e((string) ($driver['licence_expiry'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Contact</dt><dd><?= e((string) ($driver['contact_number'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Email</dt><dd><?= e((string) ($driver['email'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Address</dt><dd><?= e((string) ($driver['address'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Emergency contact</dt>
                    <dd><?= e(trim((string) ($driver['emergency_contact_name'] ?? '') . ' ' . (string) ($driver['emergency_contact_number'] ?? '')) ?: '—') ?></dd>
                </div>
            </dl>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-car" aria-hidden="true"></i> Assigned vehicles</h2>
            <span class="card__note"><?= e((string) count($vehicles)) ?></span>
        </header>
        <div class="card__body card__body--flush">
            <?php if ($vehicles === []): ?>
                <?= $this->component('empty-state', [
                    'message' => 'No vehicles are assigned to this driver.',
                    'icon'    => 'fa-car',
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
