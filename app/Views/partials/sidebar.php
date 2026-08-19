<?php
/**
 * Sidebar navigation, generated from config/navigation.php.
 *
 * An entry is rendered only when the user holds its permission, so the menu
 * never offers a page that would answer 403. A group whose children are all
 * hidden disappears with them, and a section header with nothing under it is
 * dropped too — an empty heading is worse than no heading.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var array<int,array<string,mixed>> $navigation
 * @var string $currentRoute
 * @var int $unreadCount
 */

/**
 * Whether a menu entry should be shown to this user.
 *
 * @param array<string,mixed> $item
 */
$visible = static function (array $item): bool {
    $permission = $item['permission'] ?? null;

    return !is_string($permission) || $permission === '' || can($permission);
};

/**
 * A URL for an entry, or null when its route does not exist.
 *
 * @param array<string,mixed> $item
 */
$link = static function (array $item): ?string {
    $name = $item['route'] ?? null;

    if (!is_string($name) || $name === '') {
        return null;
    }

    try {
        return route($name);
    } catch (\Throwable) {
        // A menu entry pointing at a route that was removed must not take the
        // whole page down with it.
        return null;
    }
};

$badgeFor = static function (array $item) use ($unreadCount): ?array {
    return match ($item['badge'] ?? null) {
        'notifications' => $unreadCount > 0
            ? ['text' => (string) min(99, $unreadCount), 'class' => 'badge--danger']
            : null,
        'live'     => ['text' => 'LIVE', 'class' => 'badge--live'],
        'security' => null,
        default    => null,
    };
};

// The section header is buffered and only emitted once an item under it is
// actually rendered.
$pendingHeader = null;
?>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar__brand">
        <a class="sidebar__brand-link" href="<?= e(route('dashboard')) ?>">
            <span class="sidebar__mark" aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></span>
            <span class="sidebar__brand-text">
                <span class="sidebar__brand-name"><?= e((string) config('app.short_name', 'VAMS')) ?></span>
                <span class="sidebar__brand-sub"><?= e((string) config('app.organization', '')) ?></span>
            </span>
        </a>
        <button type="button" class="sidebar__close" data-sidebar-close aria-label="Close navigation">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
    </div>

    <nav class="sidebar__nav">
        <ul class="nav-list">
            <?php foreach ($navigation as $item): ?>
                <?php if (isset($item['header'])): ?>
                    <?php $pendingHeader = (string) $item['header']; ?>
                    <?php continue; ?>
                <?php endif; ?>

                <?php if (!$visible($item)) { continue; } ?>

                <?php
                /** @var list<array<string,mixed>> $children */
                $children = array_values(array_filter(
                    (array) ($item['children'] ?? []),
                    static fn (array $child): bool => $visible($child)
                ));

                $href = $link($item);

                if ($href === null && $children === []) {
                    continue;
                }
                ?>

                <?php if ($pendingHeader !== null): ?>
                    <li class="nav-list__header"><?= e($pendingHeader) ?></li>
                    <?php $pendingHeader = null; ?>
                <?php endif; ?>

                <?php if ($children !== []): ?>
                    <?php
                    $childRoutes = array_map(
                        static fn (array $child): string => (string) ($child['route'] ?? ''),
                        $children
                    );
                    $open = in_array($currentRoute, $childRoutes, true);
                    ?>
                    <li class="nav-list__item nav-list__item--group<?= $open ? ' is-open' : '' ?>">
                        <button type="button" class="nav-list__link nav-list__toggle" aria-expanded="<?= $open ? 'true' : 'false' ?>">
                            <i class="nav-list__icon fa-solid <?= e((string) ($item['icon'] ?? 'fa-circle')) ?>" aria-hidden="true"></i>
                            <span class="nav-list__label"><?= e((string) $item['label']) ?></span>
                            <i class="nav-list__caret fa-solid fa-chevron-right" aria-hidden="true"></i>
                        </button>
                        <ul class="nav-list nav-list--child">
                            <?php foreach ($children as $child): ?>
                                <?php $childHref = $link($child); ?>
                                <?php if ($childHref === null) { continue; } ?>
                                <li class="nav-list__item">
                                    <a class="nav-list__link<?= $currentRoute === ($child['route'] ?? '') ? ' is-active' : '' ?>"
                                       href="<?= e($childHref) ?>">
                                        <i class="nav-list__icon fa-solid <?= e((string) ($child['icon'] ?? 'fa-circle')) ?>" aria-hidden="true"></i>
                                        <span class="nav-list__label"><?= e((string) $child['label']) ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php else: ?>
                    <?php $badge = $badgeFor($item); ?>
                    <li class="nav-list__item">
                        <a class="nav-list__link<?= $currentRoute === ($item['route'] ?? '') ? ' is-active' : '' ?>"
                           href="<?= e((string) $href) ?>"
                           <?= $currentRoute === ($item['route'] ?? '') ? 'aria-current="page"' : '' ?>>
                            <i class="nav-list__icon fa-solid <?= e((string) ($item['icon'] ?? 'fa-circle')) ?>" aria-hidden="true"></i>
                            <span class="nav-list__label"><?= e((string) $item['label']) ?></span>
                            <?php if ($badge !== null): ?>
                                <span class="badge <?= e($badge['class']) ?>" data-badge="<?= e((string) ($item['badge'] ?? '')) ?>"><?= e($badge['text']) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="sidebar__footer">
        <span class="sidebar__version">v<?= e((string) config('app.version', '1.0.0')) ?></span>
        <span class="sidebar__status" data-system-status title="System status">
            <i class="fa-solid fa-circle" aria-hidden="true"></i> <span data-system-status-text>Checking…</span>
        </span>
    </div>
</aside>
