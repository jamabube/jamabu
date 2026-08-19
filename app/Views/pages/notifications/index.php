<?php
/**
 * The notification centre.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var int $unread
 * @var array<string,int> $byPriority
 * @var bool $canDelete
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo $unread === 0
    ? 'Everything here has been read.'
    : sprintf('%d unread.', $unread);
$this->stop();

$this->start('page_actions');
?>
<button type="button" class="button button--ghost" data-notifications-read-all>
    <i class="fa-solid fa-check-double" aria-hidden="true"></i> Mark all read
</button>
<?php
$this->stop();
?>

<section class="stat-grid stat-grid--compact">
    <?= $this->component('stat-card', ['label' => 'Critical', 'value' => (string) ($byPriority['critical'] ?? 0), 'icon' => 'fa-circle-exclamation', 'tone' => 'danger']) ?>
    <?= $this->component('stat-card', ['label' => 'High', 'value' => (string) ($byPriority['high'] ?? 0), 'icon' => 'fa-triangle-exclamation', 'tone' => 'warning']) ?>
    <?= $this->component('stat-card', ['label' => 'Normal', 'value' => (string) ($byPriority['normal'] ?? 0), 'icon' => 'fa-circle-info', 'tone' => 'accent']) ?>
    <?= $this->component('stat-card', ['label' => 'Low', 'value' => (string) ($byPriority['low'] ?? 0), 'icon' => 'fa-circle', 'tone' => 'neutral']) ?>
</section>

<?php
ob_start();
?>
<label class="visually-hidden" for="n-state">State</label>
<select id="n-state" class="field__control field__control--sm" data-filter="state">
    <option value="">Unread and read</option>
    <option value="unread">Unread only</option>
    <option value="archived">Archived</option>
</select>
<label class="visually-hidden" for="n-priority">Priority</label>
<select id="n-priority" class="field__control field__control--sm" data-filter="priority">
    <option value="">Any priority</option>
    <option value="critical">Critical</option>
    <option value="high">High</option>
    <option value="normal">Normal</option>
    <option value="low">Low</option>
</select>
<button type="button" class="button button--ghost button--sm" data-table-reset>Clear</button>
<?php
$filterControls = (string) ob_get_clean();
?>

<div class="notification-centre" data-notification-centre data-can-delete="<?= $canDelete ? '1' : '0' ?>">
    <?= $this->component('data-table', [
        'id'           => 'notifications-table',
        'endpoint'     => route('api.notifications'),
        'sort'         => 'created_at',
        'emptyMessage' => 'Nothing to show.',
        'filterControls' => $filterControls,
        'columns'      => [
            ['key' => 'created_at', 'label' => 'When', 'sortable' => true, 'format' => 'datetime'],
            ['key' => 'priority',   'label' => 'Priority', 'sortable' => true, 'format' => 'badge'],
            ['key' => 'title',      'label' => 'Notification', 'format' => 'strong'],
            ['key' => 'message',    'label' => 'Detail'],
            ['key' => 'is_read',    'label' => 'Read', 'format' => 'boolean'],
        ],
    ]) ?>
</div>
