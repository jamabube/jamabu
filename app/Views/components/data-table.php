<?php
/**
 * A table wired to an API endpoint.
 *
 * The markup carries the endpoint, the columns and the options as data
 * attributes; assets/js/table.js reads them and takes over. Nothing about the
 * table lives in a script tag, so the strict Content-Security Policy needs no
 * exception and the same component serves every listing in the system.
 *
 * @var string                        $id
 * @var string                        $endpoint
 * @var list<array<string,mixed>>     $columns   [key, label, sortable?, class?, format?]
 * @var string|null                   $sort
 * @var string|null                   $direction
 * @var string|null                   $emptyMessage
 * @var string|null                   $rowLink   URL template, e.g. "/vehicles/{vehicle_id}"
 * @var bool|null                     $searchable
 * @var array<string,mixed>|null      $filters   fixed filters sent with every request
 */
$columnSpec = array_map(static function (array $column): array {
    return [
        'key'      => (string) ($column['key'] ?? ''),
        'label'    => (string) ($column['label'] ?? ''),
        'sortable' => (bool) ($column['sortable'] ?? false),
        'class'    => (string) ($column['class'] ?? ''),
        'format'   => (string) ($column['format'] ?? 'text'),
        'empty'    => (string) ($column['empty'] ?? '—'),
    ];
}, $columns);
?>
<div class="data-table"
     id="<?= e($id) ?>"
     data-table
     data-endpoint="<?= e($endpoint) ?>"
     data-columns="<?= e(\App\Core\Support\Html::js($columnSpec)) ?>"
     data-sort="<?= e($sort ?? '') ?>"
     data-direction="<?= e($direction ?? 'DESC') ?>"
     data-row-link="<?= e($rowLink ?? '') ?>"
     data-empty="<?= e($emptyMessage ?? 'Nothing to show yet.') ?>"
     data-fixed-filters="<?= e(\App\Core\Support\Html::js($filters ?? new stdClass())) ?>">

    <div class="data-table__toolbar">
        <?php if (($searchable ?? true) === true): ?>
            <div class="data-table__search">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <label class="visually-hidden" for="<?= e($id) ?>-search">Search this table</label>
                <input type="search" id="<?= e($id) ?>-search" data-table-search placeholder="Search…" autocomplete="off">
            </div>
        <?php endif; ?>

        <div class="data-table__filters" data-table-filters><?= $filterControls ?? '' ?></div>

        <div class="data-table__tools">
            <span class="data-table__count" data-table-count aria-live="polite"></span>
            <button type="button" class="button button--ghost button--icon" data-table-refresh aria-label="Refresh">
                <i class="fa-solid fa-rotate" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <div class="data-table__scroll">
        <table class="table">
            <thead>
                <tr>
                    <?php foreach ($columnSpec as $column): ?>
                        <th scope="col" class="<?= e($column['class']) ?><?= $column['sortable'] ? ' is-sortable' : '' ?>"
                            <?= $column['sortable'] ? 'data-table-sort="' . e($column['key']) . '" tabindex="0" role="button"' : '' ?>>
                            <?= e($column['label']) ?>
                            <?php if ($column['sortable']): ?>
                                <i class="fa-solid fa-sort table__sort-icon" aria-hidden="true"></i>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody data-table-body>
                <tr class="table__placeholder">
                    <td colspan="<?= count($columnSpec) ?>">Loading…</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="data-table__footer">
        <span class="data-table__summary" data-table-summary></span>
        <nav class="pagination" data-table-pagination aria-label="Pagination"></nav>
    </div>
</div>
