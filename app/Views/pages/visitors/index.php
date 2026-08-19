<?php
/**
 * Visitors and their passes.
 *
 * Two tabs, because they are two different things: the person, who is the same
 * on every visit, and the pass, which is one visit with one card and one
 * validity window.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $summary
 * @var list<array<string,mixed>> $visitorTypes
 * @var list<array<string,mixed>> $cards
 * @var list<array<string,mixed>> $inside
 * @var array<string,bool> $can
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'Temporary passes for people arriving without a registered vehicle.';
$this->stop();

$this->start('page_actions');
?>
<?php if ($can['issue']): ?>
    <button type="button" class="button button--primary" data-modal-open="pass-form">
        <i class="fa-solid fa-ticket" aria-hidden="true"></i> Issue a pass
    </button>
<?php endif; ?>
<?php if ($can['create']): ?>
    <button type="button" class="button button--ghost" data-modal-open="visitor-form" data-form-mode="create">
        <i class="fa-solid fa-user-plus" aria-hidden="true"></i> Register a visitor
    </button>
<?php endif; ?>
<?php
$this->stop();

?>

<section class="stat-grid stat-grid--compact">
    <?= $this->component('stat-card', ['label' => 'Inside now', 'value' => (string) count($inside), 'icon' => 'fa-user-clock', 'tone' => 'success']) ?>
    <?= $this->component('stat-card', ['label' => 'Passes issued today', 'value' => (string) ($summary['issued_today'] ?? 0), 'icon' => 'fa-ticket', 'tone' => 'accent']) ?>
    <?= $this->component('stat-card', ['label' => 'Registered visitors', 'value' => (string) ($summary['registered'] ?? 0), 'icon' => 'fa-address-book', 'tone' => 'neutral']) ?>
    <?= $this->component('stat-card', ['label' => 'Cards available', 'value' => (string) count($cards), 'icon' => 'fa-credit-card', 'tone' => count($cards) === 0 ? 'danger' : 'neutral', 'caption' => count($cards) === 0 ? 'none left to hand out' : null]) ?>
</section>

<div class="tabs" data-tabs>
    <div class="tabs__list" role="tablist">
        <button type="button" class="tabs__tab is-active" role="tab" data-tab="passes" aria-selected="true">Passes</button>
        <button type="button" class="tabs__tab" role="tab" data-tab="people" aria-selected="false">People</button>
        <button type="button" class="tabs__tab" role="tab" data-tab="inside" aria-selected="false">
            Inside now <span class="tabs__count"><?= e((string) count($inside)) ?></span>
        </button>
    </div>

    <div class="tabs__panel is-active" data-tab-panel="passes" role="tabpanel">
        <?php
        ob_start();
        ?>
        <label class="visually-hidden" for="p-status">Status</label>
        <select id="p-status" class="field__control field__control--sm" data-filter="status">
            <option value="">Any status</option>
            <option value="issued">Issued, not yet arrived</option>
            <option value="inside">Inside</option>
            <option value="completed">Completed</option>
            <option value="expired">Expired</option>
            <option value="revoked">Revoked</option>
        </select>
        <label class="field__check field__check--inline">
            <input type="checkbox" data-filter="overdue" value="1">
            <span>Overdue only</span>
        </label>
        <label class="visually-hidden" for="p-from">From</label>
        <input type="date" id="p-from" class="field__control field__control--sm" data-filter="date_from">
        <label class="visually-hidden" for="p-to">To</label>
        <input type="date" id="p-to" class="field__control field__control--sm" data-filter="date_to">
        <button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
        <?php
        $passFilters = (string) ob_get_clean();
        ?>

        <?= $this->component('data-table', [
            'id'           => 'passes-table',
            'endpoint'     => route('api.visitors.passes'),
            'sort'         => 'issued_at',
            'emptyMessage' => 'No passes match these filters.',
            'filterControls' => $passFilters,
            'columns'      => [
                ['key' => 'pass_reference', 'label' => 'Pass', 'sortable' => true, 'class' => 'table__mono'],
                ['key' => 'visitor_name',   'label' => 'Visitor', 'format' => 'strong'],
                ['key' => 'purpose',        'label' => 'Purpose'],
                ['key' => 'destination',    'label' => 'Destination', 'empty' => '—'],
                ['key' => 'card_code',      'label' => 'Card', 'class' => 'table__mono', 'empty' => 'No card'],
                ['key' => 'issued_at',      'label' => 'Issued', 'sortable' => true, 'format' => 'datetime'],
                ['key' => 'valid_until',    'label' => 'Valid until', 'sortable' => true, 'format' => 'datetime'],
                ['key' => 'status',         'label' => 'Status', 'sortable' => true, 'format' => 'badge'],
            ],
        ]) ?>
    </div>

    <div class="tabs__panel" data-tab-panel="people" role="tabpanel" hidden>
        <?php
        ob_start();
        ?>
        <label class="visually-hidden" for="vi-type">Type</label>
        <select id="vi-type" class="field__control field__control--sm" data-filter="visitor_type_id">
            <option value="">Any visitor type</option>
            <?php foreach ($visitorTypes as $type): ?>
                <option value="<?= e((string) $type['visitor_type_id']) ?>"><?= e((string) $type['type_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="field__check field__check--inline">
            <input type="checkbox" data-filter="blacklisted" value="1">
            <span>Barred only</span>
        </label>
        <button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
        <?php
        $peopleFilters = (string) ob_get_clean();
        ?>

        <?= $this->component('data-table', [
            'id'           => 'visitors-table',
            'endpoint'     => route('api.visitors'),
            'sort'         => 'created_at',
            'rowLink'      => url('/visitors/{visitor_id}'),
            'emptyMessage' => 'No visitors match these filters.',
            'filterControls' => $peopleFilters,
            'columns'      => [
                ['key' => 'visitor_code',   'label' => 'Code', 'sortable' => true, 'class' => 'table__mono'],
                ['key' => 'full_name',      'label' => 'Name', 'sortable' => true, 'format' => 'strong'],
                ['key' => 'visitor_type',   'label' => 'Type', 'empty' => '—'],
                ['key' => 'company',        'label' => 'Company', 'empty' => '—'],
                ['key' => 'contact_number', 'label' => 'Contact', 'empty' => '—'],
                ['key' => 'visit_count',    'label' => 'Visits', 'format' => 'number'],
                ['key' => 'is_blacklisted', 'label' => 'Barred', 'format' => 'boolean'],
                ['key' => 'status',         'label' => 'Status', 'format' => 'badge'],
            ],
        ]) ?>
    </div>

    <div class="tabs__panel" data-tab-panel="inside" role="tabpanel" hidden>
        <section class="card">
            <div class="card__body card__body--flush">
                <?php if ($inside === []): ?>
                    <?= $this->component('empty-state', [
                        'message' => 'No visitors are inside.',
                        'icon'    => 'fa-user-clock',
                    ]) ?>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th scope="col">Pass</th>
                                    <th scope="col">Visitor</th>
                                    <th scope="col">Purpose</th>
                                    <th scope="col">Card</th>
                                    <th scope="col">Entered</th>
                                    <th scope="col">Valid until</th>
                                    <th scope="col" class="table__actions">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inside as $pass): ?>
                                    <?php $overdue = strtotime((string) $pass['valid_until']) < time(); ?>
                                    <tr class="<?= $overdue ? 'table__row--warning' : '' ?>">
                                        <td class="table__mono"><?= e((string) $pass['pass_reference']) ?></td>
                                        <td><strong><?= e((string) $pass['visitor_name']) ?></strong></td>
                                        <td><?= e((string) $pass['purpose']) ?></td>
                                        <td class="table__mono"><?= e((string) ($pass['card_code'] ?? '—')) ?></td>
                                        <td><time data-relative-time="<?= e((string) $pass['entry_time']) ?>"><?= e((string) $pass['entry_time']) ?></time></td>
                                        <td>
                                            <?= e((string) $pass['valid_until']) ?>
                                            <?php if ($overdue): ?><span class="badge badge--warning">Overdue</span><?php endif; ?>
                                        </td>
                                        <td class="table__actions">
                                            <?php if ($can['revoke']): ?>
                                                <button type="button" class="button button--sm button--ghost"
                                                        data-revoke-pass="<?= e((string) $pass['visitor_log_id']) ?>">Revoke</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?php if ($can['issue']): ?>
    <?php ob_start(); ?>
    <form data-ajax-form data-endpoint="<?= e(route('api.visitors.passes.issue')) ?>"
          data-method="POST" data-success="The pass was issued." data-reload-on-success="true">
        <div class="field-grid">
            <div class="field field--full">
                <label class="field__label" for="pass-visitor">Visitor<span class="field__required">*</span></label>
                <select class="field__control" id="pass-visitor" name="visitor_id" required data-searchable
                        data-refresh-from="<?= e(route('api.visitors.select')) ?>"
                        data-option-value="visitor_id" data-option-label="full_name">
                    <option value="">Select a registered visitor</option>
                </select>
                <p class="field__help">Not here yet? Register the person first — one record per person, reused on every visit.</p>
            </div>
            <div class="field field--half">
                <label class="field__label" for="pass-card">Visitor card</label>
                <select class="field__control" id="pass-card" name="rfid_card_id"
                        data-refresh-from="<?= e(route('api.visitors.cards.available')) ?>"
                        data-option-value="rfid_card_id" data-option-label="card_code">
                    <option value="">No card</option>
                    <?php foreach ($cards as $card): ?>
                        <option value="<?= e((string) $card['rfid_card_id']) ?>"><?= e((string) $card['card_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field--half">
                <label class="field__label" for="pass-hours">Valid for (hours)</label>
                <input class="field__control" type="number" id="pass-hours" name="hours" min="1" max="168"
                       placeholder="Default for this visitor type">
            </div>
            <div class="field field--full">
                <label class="field__label" for="pass-purpose">Purpose of visit<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="pass-purpose" name="purpose" required maxlength="255">
            </div>
            <div class="field field--half">
                <label class="field__label" for="pass-destination">Destination</label>
                <input class="field__control" type="text" id="pass-destination" name="destination" maxlength="120">
            </div>
            <div class="field field--half">
                <label class="field__label" for="pass-authoriser">Authorised by</label>
                <select class="field__control" id="pass-authoriser" name="authorized_by" data-searchable
                        data-refresh-from="<?= e(route('api.users')) ?>"
                        data-option-value="user_id" data-option-label="full_name">
                    <option value="">Select the authorising officer</option>
                </select>
            </div>
            <div class="field field--half">
                <label class="field__label" for="pass-plate">Vehicle plate</label>
                <input class="field__control" type="text" id="pass-plate" name="vehicle_plate" maxlength="20" data-uppercase>
            </div>
            <div class="field field--half">
                <label class="field__label" for="pass-vehicle">Vehicle description</label>
                <input class="field__control" type="text" id="pass-vehicle" name="vehicle_description" maxlength="120">
            </div>
            <div class="field field--half">
                <label class="field__label" for="pass-companions">Companions</label>
                <input class="field__control" type="number" id="pass-companions" name="companions" min="0" max="60" value="0">
            </div>
            <div class="field field--full">
                <label class="field__label" for="pass-remarks">Remarks</label>
                <textarea class="field__control" id="pass-remarks" name="remarks" rows="2"></textarea>
            </div>
        </div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'pass-form', 'title' => 'Issue a visitor pass', 'size' => 'lg', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="pass-form">Issue pass</button>',
    ]) ?>
<?php endif; ?>

<?php if ($can['create'] || $can['update']): ?>
    <?php ob_start(); ?>
    <form data-ajax-form data-endpoint="<?= e(route('api.visitors.store')) ?>"
          data-update-endpoint="<?= e(url('/api/v1/visitors/{id}')) ?>"
          data-method="POST" data-success="The visitor record was saved." data-reload-on-success="true">
        <input type="hidden" name="id" data-record-id>
        <p class="form-note">
            Registering somebody who has been here before updates their existing record rather than
            creating a second one — the government identification number is what links the two.
        </p>
        <div class="field-grid">
            <div class="field field--third">
                <label class="field__label" for="vis-first">First name<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="vis-first" name="first_name" required maxlength="60">
            </div>
            <div class="field field--third">
                <label class="field__label" for="vis-middle">Middle name</label>
                <input class="field__control" type="text" id="vis-middle" name="middle_name" maxlength="60">
            </div>
            <div class="field field--third">
                <label class="field__label" for="vis-last">Last name<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="vis-last" name="last_name" required maxlength="60">
            </div>
            <div class="field field--half">
                <label class="field__label" for="vis-type">Visitor type</label>
                <select class="field__control" id="vis-type" name="visitor_type_id">
                    <option value="">Unclassified</option>
                    <?php foreach ($visitorTypes as $type): ?>
                        <option value="<?= e((string) $type['visitor_type_id']) ?>">
                            <?= e((string) $type['type_name']) ?> (<?= e((string) $type['default_validity_hours']) ?>h)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field--half">
                <label class="field__label" for="vis-govid">Government ID</label>
                <input class="field__control" type="text" id="vis-govid" name="government_id" maxlength="60">
            </div>
            <div class="field field--half">
                <label class="field__label" for="vis-company">Company</label>
                <input class="field__control" type="text" id="vis-company" name="company" maxlength="120">
            </div>
            <div class="field field--half">
                <label class="field__label" for="vis-contact">Contact number</label>
                <input class="field__control" type="text" id="vis-contact" name="contact_number" maxlength="30">
            </div>
            <div class="field field--full">
                <label class="field__label" for="vis-address">Address</label>
                <input class="field__control" type="text" id="vis-address" name="address" maxlength="255">
            </div>
        </div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'visitor-form', 'title' => 'Visitor', 'size' => 'lg', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="visitor-form">Save visitor</button>',
    ]) ?>
<?php endif; ?>
