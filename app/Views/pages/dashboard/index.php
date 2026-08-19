<?php
/**
 * The dashboard.
 *
 * Rendered complete on the server: the guardhouse screen has to be useful the
 * instant it loads and has to stay useful if a script fails to fetch. The
 * poller then updates the figures and the feed in place.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $dashboard
 * @var int $refresh
 */
$this->layout('layouts/app');

/** @var array<string,array<string,mixed>> $cards */
$cards       = (array) ($dashboard['cards'] ?? []);
$activity    = (array) ($dashboard['activity'] ?? []);
$devices     = (array) ($dashboard['devices'] ?? []);
$alerts      = (array) ($dashboard['alerts'] ?? []);
$audit       = (array) ($dashboard['audit'] ?? []);
$overstaying = (array) ($dashboard['overstaying'] ?? []);
$charts      = (array) ($dashboard['charts'] ?? []);

$this->start('page_subtitle');
echo 'Live at <time data-relative-time="' . e((string) ($dashboard['generated_at'] ?? '')) . '">'
    . e((string) ($dashboard['generated_at'] ?? '')) . '</time>';
$this->stop();

$this->start('page_actions');
?>
<button type="button" class="button button--ghost" data-dashboard-refresh>
    <i class="fa-solid fa-rotate" aria-hidden="true"></i> Refresh
</button>
<label class="switch" title="Refresh automatically every <?= e((string) $refresh) ?> seconds">
    <input type="checkbox" data-dashboard-autorefresh checked>
    <span class="switch__track" aria-hidden="true"><span class="switch__thumb"></span></span>
    <span class="switch__label">Auto</span>
</label>
<?php
$this->stop();
?>

<div class="dashboard" data-dashboard data-refresh="<?= e((string) $refresh) ?>"
     data-poll-endpoint="<?= e(route('api.dashboard.poll')) ?>">

    <section class="stat-grid" data-dashboard-cards aria-label="Summary">
        <?php foreach ($cards as $key => $card): ?>
            <?= $this->component('stat-card', [
                'label' => (string) $card['label'],
                'value' => (string) $card['value'],
                'icon'  => (string) $card['icon'],
                'tone'  => (string) $card['tone'],
                'href'  => isset($card['link']) ? url((string) $card['link']) : null,
                'bind'  => 'card.' . $key,
            ]) ?>
        <?php endforeach; ?>
    </section>

    <div class="dashboard__grid">
        <?php if (can('monitoring.view')): ?>
            <section class="card dashboard__feed">
                <header class="card__header">
                    <h2 class="card__title">
                        <i class="fa-solid fa-tower-broadcast" aria-hidden="true"></i> Live activity
                        <span class="pulse" data-live-pulse aria-hidden="true"></span>
                    </h2>
                    <div class="card__actions">
                        <a class="link-button" href="<?= e(route('monitoring.live')) ?>">Full view</a>
                    </div>
                </header>
                <div class="card__body card__body--flush">
                    <ul class="feed" data-activity-feed
                        data-since="<?= e((string) ($activity[0]['access_log_id'] ?? 0)) ?>">
                        <?php foreach ($activity as $movement): ?>
                            <?= $this->include('partials/activity-row', ['movement' => $movement]) ?>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($activity === []): ?>
                        <?= $this->component('empty-state', [
                            'message' => 'No movements recorded yet today.',
                            'icon'    => 'fa-car-side',
                            'hint'    => 'Entries and exits appear here the moment a station reports them.',
                        ]) ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <div class="dashboard__side">
            <?php if (can('devices.view')): ?>
                <section class="card">
                    <header class="card__header">
                        <h2 class="card__title"><i class="fa-solid fa-microchip" aria-hidden="true"></i> Stations</h2>
                        <a class="link-button" href="<?= e(route('devices.index')) ?>">Manage</a>
                    </header>
                    <div class="card__body card__body--flush">
                        <ul class="device-list" data-device-list>
                            <?php foreach ($devices as $device): ?>
                                <li class="device-list__item" data-device-id="<?= e((string) $device['device_id']) ?>">
                                    <span class="device-list__dot device-list__dot--<?= e((string) $device['connectivity']) ?>"
                                          aria-hidden="true"></span>
                                    <span class="device-list__body">
                                        <span class="device-list__name"><?= e((string) $device['device_name']) ?></span>
                                        <span class="device-list__meta">
                                            <?= e(ucfirst((string) $device['gate_type'])) ?> gate
                                            <?php if (($device['location'] ?? '') !== ''): ?>
                                                <span aria-hidden="true">·</span> <?= e((string) $device['location']) ?>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                    <?= $this->component('badge', ['value' => (string) $device['connectivity']]) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($devices === []): ?>
                            <?= $this->component('empty-state', [
                                'message' => 'No stations registered.',
                                'icon'    => 'fa-microchip',
                            ]) ?>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (can('security.view')): ?>
                <section class="card">
                    <header class="card__header">
                        <h2 class="card__title"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Security alerts</h2>
                        <a class="link-button" href="<?= e(route('security.index')) ?>">All events</a>
                    </header>
                    <div class="card__body card__body--flush">
                        <ul class="alert-list" data-alert-list>
                            <?php foreach ($alerts as $alert): ?>
                                <li class="alert-list__item alert-list__item--<?= e((string) $alert['severity']) ?>">
                                    <span class="alert-list__body">
                                        <span class="alert-list__title"><?= e((string) $alert['description']) ?></span>
                                        <span class="alert-list__meta">
                                            <time data-relative-time="<?= e((string) $alert['occurred_at']) ?>"><?= e((string) $alert['occurred_at']) ?></time>
                                            <?php if (($alert['ip_address'] ?? '') !== ''): ?>
                                                <span aria-hidden="true">·</span> <?= e((string) $alert['ip_address']) ?>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                    <?= $this->component('badge', ['value' => (string) $alert['severity']]) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($alerts === []): ?>
                            <?= $this->component('empty-state', [
                                'message' => 'No unresolved security events.',
                                'icon'    => 'fa-shield-halved',
                            ]) ?>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($overstaying !== []): ?>
        <section class="card card--warning">
            <header class="card__header">
                <h2 class="card__title">
                    <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
                    Vehicles inside longer than <?= e((string) config('monitoring.rules.overstay_alert_hours', 24)) ?> hours
                </h2>
                <span class="badge badge--warning"><?= e((string) count($overstaying)) ?></span>
            </header>
            <div class="card__body card__body--flush">
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">Plate</th>
                                <th scope="col">Owner</th>
                                <th scope="col">Entered</th>
                                <th scope="col">Station</th>
                                <th scope="col" class="table__actions">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overstaying as $visit): ?>
                                <tr>
                                    <td><strong><?= e((string) $visit['plate_number']) ?></strong></td>
                                    <td><?= e((string) ($visit['owner_name'] ?? $visit['visitor_name'] ?? '—')) ?></td>
                                    <td><time data-relative-time="<?= e((string) $visit['entry_time']) ?>"><?= e((string) $visit['entry_time']) ?></time></td>
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
            </div>
        </section>
    <?php endif; ?>

    <?php if ($charts !== [] && can('monitoring.view')): ?>
        <div class="chart-grid">
            <section class="card">
                <header class="card__header">
                    <h2 class="card__title"><i class="fa-solid fa-chart-column" aria-hidden="true"></i> Movements by hour, today</h2>
                </header>
                <div class="card__body">
                    <div class="chart" data-chart="hourly" data-chart-type="bar"
                         data-chart-payload="<?= e(\App\Core\Support\Html::js($charts['hourly'] ?? [])) ?>">
                        <canvas aria-label="Movements by hour" role="img"></canvas>
                        <noscript><p class="chart__fallback">Charts require JavaScript.</p></noscript>
                    </div>
                </div>
            </section>

            <section class="card">
                <header class="card__header">
                    <h2 class="card__title"><i class="fa-solid fa-chart-line" aria-hidden="true"></i> Daily traffic, last fourteen days</h2>
                </header>
                <div class="card__body">
                    <div class="chart" data-chart="daily" data-chart-type="line"
                         data-chart-payload="<?= e(\App\Core\Support\Html::js($charts['daily'] ?? [])) ?>">
                        <canvas aria-label="Daily traffic" role="img"></canvas>
                    </div>
                </div>
            </section>

            <?php if (($charts['by_type'] ?? []) !== []): ?>
                <section class="card">
                    <header class="card__header">
                        <h2 class="card__title"><i class="fa-solid fa-chart-pie" aria-hidden="true"></i> Registered vehicles by type</h2>
                    </header>
                    <div class="card__body">
                        <div class="chart" data-chart="by_type" data-chart-type="doughnut"
                             data-chart-payload="<?= e(\App\Core\Support\Html::js($charts['by_type'])) ?>">
                            <canvas aria-label="Vehicles by type" role="img"></canvas>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (($charts['denials'] ?? []) !== []): ?>
                <section class="card">
                    <header class="card__header">
                        <h2 class="card__title"><i class="fa-solid fa-ban" aria-hidden="true"></i> Why scans were refused, last thirty days</h2>
                    </header>
                    <div class="card__body">
                        <div class="chart" data-chart="denials" data-chart-type="bar-horizontal"
                             data-chart-payload="<?= e(\App\Core\Support\Html::js($charts['denials'])) ?>">
                            <canvas aria-label="Refusal reasons" role="img"></canvas>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (can('audit.view') && $audit !== []): ?>
        <section class="card">
            <header class="card__header">
                <h2 class="card__title"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i> Recent activity</h2>
                <a class="link-button" href="<?= e(route('audit.index')) ?>">Full trail</a>
            </header>
            <div class="card__body card__body--flush">
                <ul class="timeline">
                    <?php foreach ($audit as $entry): ?>
                        <li class="timeline__item">
                            <span class="timeline__marker" aria-hidden="true"></span>
                            <span class="timeline__body">
                                <span class="timeline__text"><?= e((string) $entry['description']) ?></span>
                                <span class="timeline__meta">
                                    <?= e((string) ($entry['username'] ?? 'system')) ?>
                                    <span aria-hidden="true">·</span>
                                    <time data-relative-time="<?= e((string) $entry['created_at']) ?>"><?= e((string) $entry['created_at']) ?></time>
                                </span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    <?php endif; ?>
</div>
