<?php
/**
 * Backup and restore.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $summary
 * @var array<string,bool> $can
 */
$this->layout('layouts/app');

$missing  = (array) ($summary['missing_files'] ?? []);
$orphaned = (array) ($summary['orphaned_files'] ?? []);

$this->start('page_subtitle');
echo 'A restore replaces the live database. It takes a snapshot of the current state first, without being asked — the moment somebody needs that snapshot is the moment they will not have thought to take one.';
$this->stop();

$this->start('page_actions');
?>
<?php if ($can['create']): ?>
    <button type="button" class="button button--primary" data-modal-open="backup-form">
        <i class="fa-solid fa-database" aria-hidden="true"></i> Create a backup
    </button>
<?php endif; ?>
<button type="button" class="button button--ghost" data-reconcile-backups
        data-endpoint="<?= e(route('api.backups.reconcile')) ?>">
    <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i> Reconcile
</button>
<?php
$this->stop();

$bytes  = (int) ($summary['total_bytes'] ?? 0);
$sizeMb = $bytes > 0 ? number_format($bytes / 1048576, 1) . ' MB' : '0 MB';
?>

<section class="stat-grid stat-grid--compact">
    <?= $this->component('stat-card', ['label' => 'Archives', 'value' => (string) ($summary['total'] ?? 0), 'icon' => 'fa-box-archive', 'tone' => 'neutral']) ?>
    <?= $this->component('stat-card', ['label' => 'Successful', 'value' => (string) ($summary['successful'] ?? 0), 'icon' => 'fa-circle-check', 'tone' => 'success']) ?>
    <?= $this->component('stat-card', ['label' => 'Failed', 'value' => (string) ($summary['failed'] ?? 0), 'icon' => 'fa-circle-xmark', 'tone' => ((int) ($summary['failed'] ?? 0)) > 0 ? 'danger' : 'neutral']) ?>
    <?= $this->component('stat-card', ['label' => 'Total size', 'value' => $sizeMb, 'icon' => 'fa-hard-drive', 'tone' => 'neutral', 'caption' => 'Last run ' . (string) ($summary['last_run'] ?? 'never')]) ?>
</section>

<?php if ($missing !== []): ?>
    <div class="alert alert--danger" role="alert">
        <i class="fa-solid fa-file-circle-xmark" aria-hidden="true"></i>
        <div>
            <strong><?= e((string) count($missing)) ?> archive(s) are recorded but missing from disk.</strong>
            <p class="table__mono"><?= e(implode(', ', array_map('strval', $missing))) ?></p>
        </div>
    </div>
<?php endif; ?>

<?php if ($orphaned !== []): ?>
    <div class="alert alert--warning" role="status">
        <i class="fa-solid fa-file-circle-question" aria-hidden="true"></i>
        <div>
            <strong><?= e((string) count($orphaned)) ?> file(s) on disk are not in the register.</strong>
            <p class="table__mono"><?= e(implode(', ', array_map('strval', $orphaned))) ?></p>
        </div>
    </div>
<?php endif; ?>

<div data-backup-admin
     data-can-restore="<?= $can['restore'] ? '1' : '0' ?>"
     data-can-download="<?= $can['download'] ? '1' : '0' ?>"
     data-can-delete="<?= $can['delete'] ? '1' : '0' ?>">
    <?= $this->component('data-table', [
        'id'           => 'backups-table',
        'endpoint'     => route('api.backups'),
        'sort'         => 'created_at',
        'emptyMessage' => 'No backups have been taken.',
        'columns'      => [
            ['key' => 'created_at',  'label' => 'Taken', 'sortable' => true, 'format' => 'datetime'],
            ['key' => 'filename',    'label' => 'Archive', 'class' => 'table__mono'],
            ['key' => 'backup_type', 'label' => 'Kind', 'sortable' => true],
            ['key' => 'file_size',   'label' => 'Size', 'sortable' => true, 'format' => 'bytes'],
            ['key' => 'table_count', 'label' => 'Tables', 'format' => 'number'],
            ['key' => 'row_count',   'label' => 'Rows', 'format' => 'number'],
            ['key' => 'duration_ms', 'label' => 'Took', 'format' => 'milliseconds'],
            ['key' => 'status',      'label' => 'Status', 'sortable' => true, 'format' => 'badge'],
        ],
    ]) ?>
</div>

<?php if ($can['create']): ?>
    <?php ob_start(); ?>
    <form data-ajax-form data-endpoint="<?= e(route('api.backups.store')) ?>"
          data-method="POST" data-success="The backup was created." data-reload-on-success="true">
        <p class="form-note">
            The archive contains the schema, the data, the views and the triggers. It never contains
            the environment file, so credentials are not carried into a backup.
        </p>
        <div class="field-grid">
            <div class="field field--full">
                <label class="field__check">
                    <input type="checkbox" name="include_uploads" value="1">
                    <span>Include uploaded files (photos and attachments)</span>
                </label>
            </div>
        </div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'backup-form', 'title' => 'Create a backup', 'size' => 'md', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="backup-form">Create backup</button>',
    ]) ?>
<?php endif; ?>
