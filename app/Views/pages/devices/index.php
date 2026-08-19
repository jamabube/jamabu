<?php
/**
 * Monitoring stations.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $summary
 * @var list<array<string,mixed>> $devices
 * @var array<string,bool> $can
 */
$this->layout('layouts/app');

$connectivity = (array) ($summary['connectivity'] ?? []);

$this->start('page_subtitle');
echo 'The ESP32 readers at the gates. A station is offline once three heartbeats have been missed.';
$this->stop();

$this->start('page_actions');
?>
<?php if ($can['create']): ?>
    <button type="button" class="button button--primary" data-modal-open="device-form" data-form-mode="create">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> Register a station
    </button>
<?php endif; ?>
<button type="button" class="button button--ghost" data-reload>
    <i class="fa-solid fa-rotate" aria-hidden="true"></i> Refresh
</button>
<?php
$this->stop();
?>

<section class="stat-grid stat-grid--compact">
    <?= $this->component('stat-card', ['label' => 'Online', 'value' => (string) ($connectivity['online'] ?? 0), 'icon' => 'fa-plug-circle-check', 'tone' => 'success']) ?>
    <?= $this->component('stat-card', ['label' => 'Offline', 'value' => (string) ($connectivity['offline'] ?? 0), 'icon' => 'fa-plug-circle-xmark', 'tone' => ($connectivity['offline'] ?? 0) > 0 ? 'danger' : 'neutral']) ?>
    <?= $this->component('stat-card', ['label' => 'Never seen', 'value' => (string) ($connectivity['never_seen'] ?? 0), 'icon' => 'fa-plug-circle-exclamation', 'tone' => 'warning', 'caption' => 'registered but never called in']) ?>
    <?= $this->component('stat-card', ['label' => 'Disabled', 'value' => (string) ($connectivity['disabled'] ?? 0), 'icon' => 'fa-ban', 'tone' => ($connectivity['disabled'] ?? 0) > 0 ? 'danger' : 'neutral', 'caption' => 'suspended or out of service']) ?>
</section>

<div class="device-grid" data-device-grid data-endpoint="<?= e(route('api.devices.status')) ?>">
    <?php foreach ($devices as $device): ?>
        <?php
        $connection = (string) $device['connectivity'];
        $health     = $device['health_score'] === null ? null : (int) $device['health_score'];
        ?>
        <article class="device-card device-card--<?= e($connection) ?>" data-device-id="<?= e((string) $device['device_id']) ?>">
            <header class="device-card__header">
                <span class="device-card__dot device-card__dot--<?= e($connection) ?>" aria-hidden="true"></span>
                <div class="device-card__identity">
                    <h2 class="device-card__name">
                        <a href="<?= e(url('/devices/' . (string) $device['device_id'])) ?>"><?= e((string) $device['device_name']) ?></a>
                    </h2>
                    <p class="device-card__code"><?= e((string) $device['device_code']) ?></p>
                </div>
                <?= $this->component('badge', ['value' => $connection]) ?>
            </header>

            <dl class="device-card__stats">
                <div><dt>Gate</dt><dd><?= e(ucfirst((string) $device['gate_type'])) ?></dd></div>
                <div><dt>Location</dt><dd><?= e((string) ($device['location'] ?? '—')) ?></dd></div>
                <div><dt>Firmware</dt><dd><?= e((string) ($device['firmware_version'] ?? '—')) ?></dd></div>
                <div><dt>Signal</dt><dd><?= $device['signal_strength'] === null ? '—' : e((string) $device['signal_strength']) . ' dBm' ?></dd></div>
                <div><dt>Last heartbeat</dt>
                    <dd>
                        <?php if (($device['last_heartbeat_at'] ?? null) === null): ?>
                            Never
                        <?php else: ?>
                            <time data-relative-time="<?= e((string) $device['last_heartbeat_at']) ?>"><?= e((string) $device['last_heartbeat_at']) ?></time>
                        <?php endif; ?>
                    </dd>
                </div>
                <div><dt>Restarts</dt><dd><?= e((string) $device['restart_count']) ?></dd></div>
            </dl>

            <?php if ($health !== null): ?>
                <div class="device-card__health">
                    <div class="meter" role="img" aria-label="Health score <?= e((string) $health) ?> out of 100">
                        <span class="meter__fill meter__fill--<?= $health >= 80 ? 'good' : ($health >= 50 ? 'fair' : 'poor') ?>"
                              style="width: <?= e((string) max(0, min(100, $health))) ?>%"></span>
                    </div>
                    <span class="device-card__health-label">Health <?= e((string) $health) ?>/100</span>
                </div>
            <?php endif; ?>

            <footer class="device-card__actions">
                <a class="button button--sm button--ghost" href="<?= e(url('/devices/' . (string) $device['device_id'])) ?>">Details</a>
                <?php if ($can['rotate']): ?>
                    <button type="button" class="button button--sm button--ghost"
                            data-rotate-key="<?= e((string) $device['device_id']) ?>"
                            data-device-name="<?= e((string) $device['device_name']) ?>">Rotate key</button>
                <?php endif; ?>
                <?php if ($can['suspend']): ?>
                    <?php if ((string) $device['status'] === 'suspended'): ?>
                        <button type="button" class="button button--sm button--ghost"
                                data-reinstate-device="<?= e((string) $device['device_id']) ?>">Reinstate</button>
                    <?php else: ?>
                        <button type="button" class="button button--sm button--ghost"
                                data-suspend-device="<?= e((string) $device['device_id']) ?>"
                                data-device-name="<?= e((string) $device['device_name']) ?>">Suspend</button>
                    <?php endif; ?>
                <?php endif; ?>
            </footer>
        </article>
    <?php endforeach; ?>
</div>

<?php if ($devices === []): ?>
    <?= $this->component('empty-state', [
        'message' => 'No monitoring stations are registered.',
        'icon'    => 'fa-microchip',
        'hint'    => 'Register a station here, then flash its firmware with the API key this page shows once.',
    ]) ?>
<?php endif; ?>

<?php if ($can['create'] || $can['update']): ?>
    <?php ob_start(); ?>
    <form data-ajax-form data-endpoint="<?= e(route('api.devices.store')) ?>"
          data-update-endpoint="<?= e(url('/api/v1/devices/{id}')) ?>"
          data-method="POST" data-success="The station was saved."
          data-secret-field="api_key"
          data-secret-message="Copy this API key now. It is stored only as a hash and cannot be shown again.">
        <input type="hidden" name="id" data-record-id>
        <div class="field-grid">
            <div class="field field--half">
                <label class="field__label" for="dev-name">Station name<span class="field__required">*</span></label>
                <input class="field__control" type="text" id="dev-name" name="device_name" required maxlength="120">
            </div>
            <div class="field field--half">
                <label class="field__label" for="dev-code">Station code</label>
                <input class="field__control" type="text" id="dev-code" name="device_code" maxlength="40"
                       placeholder="Generated automatically if left empty" data-lock-on-edit>
                <p class="field__help">This is the identifier the firmware transmits.</p>
            </div>
            <div class="field field--half">
                <label class="field__label" for="dev-gate">Gate role<span class="field__required">*</span></label>
                <select class="field__control" id="dev-gate" name="gate_type" required>
                    <option value="entry">Entry only</option>
                    <option value="exit">Exit only</option>
                    <option value="both">Both directions</option>
                </select>
                <p class="field__help">A station restricted to one direction cannot record the other, whatever it sends.</p>
            </div>
            <div class="field field--half">
                <label class="field__label" for="dev-label">Gate label</label>
                <input class="field__control" type="text" id="dev-label" name="gate_label" maxlength="60"
                       placeholder="e.g. Main Gate — Entry Lane">
            </div>
            <div class="field field--half">
                <label class="field__label" for="dev-location">Location</label>
                <input class="field__control" type="text" id="dev-location" name="location" maxlength="120">
            </div>
            <div class="field field--half">
                <label class="field__label" for="dev-mac">MAC address</label>
                <input class="field__control" type="text" id="dev-mac" name="mac_address" maxlength="17"
                       placeholder="AA:BB:CC:DD:EE:FF" autocomplete="off" spellcheck="false">
            </div>
            <div class="field field--half">
                <label class="field__label" for="dev-ip">Permitted IP address</label>
                <input class="field__control" type="text" id="dev-ip" name="allowed_ip" maxlength="45">
                <p class="field__help">When set, the station may call from this address only.</p>
            </div>
            <div class="field field--half">
                <label class="field__label" for="dev-heartbeat">Heartbeat interval (seconds)</label>
                <input class="field__control" type="number" id="dev-heartbeat" name="heartbeat_interval"
                       min="5" max="3600" value="<?= e((string) config('api.device.heartbeat_interval', 30)) ?>">
            </div>
            <div class="field field--half">
                <label class="field__label" for="dev-firmware">Firmware version</label>
                <input class="field__control" type="text" id="dev-firmware" name="firmware_version" maxlength="20">
            </div>
            <div class="field field--half">
                <label class="field__label" for="dev-installed">Installation date</label>
                <input class="field__control" type="date" id="dev-installed" name="installation_date">
            </div>
            <div class="field field--half">
                <label class="field__label" for="dev-status">Status</label>
                <select class="field__control" id="dev-status" name="status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </div>
            <div class="field field--full">
                <label class="field__label" for="dev-description">Description</label>
                <input class="field__control" type="text" id="dev-description" name="description" maxlength="255">
            </div>
        </div>
    </form>
    <?php $body = (string) ob_get_clean(); ?>
    <?= $this->component('modal', [
        'id' => 'device-form', 'title' => 'Monitoring station', 'size' => 'lg', 'body' => $body,
        'footer' => '<button type="button" class="button button--ghost" data-modal-close>Cancel</button>'
            . '<button type="button" class="button button--primary" data-modal-submit="device-form">Save station</button>',
    ]) ?>
<?php endif; ?>
