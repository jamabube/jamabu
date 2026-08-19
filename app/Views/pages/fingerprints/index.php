<?php
/**
 * Fingerprint enrolments.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $summary
 * @var list<array<string,mixed>> $devices
 * @var list<array<string,mixed>> $drivers
 * @var list<array<string,mixed>> $onDuty
 * @var int $capacity
 * @var array<string,bool> $can
 */
$this->layout('layouts/app');

$statuses = (array) ($summary['statuses'] ?? []);

$this->start('page_subtitle');
echo 'Who may sign on at a station, and in which sensor slot their enrolment lives.';
$this->stop();

$this->start('page_actions');
?>
<?php if ($can['enroll']): ?>
    <button type="button" class="button button--primary" data-modal-open="enrol-form">
        <i class="fa-solid fa-fingerprint" aria-hidden="true"></i> Enrol a finger
    </button>
<?php endif; ?>
<?php if ($can['sync']): ?>
    <button type="button" class="button button--ghost" data-modal-open="sync-form">
        <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i> Reconcile a sensor
    </button>
<?php endif; ?>
<?php
$this->stop();
?>

<div class="alert alert--info" role="note">
    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
    <span>
        The sensor holds the biometric data. This system stores only a slot number and a one-way
        checksum of what the sensor reported — nothing here can reconstruct a fingerprint.
    </span>
</div>

<section class="stat-grid stat-grid--compact">
    <?= $this->component('stat-card', ['label' => 'Active enrolments', 'value' => (string) ($statuses['active'] ?? 0), 'icon' => 'fa-fingerprint', 'tone' => 'success']) ?>
    <?= $this->component('stat-card', ['label' => 'Awaiting sync', 'value' => (string) ($statuses['pending_sync'] ?? 0), 'icon' => 'fa-arrows-rotate', 'tone' => ($statuses['pending_sync'] ?? 0) > 0 ? 'warning' : 'neutral', 'caption' => 'server and sensor disagree']) ?>
    <?= $this->component('stat-card', ['label' => 'Failures today', 'value' => (string) ($summary['failures_today'] ?? 0), 'icon' => 'fa-triangle-exclamation', 'tone' => 'warning']) ?>
    <?= $this->component('stat-card', ['label' => 'Operators on duty', 'value' => (string) count($onDuty), 'icon' => 'fa-user-shield', 'tone' => 'accent']) ?>
</section>

<?php if ($onDuty !== []): ?>
    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-user-shield" aria-hidden="true"></i> On duty right now</h2>
        </header>
        <div class="card__body card__body--flush">
            <ul class="record-list">
                <?php foreach ($onDuty as $session): ?>
                    <li class="record-list__item">
                        <span class="record-list__link">
                            <span class="record-list__title"><?= e((string) ($session['operator_name'] ?? 'Unknown')) ?></span>
                            <span class="record-list__meta">
                                <?= e((string) ($session['device_name'] ?? '—')) ?>
                                <span aria-hidden="true">·</span>
                                signed on <time data-relative-time="<?= e((string) $session['authenticated_at']) ?>"><?= e((string) $session['authenticated_at']) ?></time>
                                <span aria-hidden="true">·</span>
                                <?= e((string) $session['transaction_count']) ?> transaction(s)
                            </span>
                        </span>
                        <?php if ($can['verify']): ?>
                            <button type="button" class="button button--sm button--ghost"
                                    data-close-operator="<?= e((string) $session['device_id']) ?>">End session</button>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
<?php endif; ?>

<div class="tabs" data-tabs>
    <div class="tabs__list" role="tablist">
        <button type="button" class="tabs__tab is-active" role="tab" data-tab="enrolments" aria-selected="true">Enrolments</button>
        <button type="button" class="tabs__tab" role="tab" data-tab="verifications" aria-selected="false">Verification attempts</button>
    </div>

    <div class="tabs__panel is-active" data-tab-panel="enrolments" role="tabpanel">
        <?php
        ob_start();
        ?>
        <label class="visually-hidden" for="f-device">Sensor</label>
        <select id="f-device" class="field__control field__control--sm" data-filter="device_id">
            <option value="">Any sensor</option>
            <?php foreach ($devices as $device): ?>
                <option value="<?= e((string) $device['device_id']) ?>"><?= e((string) $device['device_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="f-status">Status</label>
        <select id="f-status" class="field__control field__control--sm" data-filter="status">
            <option value="">Any status</option>
            <option value="active">Active</option>
            <option value="pending_sync">Awaiting sync</option>
            <option value="inactive">Inactive</option>
            <option value="revoked">Revoked</option>
        </select>
        <label class="visually-hidden" for="f-holder">Holder</label>
        <select id="f-holder" class="field__control field__control--sm" data-filter="holder">
            <option value="">Anyone</option>
            <option value="user">System users</option>
            <option value="driver">Drivers</option>
        </select>
        <button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
        <?php
        $filterControls = (string) ob_get_clean();
        ?>

        <?= $this->component('data-table', [
            'id'           => 'fingerprints-table',
            'endpoint'     => route('api.fingerprints'),
            'sort'         => 'enrolled_at',
            'emptyMessage' => 'No enrolments match these filters.',
            'filterControls' => $filterControls,
            'columns'      => [
                ['key' => 'template_number',  'label' => 'Enrolment', 'sortable' => true, 'class' => 'table__mono'],
                ['key' => 'holder_name',      'label' => 'Belongs to', 'format' => 'strong'],
                ['key' => 'holder_type',      'label' => 'Kind'],
                ['key' => 'device_name',      'label' => 'Sensor'],
                ['key' => 'sensor_slot',      'label' => 'Slot', 'sortable' => true, 'format' => 'number'],
                ['key' => 'finger_label',     'label' => 'Finger', 'empty' => '—'],
                ['key' => 'verification_count', 'label' => 'Verified', 'format' => 'number'],
                ['key' => 'failure_count',    'label' => 'Failed', 'format' => 'number'],
                ['key' => 'status',           'label' => 'Status', 'sortable' => true, 'format' => 'badge'],
            ],
        ]) ?>
    </div>

    <div class="tabs__panel" data-tab-panel="verifications" role="tabpanel" hidden>
        <?= $this->component('data-table', [
            'id'           => 'verifications-table',
            'endpoint'     => route('api.fingerprints.verifications'),
            'sort'         => 'verified_at',
            'emptyMessage' => 'No verification attempts recorded.',
            'columns'      => [
                ['key' => 'verified_at',  'label' => 'When', 'sortable' => true, 'format' => 'datetime'],
                ['key' => 'device_name',  'label' => 'Station'],
                ['key' => 'operator_name', 'label' => 'Matched', 'empty' => 'No match'],
                ['key' => 'purpose',      'label' => 'Purpose'],
                ['key' => 'match_score',  'label' => 'Confidence', 'format' => 'number'],
                ['key' => 'successful',   'label' => 'Result', 'format' => 'boolean'],
            ],
        ]) ?>
    </div>
</div>

<?php if ($can['enroll']): ?>
    <?php ob_start(); ?>
    <form data-ajax-form data-endpoint="<?= e(route('api.fingerprints.store')) ?>"
          data-method="POST" data-success="The enrolment was recorded." data-reload-on-success="true">
        <p class="form-note">
            Enrol the finger on the sensor itself first, note the slot it used, then record it here.
            The two must agree: the reconcile tool reports any that do not.
        </p>
        <div class="field-grid">
            <div class="field field--half">
                <label class="field__label" for="fp-device">Sensor<span class="field__required">*</span></label>
                <select class="field__control" id="fp-device" name="device_id" required
                        data-slot-source="<?= e(route('api.fingerprints.next-slot')) ?>">
                    <option value="">Select a sensor</option>
                    <?php foreach ($devices as $device): ?>
                        <option value="<?= e((string) $device['device_id']) ?>"><?= e((string) $device['device_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field--half">
                <label class="field__label" for="fp-slot">Sensor slot</label>
                <input class="field__control" type="number" id="fp-slot" name="sensor_slot" min="1" max="<?= e((string) $capacity) ?>"
                       data-slot-target placeholder="Next free slot is chosen automatically">
            </div>
            <div class="field field--half">
                <label class="field__label" for="fp-user">System user</label>
                <select class="field__control" id="fp-user" name="assigned_user_id" data-searchable
                        data-refresh-from="<?= e(route('api.users')) ?>"
                        data-option-value="user_id" data-option-label="full_name"
                        data-exclusive-with="fp-driver">
                    <option value="">Not a system user</option>
                </select>
            </div>
            <div class="field field--half">
                <label class="field__label" for="fp-driver">Driver</label>
                <select class="field__control" id="fp-driver" name="assigned_driver_id" data-searchable
                        data-exclusive-with="fp-user">
                    <option value="">Not a driver</option>
                    <?php foreach ($drivers as $driver): ?>
                        <option value="<?= e((string) $driver['driver_id']) ?>"><?= e((string) $driver['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="field__help">An enrolment belongs to one person: a system user or a driver, not both.</p>
            </div>
            <div class="field field--half">
                <label class="field__label" for="fp-finger">Finger</label>
                <select class="field__control" id="fp-finger" name="finger_label">
                    <option value="">Not recorded</option>
                    <?php foreach (['right_thumb', 'right_index', 'right_middle', 'left_thumb', 'left_index', 'left_middle'] as $finger): ?>
                        <option value="<?= e($finger) ?>"><?= e(ucwords(str_replace('_', ' ', $finger))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field--half">
                <label class="field__label" for="fp-quality">Enrolment quality</label>
                <input class="field__control" type="number" id="fp-quality" name="quality_score" min="0" max="100"
                       placeholder="As reported by the sensor">
            </div>
            <div class="field field--full">
                <label class="field__label" for="fp-checksum">Sensor checksum</label>
                <input class="field__control" type="text" id="fp-checksum" name="checksum" maxlength="128"
                       autocomplete="off" spellcheck="false">
                <p class="field__help">Optional. Lets the system notice later that a slot has been reprogrammed.</p>
            </div>
            <div class="field field--full">
                <label class="field__label" for="fp-remarks">Remarks</label>
                <input class="field__control" type="text" id="fp-remarks" name="remarks" maxlength="255">
            </div>
        </div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'enrol-form', 'title' => 'Record a fingerprint enrolment', 'size' => 'lg', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="enrol-form">Record enrolment</button>',
    ]) ?>
<?php endif; ?>

<?php if ($can['sync']): ?>
    <?php ob_start(); ?>
    <form data-ajax-form data-endpoint="<?= e(route('api.fingerprints.synchronise')) ?>"
          data-method="POST" data-success="Reconciliation complete." data-result-target="#sync-result">
        <p class="form-note">
            Read the occupied slots from the sensor and list them here. Discrepancies are reported,
            never resolved automatically — deleting an enrolment because a sensor was briefly
            unreachable would lock somebody out of their own shift.
        </p>
        <div class="field-grid">
            <div class="field field--half">
                <label class="field__label" for="sync-device">Sensor<span class="field__required">*</span></label>
                <select class="field__control" id="sync-device" name="device_id" required>
                    <option value="">Select a sensor</option>
                    <?php foreach ($devices as $device): ?>
                        <option value="<?= e((string) $device['device_id']) ?>"><?= e((string) $device['device_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field--full">
                <label class="field__label" for="sync-slots">Occupied slots<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="sync-slots" name="slots" required
                       data-list-field placeholder="e.g. 1, 2, 5, 9">
                <p class="field__help">Comma-separated slot numbers as the sensor reports them.</p>
            </div>
        </div>
        <div id="sync-result" class="lookup__result"></div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'sync-form', 'title' => 'Reconcile a sensor', 'size' => 'md', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Close</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="sync-form">Reconcile</button>',
    ]) ?>
<?php endif; ?>
