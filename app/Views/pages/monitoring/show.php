<?php
/**
 * One monitoring record.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $record
 */
$this->layout('layouts/app');

$open      = (string) $record['status'] === 'inside';
$isVisitor = (int) ($record['is_visitor'] ?? 0) === 1;

$this->start('breadcrumbs');
?>
<a href="<?= e(route('monitoring.history')) ?>">Access history</a>
<span aria-hidden="true">/</span>
<span><?= e((string) $record['transaction_reference']) ?></span>
<?php
$this->stop();

$this->start('page_actions');
?>
<?php if ($open && can('monitoring.force_close')): ?>
    <button type="button" class="button button--warning" data-modal-open="force-close">
        <i class="fa-solid fa-door-closed" aria-hidden="true"></i> Close this visit
    </button>
<?php endif; ?>
<?php if (can('monitoring.annotate')): ?>
    <button type="button" class="button button--ghost" data-modal-open="annotate">
        <i class="fa-solid fa-note-sticky" aria-hidden="true"></i> Add a note
    </button>
<?php endif; ?>
<button type="button" class="button button--ghost" data-print>
    <i class="fa-solid fa-print" aria-hidden="true"></i> Print
</button>
<?php
$this->stop();
?>

<div class="detail-grid">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-car" aria-hidden="true"></i> Movement</h2>
            <?= $this->component('badge', ['value' => (string) $record['status']]) ?>
        </header>
        <div class="card__body">
            <dl class="definition-list definition-list--two">
                <div class="definition-list__row">
                    <dt>Reference</dt><dd class="table__mono"><?= e((string) $record['transaction_reference']) ?></dd>
                </div>
                <div class="definition-list__row">
                    <dt>Plate</dt><dd><strong><?= e((string) $record['plate_number']) ?></strong></dd>
                </div>
                <div class="definition-list__row">
                    <dt>Vehicle type</dt><dd><?= e((string) ($record['vehicle_type'] ?? '—')) ?></dd>
                </div>
                <div class="definition-list__row">
                    <dt><?= $isVisitor ? 'Visitor' : 'Owner' ?></dt>
                    <dd><?= e((string) ($isVisitor ? ($record['visitor_name'] ?? '—') : ($record['owner_name'] ?? '—'))) ?></dd>
                </div>
                <div class="definition-list__row">
                    <dt>Driver</dt><dd><?= e((string) ($record['driver_name'] ?? 'Not recorded')) ?></dd>
                </div>
                <div class="definition-list__row">
                    <dt>Credential</dt>
                    <dd class="table__mono">
                        <?= e((string) ($record['tag_code'] ?? $record['card_code'] ?? $record['scanned_uid'] ?? '—')) ?>
                    </dd>
                </div>
                <?php if ($isVisitor): ?>
                    <div class="definition-list__row">
                        <dt>Pass</dt><dd class="table__mono"><?= e((string) ($record['pass_reference'] ?? '—')) ?></dd>
                    </div>
                    <div class="definition-list__row">
                        <dt>Purpose</dt><dd><?= e((string) ($record['visit_purpose'] ?? '—')) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-clock" aria-hidden="true"></i> Timeline</h2>
        </header>
        <div class="card__body card__body--flush">
            <ul class="timeline timeline--padded">
                <li class="timeline__item">
                    <span class="timeline__marker timeline__marker--in" aria-hidden="true"></span>
                    <span class="timeline__body">
                        <span class="timeline__text">
                            Entered at <?= e((string) $record['entry_time']) ?>
                        </span>
                        <span class="timeline__meta">
                            <?= e((string) ($record['entry_device_name'] ?? 'Unknown station')) ?>
                            <span aria-hidden="true">·</span>
                            <?= e((string) ($record['entry_verification'] ?? 'rfid')) ?>
                            <?php if (($record['entry_operator_name'] ?? '') !== ''): ?>
                                <span aria-hidden="true">·</span> operator <?= e((string) $record['entry_operator_name']) ?>
                            <?php endif; ?>
                        </span>
                    </span>
                </li>

                <?php if (($record['exit_time'] ?? null) !== null): ?>
                    <li class="timeline__item">
                        <span class="timeline__marker timeline__marker--out" aria-hidden="true"></span>
                        <span class="timeline__body">
                            <span class="timeline__text">Exited at <?= e((string) $record['exit_time']) ?></span>
                            <span class="timeline__meta">
                                <?= e((string) ($record['exit_device_name'] ?? 'Unknown station')) ?>
                                <span aria-hidden="true">·</span>
                                <?= e((string) ($record['exit_verification'] ?? '—')) ?>
                                <?php if (($record['exit_operator_name'] ?? '') !== ''): ?>
                                    <span aria-hidden="true">·</span> operator <?= e((string) $record['exit_operator_name']) ?>
                                <?php endif; ?>
                            </span>
                        </span>
                    </li>
                <?php else: ?>
                    <li class="timeline__item timeline__item--pending">
                        <span class="timeline__marker" aria-hidden="true"></span>
                        <span class="timeline__body">
                            <span class="timeline__text">Still inside</span>
                            <span class="timeline__meta" data-elapsed-since="<?= e((string) $record['entry_time']) ?>"></span>
                        </span>
                    </li>
                <?php endif; ?>
            </ul>

            <?php if (($record['duration_seconds'] ?? null) !== null): ?>
                <p class="card__note card__note--padded">
                    Total stay: <strong data-duration="<?= e((string) $record['duration_seconds']) ?>"></strong>
                </p>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php if (($record['remarks'] ?? '') !== '' || ($record['annotation'] ?? '') !== ''): ?>
    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-note-sticky" aria-hidden="true"></i> Notes</h2>
        </header>
        <div class="card__body">
            <?php if (($record['remarks'] ?? '') !== ''): ?>
                <p class="note-block"><span class="note-block__label">Recorded with the scan</span><?= e((string) $record['remarks']) ?></p>
            <?php endif; ?>
            <?php if (($record['annotation'] ?? '') !== ''): ?>
                <p class="note-block"><span class="note-block__label">Added afterwards</span><?= e((string) $record['annotation']) ?></p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($open && can('monitoring.force_close')): ?>
    <?php ob_start(); ?>
    <form data-ajax-form
          data-endpoint="<?= e(url('/api/v1/monitoring/' . (string) $record['access_log_id'] . '/force-close')) ?>"
          data-method="POST" data-success="The visit was closed." data-reload-on-success="true">
        <p class="form-note">
            Use this when a vehicle left without its exit being scanned. The entry record is not altered —
            an exit is added, marked as closed by you, with the reason you give below. Until this is done the
            vehicle counts as inside and cannot enter again.
        </p>
        <div class="field-grid">
            <div class="field field--full">
                <label class="field__label" for="close-reason">Reason<span class="field__required">*</span></label>
                <textarea class="field__control" id="close-reason" name="reason" rows="2" required
                          placeholder="e.g. Vehicle left via the service gate; confirmed with the duty guard."></textarea>
            </div>
            <div class="field field--half">
                <label class="field__label" for="close-time">Time it actually left</label>
                <input class="field__control" type="datetime-local" id="close-time" name="exit_time">
                <p class="field__help">Leave empty to record it as now.</p>
            </div>
        </div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'force-close', 'title' => 'Close this visit', 'size' => 'md', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--warning" data-modal-submit="force-close">Close visit</button>',
    ]) ?>
<?php endif; ?>

<?php if (can('monitoring.annotate')): ?>
    <?php ob_start(); ?>
    <form data-ajax-form
          data-endpoint="<?= e(url('/api/v1/monitoring/' . (string) $record['access_log_id'] . '/annotate')) ?>"
          data-method="POST" data-success="The note was added." data-reload-on-success="true">
        <p class="form-note">
            The note is added alongside the record; nothing already stored is changed.
        </p>
        <div class="field-grid">
            <div class="field field--full">
                <label class="field__label" for="annotation">Note<span class="field__required">*</span></label>
                <textarea class="field__control" id="annotation" name="annotation" rows="3" required></textarea>
            </div>
        </div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'annotate', 'title' => 'Add a note to this record', 'size' => 'md', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="annotate">Add note</button>',
    ]) ?>
<?php endif; ?>
