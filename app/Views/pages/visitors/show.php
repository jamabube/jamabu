<?php
/**
 * One visitor and every visit they have made.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $visitor
 * @var list<array<string,mixed>> $history
 */
$this->layout('layouts/app');

$barred = (int) ($visitor['is_blacklisted'] ?? 0) === 1;

$this->start('breadcrumbs');
?>
<a href="<?= e(route('visitors.index')) ?>">Visitors</a>
<span aria-hidden="true">/</span>
<span><?= e((string) $visitor['full_name']) ?></span>
<?php
$this->stop();

$this->start('page_actions');
?>
<?php if (can('visitors.blacklist')): ?>
    <button type="button" class="button <?= $barred ? 'button--ghost' : 'button--danger' ?>"
            data-modal-open="blacklist-form">
        <i class="fa-solid <?= $barred ? 'fa-unlock' : 'fa-ban' ?>" aria-hidden="true"></i>
        <?= $barred ? 'Lift the bar' : 'Bar from entry' ?>
    </button>
<?php endif; ?>
<?php
$this->stop();
?>

<?php if ($barred): ?>
    <div class="alert alert--danger" role="alert">
        <i class="fa-solid fa-ban" aria-hidden="true"></i>
        <div>
            <strong>This visitor is barred from entry.</strong>
            <p><?= e((string) ($visitor['blacklist_reason'] ?? 'No reason recorded.')) ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="detail-grid">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-user" aria-hidden="true"></i> Visitor</h2>
            <?= $this->component('badge', ['value' => (string) $visitor['status']]) ?>
        </header>
        <div class="card__body">
            <dl class="definition-list definition-list--two">
                <div class="definition-list__row"><dt>Name</dt><dd><strong><?= e((string) $visitor['full_name']) ?></strong></dd></div>
                <div class="definition-list__row"><dt>Code</dt><dd class="table__mono"><?= e((string) $visitor['visitor_code']) ?></dd></div>
                <div class="definition-list__row"><dt>Type</dt><dd><?= e((string) ($visitor['visitor_type'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Company</dt><dd><?= e((string) ($visitor['company'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Government ID</dt><dd class="table__mono"><?= e((string) ($visitor['government_id'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Contact</dt><dd><?= e((string) ($visitor['contact_number'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Email</dt><dd><?= e((string) ($visitor['email'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Address</dt><dd><?= e((string) ($visitor['address'] ?? '—')) ?></dd></div>
            </dl>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Visit history</h2>
            <span class="card__note"><?= e((string) count($history)) ?> visit(s)</span>
        </header>
        <div class="card__body card__body--flush">
            <?php if ($history === []): ?>
                <?= $this->component('empty-state', ['message' => 'This person has not visited yet.', 'icon' => 'fa-ticket']) ?>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">Pass</th>
                                <th scope="col">Purpose</th>
                                <th scope="col">Issued</th>
                                <th scope="col">Entered</th>
                                <th scope="col">Exited</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $visit): ?>
                                <tr>
                                    <td class="table__mono"><?= e((string) $visit['pass_reference']) ?></td>
                                    <td><?= e((string) $visit['purpose']) ?></td>
                                    <td><?= e((string) $visit['issued_at']) ?></td>
                                    <td><?= e((string) ($visit['entry_time'] ?? '—')) ?></td>
                                    <td><?= e((string) ($visit['exit_time'] ?? '—')) ?></td>
                                    <td><?= $this->component('badge', ['value' => (string) $visit['status']]) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php if (can('visitors.blacklist')): ?>
    <?php ob_start(); ?>
    <form data-ajax-form
          data-endpoint="<?= e(url('/api/v1/visitors/' . (string) $visitor['visitor_id'] . '/blacklist')) ?>"
          data-method="POST" data-success="The visitor record was updated." data-reload-on-success="true">
        <input type="hidden" name="blacklisted" value="<?= $barred ? '0' : '1' ?>">
        <?php if ($barred): ?>
            <p class="form-note">This lifts the bar; the visitor will be able to receive passes again.</p>
        <?php else: ?>
            <p class="form-note">
                A barred visitor cannot be issued a pass, and any attempt to do so is refused with the
                reason you give here. The reason is shown to whoever tries.
            </p>
            <div class="field-grid">
                <div class="field field--full">
                    <label class="field__label" for="bar-reason">Reason<span class="field__required">*</span></label>
                    <textarea class="field__control" id="bar-reason" name="reason" rows="3" required></textarea>
                </div>
            </div>
        <?php endif; ?>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'blacklist-form',
        'title' => $barred ? 'Lift the bar on this visitor' : 'Bar this visitor from entry',
        'size' => 'md', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button ' . ($barred ? 'button--primary' : 'button--danger')
            . '" data-modal-submit="blacklist-form">' . ($barred ? 'Lift the bar' : 'Bar visitor') . '</button>',
    ]) ?>
<?php endif; ?>
