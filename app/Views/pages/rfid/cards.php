<?php
/**
 * Visitor card inventory.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,int> $statuses
 * @var list<string> $cardTypes
 * @var array<string,bool> $can
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'Reusable cards handed to visitors on arrival and taken back on the way out.';
$this->stop();

$this->start('page_actions');
?>
<?php if ($can['create']): ?>
    <button type="button" class="button button--primary" data-modal-open="card-form">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> Add a card
    </button>
<?php endif; ?>
<?php
$this->stop();
?>

<section class="stat-grid stat-grid--compact">
    <?= $this->component('stat-card', ['label' => 'Available', 'value' => (string) ($statuses['available'] ?? 0), 'icon' => 'fa-box-open', 'tone' => ($statuses['available'] ?? 0) === 0 ? 'danger' : 'success']) ?>
    <?= $this->component('stat-card', ['label' => 'Issued out', 'value' => (string) ($statuses['issued'] ?? 0), 'icon' => 'fa-hand-holding', 'tone' => 'accent']) ?>
    <?= $this->component('stat-card', ['label' => 'Lost or damaged', 'value' => (string) (($statuses['lost'] ?? 0) + ($statuses['damaged'] ?? 0)), 'icon' => 'fa-triangle-exclamation', 'tone' => 'danger']) ?>
    <?= $this->component('stat-card', ['label' => 'Retired', 'value' => (string) ($statuses['retired'] ?? 0), 'icon' => 'fa-box-archive', 'tone' => 'neutral']) ?>
</section>

<?php
ob_start();
?>
<label class="visually-hidden" for="c-status">Status</label>
<select id="c-status" class="field__control field__control--sm" data-filter="status">
    <option value="">Any status</option>
    <?php foreach (['available', 'issued', 'inactive', 'lost', 'damaged', 'retired'] as $status): ?>
        <option value="<?= e($status) ?>"><?= e(ucfirst($status)) ?></option>
    <?php endforeach; ?>
</select>
<button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
<?php
$filterControls = (string) ob_get_clean();
?>

<?= $this->component('data-table', [
    'id'           => 'cards-table',
    'endpoint'     => route('api.rfid.cards'),
    'sort'         => 'created_at',
    'emptyMessage' => 'No cards match these filters.',
    'filterControls' => $filterControls,
    'columns'      => [
        ['key' => 'card_code',       'label' => 'Card', 'sortable' => true, 'class' => 'table__mono'],
        ['key' => 'card_uid',        'label' => 'UID', 'class' => 'table__mono'],
        ['key' => 'card_type',       'label' => 'Type'],
        ['key' => 'visitor_name',    'label' => 'Currently held by', 'empty' => 'In the drawer'],
        ['key' => 'pass_reference',  'label' => 'Pass', 'class' => 'table__mono', 'empty' => '—'],
        ['key' => 'issued_count',    'label' => 'Times issued', 'sortable' => true, 'format' => 'number'],
        ['key' => 'last_issued_at',  'label' => 'Last issued', 'sortable' => true, 'format' => 'datetime', 'empty' => 'Never'],
        ['key' => 'status',          'label' => 'Status', 'sortable' => true, 'format' => 'badge'],
    ],
]) ?>

<?php if ($can['create']): ?>
    <?php ob_start(); ?>
    <form data-ajax-form data-endpoint="<?= e(route('api.rfid.cards.store')) ?>"
          data-method="POST" data-success="The card was added." data-reload-on-success="true">
        <div class="field-grid">
            <div class="field field--half">
                <label class="field__label" for="card-uid">Card UID<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="card-uid" name="card_uid" required maxlength="32"
                       autocomplete="off" spellcheck="false" data-uppercase>
            </div>
            <div class="field field--half">
                <label class="field__label" for="card-code">Card number</label>
                <input class="field__control" type="text" id="card-code" name="card_code" maxlength="20"
                       placeholder="Generated automatically if left empty">
            </div>
            <div class="field field--half">
                <label class="field__label" for="card-type">Card type</label>
                <select class="field__control" id="card-type" name="card_type">
                    <?php foreach ($cardTypes as $type): ?>
                        <option value="<?= e($type) ?>"><?= e(ucwords(str_replace('_', ' ', $type))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field--full">
                <label class="field__label" for="card-remarks">Remarks</label>
                <textarea class="field__control" id="card-remarks" name="remarks" rows="2"></textarea>
            </div>
        </div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'card-form', 'title' => 'Visitor card', 'size' => 'md', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="card-form">Add card</button>',
    ]) ?>
<?php endif; ?>
