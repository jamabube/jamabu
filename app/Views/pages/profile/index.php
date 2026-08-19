<?php
/**
 * The signed-in user's own profile.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<string,mixed> $profile
 * @var list<array<string,mixed>> $sessions
 * @var list<array<string,mixed>> $activity
 * @var int|null $passwordExpiry
 */
$this->layout('layouts/app');

$user = (array) ($profile['user'] ?? []);

$this->start('page_actions');
?>
<a class="button button--ghost" href="<?= e(route('profile.password')) ?>">
    <i class="fa-solid fa-key" aria-hidden="true"></i> Change password
</a>
<?php
$this->stop();
?>

<?php if ($passwordExpiry !== null && $passwordExpiry <= 14): ?>
    <div class="alert alert--<?= $passwordExpiry <= 3 ? 'danger' : 'warning' ?>" role="status">
        <i class="fa-solid fa-key" aria-hidden="true"></i>
        <span>
            <?php if ($passwordExpiry <= 0): ?>
                Your password has expired and must be changed now.
            <?php else: ?>
                Your password expires in <?= e((string) $passwordExpiry) ?> day(s).
            <?php endif; ?>
            <a href="<?= e(route('profile.password')) ?>">Change it</a>.
        </span>
    </div>
<?php endif; ?>

<div class="detail-grid">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-user" aria-hidden="true"></i> Your details</h2>
        </header>
        <div class="card__body">
            <form method="post" action="<?= e(route('profile.update')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="field-grid">
                    <div class="field field--third">
                        <label class="field__label" for="p-first">First name<span class="field__required">*</span></label>
                        <input class="field__control" type="text" id="p-first" name="first_name" required maxlength="60"
                               value="<?= e((string) old('first_name', $user['first_name'] ?? '')) ?>">
                    </div>
                    <div class="field field--third">
                        <label class="field__label" for="p-middle">Middle name</label>
                        <input class="field__control" type="text" id="p-middle" name="middle_name" maxlength="60"
                               value="<?= e((string) old('middle_name', $user['middle_name'] ?? '')) ?>">
                    </div>
                    <div class="field field--third">
                        <label class="field__label" for="p-last">Last name<span class="field__required">*</span></label>
                        <input class="field__control" type="text" id="p-last" name="last_name" required maxlength="60"
                               value="<?= e((string) old('last_name', $user['last_name'] ?? '')) ?>">
                    </div>
                    <div class="field field--half">
                        <label class="field__label" for="p-email">Email<span class="field__required">*</span></label>
                        <input class="field__control" type="email" id="p-email" name="email" required maxlength="150"
                               value="<?= e((string) old('email', $user['email'] ?? '')) ?>">
                    </div>
                    <div class="field field--half">
                        <label class="field__label" for="p-mobile">Mobile number</label>
                        <input class="field__control" type="text" id="p-mobile" name="mobile_number" maxlength="30"
                               value="<?= e((string) old('mobile_number', $user['mobile_number'] ?? '')) ?>">
                    </div>
                </div>
                <p class="form-note">
                    Your username, role and department are administrative settings and are changed by an
                    administrator, not here.
                </p>
                <div class="form-actions">
                    <button type="submit" class="button button--primary">
                        <i class="fa-solid fa-check" aria-hidden="true"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-id-badge" aria-hidden="true"></i> Your account</h2>
        </header>
        <div class="card__body">
            <dl class="definition-list">
                <div class="definition-list__row"><dt>Username</dt><dd class="table__mono"><?= e((string) ($user['username'] ?? '')) ?></dd></div>
                <div class="definition-list__row"><dt>Employee number</dt><dd class="table__mono"><?= e((string) ($user['employee_number'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Role</dt><dd><?= e((string) ($user['role_name'] ?? '')) ?></dd></div>
                <div class="definition-list__row"><dt>Department</dt><dd><?= e((string) ($user['department_name'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Position</dt><dd><?= e((string) ($user['position'] ?? '—')) ?></dd></div>
                <div class="definition-list__row"><dt>Password changed</dt><dd><?= e((string) ($user['password_changed_at'] ?? 'Never')) ?></dd></div>
                <div class="definition-list__row"><dt>Last signed in</dt><dd><?= e((string) ($user['last_login_at'] ?? '—')) ?></dd></div>
            </dl>

            <h3 class="card__subtitle">What you may do</h3>
            <ul class="permission-chips">
                <?php foreach ((array) ($profile['permissions'] ?? []) as $permission): ?>
                    <li class="chip table__mono"><?= e((string) $permission) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
</div>

<div class="detail-grid">
    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-desktop" aria-hidden="true"></i> Your open sessions</h2>
        </header>
        <div class="card__body card__body--flush">
            <?php if ($sessions === []): ?>
                <?= $this->component('empty-state', ['message' => 'No other sessions are open.', 'icon' => 'fa-desktop']) ?>
            <?php else: ?>
                <ul class="record-list">
                    <?php foreach ($sessions as $session): ?>
                        <li class="record-list__item">
                            <span class="record-list__link">
                                <span class="record-list__title"><?= e((string) ($session['device_label'] ?? 'Unknown workstation')) ?></span>
                                <span class="record-list__meta">
                                    <?= e((string) ($session['ip_address'] ?? '')) ?>
                                    <span aria-hidden="true">·</span>
                                    active <time data-relative-time="<?= e((string) $session['last_activity_at']) ?>"><?= e((string) $session['last_activity_at']) ?></time>
                                </span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <header class="card__header">
            <h2 class="card__title"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i> Your recent activity</h2>
        </header>
        <div class="card__body card__body--flush">
            <?php if ($activity === []): ?>
                <?= $this->component('empty-state', ['message' => 'Nothing recorded yet.', 'icon' => 'fa-clipboard']) ?>
            <?php else: ?>
                <ul class="timeline timeline--padded">
                    <?php foreach ($activity as $entry): ?>
                        <li class="timeline__item">
                            <span class="timeline__marker" aria-hidden="true"></span>
                            <span class="timeline__body">
                                <span class="timeline__text"><?= e((string) $entry['description']) ?></span>
                                <span class="timeline__meta">
                                    <time data-relative-time="<?= e((string) $entry['created_at']) ?>"><?= e((string) $entry['created_at']) ?></time>
                                </span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
</div>
