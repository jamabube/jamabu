<?php
/**
 * One account: its details, its sessions and what it has done.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $profile
 * @var list<array<string,mixed>> $sessions
 * @var list<array<string,mixed>> $activity
 */
$this->layout('layouts/app');

$user     = (array) ($profile['user'] ?? []);
$locked   = (int) ($user['is_locked'] ?? 0) === 1;

$this->start('breadcrumbs');
?>
<a href="<?= e(route('users.index')) ?>">Users</a>
<span aria-hidden="true">/</span>
<span><?= e((string) ($user['full_name'] ?? '')) ?></span>
<?php
$this->stop();

$this->start('page_actions');
?>
<?php if ($locked && can('users.unlock')): ?>
    <button type="button" class="button button--primary" data-unlock-user="<?= e((string) $user['user_id']) ?>">
        <i class="fa-solid fa-unlock" aria-hidden="true"></i> Unlock
    </button>
<?php elseif (!$locked && can('users.lock')): ?>
    <button type="button" class="button button--warning" data-lock-user="<?= e((string) $user['user_id']) ?>">
        <i class="fa-solid fa-lock" aria-hidden="true"></i> Lock
    </button>
<?php endif; ?>
<?php if (can('users.reset_password')): ?>
    <button type="button" class="button button--ghost" data-reset-password="<?= e((string) $user['user_id']) ?>">
        <i class="fa-solid fa-key" aria-hidden="true"></i> Reset password
    </button>
<?php endif; ?>
<?php
$this->stop();
?>

<?php if ($locked): ?>
    <div class="alert alert--danger" role="alert">
        <i class="fa-solid fa-lock" aria-hidden="true"></i>
        <div>
            <strong>This account is locked and cannot sign in.</strong>
            <p><?= e((string) ($user['locked_reason'] ?? 'No reason recorded.')) ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="detail-grid">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-user" aria-hidden="true"></i> Account</h2>
            <?= $this->component('badge', ['value' => (string) ($user['status'] ?? 'unknown')]) ?>
        </header>
        <div class="card__body">
            <dl class="definition-list definition-list--two">
                <div class="definition-list__row"><dt>Name</dt><dd><strong><?= e((string) ($user['full_name'] ?? '—')) ?></strong></dd></div>
                <div class="definition-list__row"><dt>Username</dt><dd class="table__mono"><?= e((string) ($user['username'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Employee number</dt><dd class="table__mono"><?= e((string) ($user['employee_number'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Role</dt><dd><?= e((string) ($user['role_name'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Department</dt><dd><?= e((string) ($user['department_name'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Position</dt><dd><?= e((string) ($user['position'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Email</dt><dd><?= e((string) ($user['email'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Mobile</dt><dd><?= e((string) ($user['mobile_number'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Password changed</dt><dd><?= e((string) ($user['password_changed_at'] ?? 'Never')) ?></dd></div>
                <div class="definition-list__row"><dt>Last signed in</dt><dd><?= e((string) ($user['last_login_at'] ?? 'Never')) ?></dd></div>
                <div class="definition-list__row"><dt>Last address</dt><dd class="table__mono"><?= e((string) ($user['last_login_ip'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Failed attempts</dt><dd><?= e((string) ($user['failed_login_attempts'] ?? 0)) ?></dd></div>
            </dl>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-desktop" aria-hidden="true"></i> Sessions</h2>
            <span class="card__note"><?= e((string) count($sessions)) ?></span>
        </header>
        <div class="card__body card__body--flush">
            <?php if ($sessions === []): ?>
                <?= $this->component('empty-state', ['message' => 'No sessions recorded.', 'icon' => 'fa-desktop']) ?>
            <?php else: ?>
                <ul class="record-list">
                    <?php foreach ($sessions as $session): ?>
                        <li class="record-list__item">
                            <span class="record-list__link">
                                <span class="record-list__title"><?= e((string) ($session['ip_address'] ?? 'Unknown address')) ?></span>
                                <span class="record-list__meta">
                                    started <time data-relative-time="<?= e((string) $session['login_at']) ?>"><?= e((string) $session['login_at']) ?></time>
                                    <?php if (($session['logout_at'] ?? null) !== null): ?>
                                        <span aria-hidden="true">·</span> ended <?= e((string) $session['logout_at']) ?>
                                        (<?= e((string) ($session['termination_reason'] ?? 'closed')) ?>)
                                    <?php endif; ?>
                                </span>
                            </span>
                            <?php if ((string) $session['status'] === 'active' && can('users.sessions')): ?>
                                <button type="button" class="button button--sm button--ghost"
                                        data-terminate-session="<?= e((string) $session['user_session_id']) ?>"
                                        data-user-id="<?= e((string) $user['user_id']) ?>">Sign out</button>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="card">
    <header class="card__header">
        <h2 class="card__title"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i> What this account has done</h2>
    </header>
    <div class="card__body card__body--flush">
        <?php if ($activity === []): ?>
            <?= $this->component('empty-state', ['message' => 'Nothing recorded against this account yet.', 'icon' => 'fa-clipboard']) ?>
        <?php else: ?>
            <ul class="timeline timeline--padded">
                <?php foreach ($activity as $entry): ?>
                    <li class="timeline__item">
                        <span class="timeline__marker" aria-hidden="true"></span>
                        <span class="timeline__body">
                            <span class="timeline__text"><?= e((string) $entry['description']) ?></span>
                            <span class="timeline__meta">
                                <?= e((string) $entry['module']) ?>
                                <span aria-hidden="true">·</span>
                                <time data-relative-time="<?= e((string) $entry['created_at']) ?>"><?= e((string) $entry['created_at']) ?></time>
                            </span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
