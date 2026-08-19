<?php
/**
 * Vehicles currently inside the park.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var list<array<string,mixed>> $inside
 * @var list<array<string,mixed>> $overstaying
 * @var list<array<string,mixed>> $devices
 * @var bool $canManual
 */
$this->layout('layouts/app');

$overstayIds = array_map(static fn (array $row): int => (int) $row['access_log_id'], $overstaying);
$threshold   = (int) config('monitoring.rules.overstay_alert_hours', 24);

$this->start('page_subtitle');
printf(
    '%d vehicle%s inside right now%s.',
    count($inside),
    count($inside) === 1 ? '' : 's',
    $overstaying === [] ? '' : sprintf(', %d past the %d-hour mark', count($overstaying), $threshold)
);
$this->stop();

$this->start('page_actions');
?>
<?php if ($canManual): ?>
    <button type="button" class="button button--primary" data-modal-open="manual-movement">
        <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Record manually
    </button>
<?php endif; ?>
<button type="button" class="button button--ghost" data-reload>
    <i class="fa-solid fa-rotate" aria-hidden="true"></i> Refresh
</button>
<?php
$this->stop();
?>

<section class="card">
    <header class="card__header">
        <h2 class="card__title"><i class="fa-solid fa-warehouse" aria-hidden="true"></i> Inside now</h2>
        <div class="card__actions">
            <div class="data-table__search">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <label class="visually-hidden" for="inside-filter">Filter this list</label>
                <input type="search" id="inside-filter" placeholder="Filter by plate or name…"
                       data-filter-list="#inside-table" autocomplete="off">
            </div>
        </div>
    </header>
    <div class="card__body card__body--flush">
        <?php if ($inside === []): ?>
            <?= $this->component('empty-state', [
                'message' => 'The park is empty.',
                'icon'    => 'fa-warehouse',
                'hint'    => 'Every vehicle that entered has been recorded leaving.',
            ]) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table" id="inside-table">
                    <thead>
                        <tr>
                            <th scope="col">Plate</th>
                            <th scope="col">Owner / visitor</th>
                            <th scope="col">Type</th>
                            <th scope="col">Entered</th>
                            <th scope="col">Time inside</th>
                            <th scope="col">Station</th>
                            <th scope="col" class="table__actions">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inside as $visit): ?>
                            <?php $late = in_array((int) $visit['access_log_id'], $overstayIds, true); ?>
                            <tr class="<?= $late ? 'table__row--warning' : '' ?>">
                                <td>
                                    <strong><?= e((string) $visit['plate_number']) ?></strong>
                                    <?php if ((int) ($visit['is_visitor'] ?? 0) === 1): ?>
                                        <span class="badge badge--info">Visitor</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($visit['owner_name'] ?? $visit['visitor_name'] ?? '—')) ?></td>
                                <td><?= e((string) ($visit['vehicle_type'] ?? '—')) ?></td>
                                <td><time data-relative-time="<?= e((string) $visit['entry_time']) ?>"><?= e((string) $visit['entry_time']) ?></time></td>
                                <td data-elapsed-since="<?= e((string) $visit['entry_time']) ?>">—</td>
                                <td><?= e((string) ($visit['entry_device_name'] ?? '—')) ?></td>
                                <td class="table__actions">
                                    <a class="button button--sm button--ghost"
                                       href="<?= e(url('/monitoring/' . (string) $visit['access_log_id'])) ?>">Open</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($canManual): ?>
    <?php
    ob_start();
    ?>
    <form data-ajax-form
          data-endpoint="<?= e(route('api.monitoring.manual')) ?>"
          data-method="POST"
          data-success="A manual record was created."
          data-reload-on-success="true">
        <p class="form-note">
            Use this only when a station is out of service. The movement goes through the same checks
            as a scan, so a revoked tag is still refused, and the record is marked as manually entered
            with your name against it.
        </p>
        <div class="field-grid">
            <div class="field field--half">
                <label class="field__label" for="manual-device">Station<span class="field__required">*</span></label>
                <select class="field__control" id="manual-device" name="device_id" required>
                    <option value="">Select a station</option>
                    <?php foreach ($devices as $device): ?>
                        <option value="<?= e((string) $device['device_id']) ?>">
                            <?= e((string) $device['device_name']) ?> (<?= e((string) $device['gate_type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field--half">
                <label class="field__label" for="manual-action">Movement<span class="field__required">*</span></label>
                <select class="field__control" id="manual-action" name="access_type" required>
                    <option value="entry">Entry</option>
                    <option value="exit">Exit</option>
                </select>
            </div>
            <div class="field field--half">
                <label class="field__label" for="manual-uid">RFID UID<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="manual-uid" name="rfid_uid" required
                       placeholder="e.g. 04A2B3C4" autocomplete="off" spellcheck="false">
                <p class="field__help">The UID printed on the tag, or read from the handheld reader.</p>
            </div>
            <div class="field field--half">
                <label class="field__label" for="manual-time">Time of movement</label>
                <input class="field__control" type="datetime-local" id="manual-time" name="occurred_at">
                <p class="field__help">Leave empty to record it as happening now.</p>
            </div>
            <div class="field field--full">
                <label class="field__label" for="manual-remarks">Why this is being recorded by hand<span class="field__required">*</span></label>
                <textarea class="field__control" id="manual-remarks" name="remarks" rows="2" required
                          placeholder="e.g. Entry-lane reader unresponsive; movement recorded from the logbook."></textarea>
            </div>
        </div>
    </form>
    <?php
    $body = (string) ob_get_clean();
    ?>
    <?= $this->component('modal', [
        'id'    => 'manual-movement',
        'title' => 'Record a movement by hand',
        'size'  => 'lg',
        'body'  => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="manual-movement">Record movement</button>',
    ]) ?>
<?php endif; ?>
