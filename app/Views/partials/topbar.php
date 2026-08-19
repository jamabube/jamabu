<?php
/**
 * The top bar: sidebar toggle, global search, live clock, notifications and
 * the account menu.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var \App\DTO\AuthenticatedUser|null $currentUser
 * @var int $unreadCount
 */
?>
<header class="topbar">
    <button type="button" class="topbar__toggle" data-sidebar-toggle aria-label="Toggle navigation" aria-controls="sidebar">
        <i class="fa-solid fa-bars" aria-hidden="true"></i>
    </button>

    <form class="topbar__search" action="<?= e(route('search')) ?>" method="get" role="search" data-quick-search>
        <label class="visually-hidden" for="global-search">Search plates, names, tags and references</label>
        <i class="fa-solid fa-magnifying-glass topbar__search-icon" aria-hidden="true"></i>
        <input type="search"
               id="global-search"
               name="q"
               class="topbar__search-input"
               placeholder="Search a plate, a name, a tag or a reference…"
               autocomplete="off"
               value="<?= e((string) ($term ?? '')) ?>">
        <div class="topbar__suggestions" data-search-suggestions hidden></div>
    </form>

    <div class="topbar__actions">
        <span class="topbar__clock" data-clock aria-live="off" title="Server time"></span>

        <button type="button" class="topbar__icon-button" data-theme-toggle aria-label="Switch between light and dark">
            <i class="fa-solid fa-moon" aria-hidden="true"></i>
        </button>

        <?php if (can('notifications.view')): ?>
            <div class="dropdown" data-dropdown>
                <button type="button" class="topbar__icon-button" data-dropdown-toggle aria-expanded="false"
                        aria-label="Notifications">
                    <i class="fa-solid fa-bell" aria-hidden="true"></i>
                    <span class="topbar__count<?= $unreadCount > 0 ? '' : ' is-hidden' ?>" data-notification-count>
                        <?= e((string) min(99, $unreadCount)) ?>
                    </span>
                </button>
                <div class="dropdown__menu dropdown__menu--wide" data-dropdown-menu hidden>
                    <div class="dropdown__header">
                        <span>Notifications</span>
                        <button type="button" class="link-button" data-notifications-read-all>Mark all read</button>
                    </div>
                    <div class="dropdown__body" data-notification-list>
                        <p class="dropdown__empty">Loading…</p>
                    </div>
                    <a class="dropdown__footer" href="<?= e(route('notifications.index')) ?>">View all notifications</a>
                </div>
            </div>
        <?php endif; ?>

        <div class="dropdown" data-dropdown>
            <button type="button" class="topbar__account" data-dropdown-toggle aria-expanded="false">
                <span class="avatar" aria-hidden="true"><?= e($currentUser?->initials() ?? '??') ?></span>
                <span class="topbar__account-text">
                    <span class="topbar__account-name"><?= e($currentUser?->fullName ?? '') ?></span>
                    <span class="topbar__account-role"><?= e($currentUser?->roleName ?? '') ?></span>
                </span>
                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="dropdown__menu" data-dropdown-menu hidden>
                <a class="dropdown__item" href="<?= e(route('profile')) ?>">
                    <i class="fa-solid fa-user" aria-hidden="true"></i> My profile
                </a>
                <a class="dropdown__item" href="<?= e(route('profile.password')) ?>">
                    <i class="fa-solid fa-key" aria-hidden="true"></i> Change password
                </a>
                <div class="dropdown__divider"></div>
                <form action="<?= e(route('logout')) ?>" method="post">
                    <?= csrf_field() ?>
                    <button type="submit" class="dropdown__item dropdown__item--danger">
                        <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Sign out
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
