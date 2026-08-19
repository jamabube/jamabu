<?php
/**
 * Live monitoring — the screen the guardhouse leaves open.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var list<array<string,mixed>> $feed
 * @var list<array<string,mixed>> $devices
 * @var int $interval
 */
$this->layout('layouts/app');

$this->start('page_subtitle');
echo 'Entries and exits appear here within ' . e((string) $interval) . ' seconds of being scanned.';
$this->stop();

$this->start('page_actions');
?>
<label class="switch">
    <input type="checkbox" data-live-toggle checked>
    <span class="switch__track" aria-hidden="true"><span class="switch__thumb"></span></span>
    <span class="switch__label">Live</span>
</label>
<label class="switch" title="Play a short tone when a movement is recorded">
    <input type="checkbox" data-live-sound>
    <span class="switch__track" aria-hidden="true"><span class="switch__thumb"></span></span>
    <span class="switch__label">Sound</span>
</label>
<a class="button button--ghost" href="<?= e(route('monitoring.history')) ?>">
    <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> History
</a>
<?php
$this->stop();
?>

<div class="live" data-live-monitor
     data-endpoint="<?= e(route('api.access.live')) ?>"
     data-interval="<?= e((string) $interval) ?>"
     data-since="<?= e((string) ($feed[0]['access_log_id'] ?? 0)) ?>">

    <div class="live__grid">
        <section class="card live__feed">
            <header class="card__header">
                <h2 class="card__title">
                    <i class="fa-solid fa-tower-broadcast" aria-hidden="true"></i> Movements
                    <span class="pulse" data-live-pulse aria-hidden="true"></span>
                </h2>
                <span class="card__note" data-live-status aria-live="polite">Connected</span>
            </header>
            <div class="card__body card__body--flush">
                <ul class="feed feed--tall" data-activity-feed>
                    <?php foreach ($feed as $movement): ?>
                        <?= $this->include('partials/activity-row', ['movement' => $movement]) ?>
                    <?php endforeach; ?>
                </ul>
                <?php if ($feed === []): ?>
                    <?= $this->component('empty-state', [
                        'message' => 'Nothing has come through the gates yet.',
                        'icon'    => 'fa-car-side',
                        'hint'    => 'This list fills itself; there is no need to reload the page.',
                    ]) ?>
                <?php endif; ?>
            </div>
        </section>

        <div class="live__side">
            <section class="card">
                <header class="card__header">
                    <h2 class="card__title"><i class="fa-solid fa-microchip" aria-hidden="true"></i> Stations</h2>
                </header>
                <div class="card__body card__body--flush">
                    <ul class="device-list" data-device-list>
                        <?php foreach ($devices as $device): ?>
                            <li class="device-list__item" data-device-id="<?= e((string) $device['device_id']) ?>">
                                <span class="device-list__dot device-list__dot--<?= e((string) $device['connectivity']) ?>" aria-hidden="true"></span>
                                <span class="device-list__body">
                                    <span class="device-list__name"><?= e((string) $device['device_name']) ?></span>
                                    <span class="device-list__meta">
                                        <?= e(ucfirst((string) $device['gate_type'])) ?> gate
                                        <?php if (($device['last_scan_at'] ?? null) !== null): ?>
                                            <span aria-hidden="true">·</span>
                                            last scan <time data-relative-time="<?= e((string) $device['last_scan_at']) ?>"><?= e((string) $device['last_scan_at']) ?></time>
                                        <?php endif; ?>
                                    </span>
                                </span>
                                <?= $this->component('badge', ['value' => (string) $device['connectivity']]) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($devices === []): ?>
                        <?= $this->component('empty-state', ['message' => 'No stations registered.', 'icon' => 'fa-microchip']) ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card">
                <header class="card__header">
                    <h2 class="card__title"><i class="fa-solid fa-gauge" aria-hidden="true"></i> Right now</h2>
                </header>
                <div class="card__body">
                    <dl class="definition-list">
                        <div class="definition-list__row">
                            <dt>Vehicles inside</dt>
                            <dd><strong data-bind="card.inside">—</strong></dd>
                        </div>
                        <div class="definition-list__row">
                            <dt>Entries today</dt>
                            <dd><strong data-bind="card.entries_today">—</strong></dd>
                        </div>
                        <div class="definition-list__row">
                            <dt>Exits today</dt>
                            <dd><strong data-bind="card.exits_today">—</strong></dd>
                        </div>
                        <div class="definition-list__row">
                            <dt>Refused today</dt>
                            <dd><strong data-bind="card.rejected_today">—</strong></dd>
                        </div>
                    </dl>
                    <a class="button button--ghost button--block" href="<?= e(route('monitoring.inside')) ?>">
                        Who is inside
                    </a>
                </div>
            </section>
        </div>
    </div>
</div>
