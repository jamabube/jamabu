<?php
/**
 * The system health report.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $report
 * @var array<string,mixed> $environment
 */
$this->layout('layouts/app');

$checks  = (array) ($report['checks'] ?? []);
$overall = (string) ($report['status'] ?? 'unknown');

$this->start('page_subtitle');
echo 'The overall state is the worst individual check, not an average — an average would hide the one thing that is wrong.';
$this->stop();

$this->start('page_actions');
?>
<button type="button" class="button button--ghost" data-reload>
    <i class="fa-solid fa-rotate" aria-hidden="true"></i> Re-run checks
</button>
<?php
$this->stop();

$tones = ['healthy' => 'success', 'ok' => 'success', 'degraded' => 'warning', 'unhealthy' => 'danger'];
$icons = ['healthy' => 'fa-circle-check', 'ok' => 'fa-circle-check', 'degraded' => 'fa-triangle-exclamation', 'unhealthy' => 'fa-circle-xmark'];
?>

<div class="health-banner health-banner--<?= e($tones[$overall] ?? 'neutral') ?>">
    <i class="fa-solid <?= e($icons[$overall] ?? 'fa-circle-question') ?>" aria-hidden="true"></i>
    <div>
        <h2 class="health-banner__title">System is <?= e($overall) ?></h2>
        <p class="health-banner__meta">Checked <?= e((string) ($report['generated_at'] ?? now()->format('Y-m-d H:i:s'))) ?></p>
    </div>
</div>

<div class="health-grid">
    <?php foreach ($checks as $name => $check): ?>
        <?php $status = (string) ($check['status'] ?? 'unknown'); ?>
        <section class="card card--check card--check-<?= e($tones[$status] ?? 'neutral') ?>">
            <header class="card__header">
                <h2 class="card__title">
                    <i class="fa-solid <?= e($icons[$status] ?? 'fa-circle-question') ?>" aria-hidden="true"></i>
                    <?= e(ucfirst(str_replace('_', ' ', (string) $name))) ?>
                </h2>
                <?= $this->component('badge', ['value' => $status]) ?>
            </header>
            <div class="card__body">
                <p class="check__message"><?= e((string) ($check['message'] ?? '')) ?></p>
                <?php
                $details = array_diff_key((array) $check, ['status' => null, 'message' => null]);
                ?>
                <?php if ($details !== []): ?>
                    <dl class="definition-list definition-list--tight">
                        <?php foreach ($details as $key => $value): ?>
                            <div class="definition-list__row">
                                <dt><?= e(ucfirst(str_replace('_', ' ', (string) $key))) ?></dt>
                                <dd>
                                    <?php if (is_bool($value)): ?>
                                        <?= $value ? 'Yes' : 'No' ?>
                                    <?php elseif (is_scalar($value)): ?>
                                        <?= e((string) $value) ?>
                                    <?php elseif (is_array($value)): ?>
                                        <?= e(implode(', ', array_map(
                                            static fn (mixed $item): string => is_scalar($item) ? (string) $item : '…',
                                            $value
                                        ))) ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<section class="card">
    <header class="card__header">
        <h2 class="card__title"><i class="fa-solid fa-server" aria-hidden="true"></i> Environment</h2>
    </header>
    <div class="card__body">
        <dl class="definition-list definition-list--two">
            <?php foreach ($environment as $key => $value): ?>
                <div class="definition-list__row">
                    <dt><?= e(ucfirst(str_replace('_', ' ', (string) $key))) ?></dt>
                    <dd>
                        <?php if (is_bool($value)): ?>
                            <?= $value ? 'Yes' : 'No' ?>
                        <?php elseif (is_scalar($value)): ?>
                            <?= e((string) $value) ?>
                        <?php elseif (is_array($value)): ?>
                            <?= e(implode(', ', array_map(
                                static fn (mixed $item): string => is_scalar($item) ? (string) $item : '…',
                                $value
                            ))) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>
</section>
