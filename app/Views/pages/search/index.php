<?php
/**
 * Global search results.
 *
 * @var \App\Core\View\ViewEngine $this
 * @var string $term
 * @var array<string,mixed>|null $results
 */
$this->layout('layouts/app');

/*
 * The service returns one entry per module it was permitted to search, each
 * carrying its own label, icon and rows.
 */
$groups = (array) ($results ?? []);
$total  = array_sum(array_map(
    static fn (array $group): int => count((array) ($group['results'] ?? [])),
    $groups
));

$this->start('page_subtitle');
if ($results === null) {
    echo 'Search a plate, a name, a tag UID or a reference. Two characters is the minimum.';
} else {
    printf('%d result%s for “%s”.', $total, $total === 1 ? '' : 's', e($term));
}
$this->stop();
?>

<form class="card card--filters" method="get" action="<?= e(route('search')) ?>" role="search">
    <div class="card__body">
        <div class="field field--full">
            <label class="visually-hidden" for="search-term">Search</label>
            <div class="field__group">
                <i class="fa-solid fa-magnifying-glass field__adornment" aria-hidden="true"></i>
                <input class="field__control" type="search" id="search-term" name="q" value="<?= e($term) ?>"
                       placeholder="Plate, name, tag UID, transaction reference…" autocomplete="off" autofocus>
            </div>
        </div>
    </div>
</form>

<?php if ($results === null): ?>
    <?= $this->component('empty-state', [
        'message' => 'Type something to search.',
        'icon'    => 'fa-magnifying-glass',
        'hint'    => 'Only the modules your role can open are searched, so the results are already filtered to what you may see.',
    ]) ?>
<?php elseif ($groups === []): ?>
    <?= $this->component('empty-state', [
        'message' => sprintf('Nothing matched “%s”.', $term),
        'icon'    => 'fa-magnifying-glass',
        'hint'    => 'Check the spelling, or try a shorter fragment — a partial plate usually works.',
    ]) ?>
<?php else: ?>
    <?php foreach ($groups as $group): ?>
        <section class="card">
            <header class="card__header">
                <h2 class="card__title">
                    <i class="fa-solid <?= e((string) ($group['icon'] ?? 'fa-folder')) ?>" aria-hidden="true"></i>
                    <?= e((string) ($group['label'] ?? 'Results')) ?>
                </h2>
                <span class="card__note"><?= e((string) count((array) ($group['results'] ?? []))) ?></span>
            </header>
            <div class="card__body card__body--flush">
                <ul class="record-list">
                    <?php foreach ((array) ($group['results'] ?? []) as $item): ?>
                        <li class="record-list__item">
                            <a class="record-list__link" href="<?= e(url((string) ($item['link'] ?? '/'))) ?>">
                                <span class="record-list__title"><?= e((string) ($item['title'] ?? '')) ?></span>
                                <span class="record-list__meta"><?= e((string) ($item['subtitle'] ?? '')) ?></span>
                            </a>
                            <?php if (($item['status'] ?? '') !== ''): ?>
                                <?= $this->component('badge', ['value' => (string) $item['status']]) ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
