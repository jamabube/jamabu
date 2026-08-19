<?php
/**
 * A status pill.
 *
 * The colour is derived from the value rather than chosen at each call site,
 * so "inside" is the same green everywhere it appears.
 *
 * @var string      $value
 * @var string|null $label
 */
$key = strtolower(trim((string) $value));

$tones = [
    'active'        => 'success', 'inside'    => 'success', 'granted'  => 'success',
    'assigned'      => 'success', 'available' => 'info',    'issued'   => 'info',
    'completed'     => 'neutral', 'outside'   => 'neutral', 'returned' => 'neutral',
    'inactive'      => 'neutral', 'archived'  => 'neutral', 'unknown'  => 'neutral',
    'pending_sync'  => 'warning', 'maintenance' => 'warning', 'expiring' => 'warning',
    'overdue'       => 'warning', 'expired'   => 'warning',
    'suspended'     => 'danger',  'revoked'   => 'danger',  'lost'     => 'danger',
    'damaged'       => 'danger',  'locked'    => 'danger',  'denied'   => 'danger',
    'decommissioned'=> 'danger',  'offline'   => 'danger',  'failed'   => 'danger',
    'online'        => 'success', 'healthy'   => 'success', 'ok'       => 'success',
    'degraded'      => 'warning', 'unhealthy' => 'danger',  'critical' => 'danger',
    'high'          => 'danger',  'medium'    => 'warning', 'low'      => 'info',
    'normal'        => 'neutral', 'new'       => 'warning',
    'acknowledged'  => 'info',    'investigating' => 'warning',
    'resolved'      => 'success', 'dismissed' => 'neutral',
];

$tone    = $tones[$key] ?? 'neutral';
$display = $label ?? ucwords(str_replace(['_', '-'], ' ', $key));
?>
<span class="badge badge--<?= e($tone) ?>"><?= e((string) $display) ?></span>
