<?php
/**
 * Security events and the thresholds that raise them.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var list<string> $eventTypes
 * @var array<string,int> $severity
 * @var int $unresolved
 * @var list<array<string,mixed>> $rules
 * @var array<string,bool> $can
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'Events are acknowledged, investigated or dismissed — never deleted. What an incident needs is a register that cannot be tidied up afterwards.';
$this->stop();
?>

<section class="stat-grid stat-grid--compact">
    <?= $this->component('stat-card', ['label' => 'Critical', 'value' => (string) ($severity['critical'] ?? 0), 'icon' => 'fa-circle-exclamation', 'tone' => 'danger']) ?>
    <?= $this->component('stat-card', ['label' => 'High', 'value' => (string) ($severity['high'] ?? 0), 'icon' => 'fa-triangle-exclamation', 'tone' => 'warning']) ?>
    <?= $this->component('stat-card', ['label' => 'Medium', 'value' => (string) ($severity['medium'] ?? 0), 'icon' => 'fa-circle-info', 'tone' => 'accent']) ?>
    <?= $this->component('stat-card', ['label' => 'Unresolved', 'value' => (string) $unresolved, 'icon' => 'fa-folder-open', 'tone' => $unresolved > 0 ? 'warning' : 'success']) ?>
</section>

<div class="tabs" data-tabs>
    <div class="tabs__list" role="tablist">
        <button type="button" class="tabs__tab is-active" role="tab" data-tab="events" aria-selected="true">Events</button>
        <button type="button" class="tabs__tab" role="tab" data-tab="attempts" aria-selected="false">Sign-in attempts</button>
        <button type="button" class="tabs__tab" role="tab" data-tab="rules" aria-selected="false">Thresholds</button>
    </div>

    <div class="tabs__panel is-active" data-tab-panel="events" role="tabpanel">
        <?php
        ob_start();
        ?>
        <label class="visually-hidden" for="s-severity">Severity</label>
        <select id="s-severity" class="field__control field__control--sm" data-filter="severity">
            <option value="">Any severity</option>
            <option value="critical">Critical</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
        </select>

        <label class="visually-hidden" for="s-type">Event type</label>
        <select id="s-type" class="field__control field__control--sm" data-filter="event_type">
            <option value="">Any event type</option>
            <?php foreach ($eventTypes as $type): ?>
                <option value="<?= e((string) $type) ?>">
                    <?= e(ucfirst(str_replace('_', ' ', (string) $type))) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label class="visually-hidden" for="s-status">Status</label>
        <select id="s-status" class="field__control field__control--sm" data-filter="status">
            <option value="">Any status</option>
            <option value="new">New</option>
            <option value="acknowledged">Acknowledged</option>
            <option value="investigating">Investigating</option>
            <option value="resolved">Resolved</option>
            <option value="dismissed">Dismissed</option>
        </select>

        <label class="visually-hidden" for="s-from">From</label>
        <input type="date" id="s-from" class="field__control field__control--sm" data-filter="date_from">
        <label class="visually-hidden" for="s-to">To</label>
        <input type="date" id="s-to" class="field__control field__control--sm" data-filter="date_to">
        <button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
        <?php
        $filterControls = (string) ob_get_clean();
        ?>

        <div data-security-events data-can-acknowledge="<?= $can['acknowledge'] ? '1' : '0' ?>">
            <?= $this->component('data-table', [
                'id'           => 'security-table',
                'endpoint'     => route('api.security.events'),
                'sort'         => 'occurred_at',
                'emptyMessage' => 'No security events match these filters.',
                'filterControls' => $filterControls,
                'columns'      => [
                    ['key' => 'occurred_at',  'label' => 'When', 'sortable' => true, 'format' => 'datetime'],
                    ['key' => 'severity',     'label' => 'Severity', 'sortable' => true, 'format' => 'badge'],
                    ['key' => 'event_type',   'label' => 'Type', 'sortable' => true],
                    ['key' => 'description',  'label' => 'What happened'],
                    ['key' => 'username',     'label' => 'Account', 'empty' => '—'],
                    ['key' => 'ip_address',   'label' => 'From', 'class' => 'table__mono', 'empty' => '—'],
                    ['key' => 'action_taken', 'label' => 'System response', 'empty' => '—'],
                    ['key' => 'status',       'label' => 'Status', 'sortable' => true, 'format' => 'badge'],
                ],
            ]) ?>
        </div>
    </div>

    <div class="tabs__panel" data-tab-panel="attempts" role="tabpanel" hidden>
        <?= $this->component('data-table', [
            'id'           => 'attempts-table',
            'endpoint'     => route('api.security.login-attempts'),
            'sort'         => 'attempted_at',
            'emptyMessage' => 'No sign-in attempts recorded.',
            'columns'      => [
                ['key' => 'attempted_at', 'label' => 'When', 'sortable' => true, 'format' => 'datetime'],
                ['key' => 'username',     'label' => 'Username tried'],
                ['key' => 'ip_address',   'label' => 'From', 'class' => 'table__mono'],
                ['key' => 'successful',   'label' => 'Result', 'format' => 'boolean'],
                ['key' => 'failure_reason', 'label' => 'Reason', 'empty' => '—'],
                ['key' => 'user_agent',   'label' => 'Client'],
            ],
        ]) ?>
    </div>

    <div class="tabs__panel" data-tab-panel="rules" role="tabpanel" hidden>
        <section class="card">
            <header class="card__header">
                <h2 class="card__title"><i class="fa-solid fa-sliders" aria-hidden="true"></i> Thresholds</h2>
                <span class="card__note">These values are what the lockout, rate-limit and flood checks actually enforce.</span>
            </header>
            <div class="card__body card__body--flush">
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">Rule</th>
                                <th scope="col">Threshold</th>
                                <th scope="col">Window</th>
                                <th scope="col">Action</th>
                                <th scope="col">Severity</th>
                                <th scope="col">Enabled</th>
                                <?php if ($can['manageRules']): ?><th scope="col" class="table__actions">Edit</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rules as $rule): ?>
                                <tr>
                                    <td>
                                        <strong><?= e((string) $rule['rule_name']) ?></strong>
                                        <span class="table__sub"><?= e((string) ($rule['description'] ?? '')) ?></span>
                                    </td>
                                    <td><?= e((string) $rule['threshold_value']) ?></td>
                                    <td><?= e((string) $rule['window_seconds']) ?> s</td>
                                    <td><?= e(ucfirst((string) $rule['action'])) ?></td>
                                    <td><?= $this->component('badge', ['value' => (string) $rule['severity']]) ?></td>
                                    <td><?= (int) $rule['is_enabled'] === 1 ? 'Yes' : 'No' ?></td>
                                    <?php if ($can['manageRules']): ?>
                                        <td class="table__actions">
                                            <button type="button" class="button button--sm button--ghost"
                                                    data-edit-rule="<?= e((string) $rule['security_rule_id']) ?>"
                                                    data-rule="<?= e(\App\Core\Support\Html::js($rule)) ?>">Edit</button>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <footer class="card__footer">
                A disabled rule falls back to the value shipped in configuration, never to "no limit":
                switching a rule off must not remove a protection.
            </footer>
        </section>
    </div>
</div>

<?php if ($can['manageRules']): ?>
    <?php ob_start(); ?>
    <form data-ajax-form data-endpoint="<?= e(url('/api/v1/security/rules/{id}')) ?>"
          data-method="PUT" data-success="The threshold was updated." data-reload-on-success="true">
        <input type="hidden" name="id" data-record-id>
        <p class="form-note" data-rule-name></p>
        <div class="field-grid">
            <div class="field field--half">
                <label class="field__label" for="rule-threshold">Threshold<span class="field__required">*</span></label>
                <input class="field__control" type="number" id="rule-threshold" name="threshold_value" min="1" required>
            </div>
            <div class="field field--half">
                <label class="field__label" for="rule-window">Window (seconds)<span class="field__required">*</span></label>
                <input class="field__control" type="number" id="rule-window" name="window_seconds" min="1" max="86400" required>
            </div>
            <div class="field field--half">
                <label class="field__label" for="rule-action">Action<span class="field__required">*</span></label>
                <select class="field__control" id="rule-action" name="action" required>
                    <option value="log">Log only</option>
                    <option value="notify">Notify an administrator</option>
                    <option value="block">Block the source</option>
                    <option value="lock">Lock the account</option>
                </select>
            </div>
            <div class="field field--half">
                <label class="field__label" for="rule-severity">Severity<span class="field__required">*</span></label>
                <select class="field__control" id="rule-severity" name="severity" required>
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                    <option value="critical">Critical</option>
                </select>
            </div>
            <div class="field field--full">
                <label class="field__check">
                    <input type="checkbox" id="rule-enabled" name="is_enabled" value="1">
                    <span>Enforce this rule</span>
                </label>
            </div>
        </div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'rule-form', 'title' => 'Security threshold', 'size' => 'md', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="rule-form">Save threshold</button>',
    ]) ?>
<?php endif; ?>
