/**
 * The data table.
 *
 * One implementation drives every listing in the system. It reads its
 * endpoint, columns and options from data attributes written by the
 * data-table component, so a new listing is a PHP call rather than another
 * copy of this file.
 *
 * DataTables is not used: the server already paginates, sorts and filters, and
 * a client-side table library would either fight that or duplicate it.
 *
 * @package VAMS
 * @version 1.0.0
 */
(function (VAMS) {
    'use strict';

    var dom = VAMS.dom;
    var format = VAMS.format;

    /** @type {Object<string, Table>} Live instances, keyed by element id. */
    var registry = {};

    /**
     * Render one cell according to its declared format.
     *
     * Every branch escapes; a plate number or a device name is user-supplied
     * data even though it came back from our own API.
     */
    function renderCell(value, column, row) {
        if (value === null || value === undefined || value === '') {
            return '<span class="table__empty">' + format.escape(column.empty || '—') + '</span>';
        }

        switch (column.format) {
            case 'strong':
                return '<strong>' + format.escape(value) + '</strong>';

            case 'datetime':
                return '<time datetime="' + format.escape(value) + '" title="' + format.escape(value) + '">'
                    + format.escape(format.datetime(value)) + '</time>';

            case 'date':
                return format.escape(format.date(value));

            case 'time':
                return format.escape(format.time(value));

            case 'relative':
                return '<time title="' + format.escape(value) + '">' + format.escape(format.relative(value)) + '</time>';

            case 'duration':
                return '<span class="table__number">' + format.escape(format.duration(value)) + '</span>';

            case 'milliseconds':
                return '<span class="table__number">' + format.escape(format.milliseconds(value)) + '</span>';

            case 'bytes':
                return '<span class="table__number">' + format.escape(format.bytes(value)) + '</span>';

            case 'number':
                return '<span class="table__number">' + format.escape(format.number(value)) + '</span>';

            case 'boolean':
                return Number(value) === 1 || value === true || value === 'yes'
                    ? '<span class="badge badge--success">Yes</span>'
                    : '<span class="badge badge--neutral">No</span>';

            case 'badge':
                return badge(String(value));

            case 'status-code':
                return statusCode(value);

            default:
                return format.escape(value);
        }
    }

    /** Status pills use the same vocabulary as the PHP badge component. */
    function badge(value) {
        var tones = {
            active: 'success', inside: 'success', granted: 'success', assigned: 'success',
            online: 'success', healthy: 'success', ok: 'success', resolved: 'success',
            available: 'info', issued: 'info', acknowledged: 'info', low: 'info',
            completed: 'neutral', outside: 'neutral', returned: 'neutral', inactive: 'neutral',
            archived: 'neutral', unknown: 'neutral', normal: 'neutral', dismissed: 'neutral',
            pending_sync: 'warning', maintenance: 'warning', expiring: 'warning', overdue: 'warning',
            expired: 'warning', degraded: 'warning', medium: 'warning', new: 'warning',
            investigating: 'warning', never_seen: 'warning',
            suspended: 'danger', revoked: 'danger', lost: 'danger', damaged: 'danger',
            locked: 'danger', denied: 'danger', decommissioned: 'danger', offline: 'danger',
            failed: 'danger', unhealthy: 'danger', critical: 'danger', high: 'danger',
            forced_closed: 'warning'
        };

        var key = String(value).toLowerCase();

        return '<span class="badge badge--' + (tones[key] || 'neutral') + '">'
            + format.escape(format.label(value)) + '</span>';
    }

    function statusCode(value) {
        var code = Number(value);
        var tone = code >= 500 ? 'bad' : (code >= 400 ? 'warn' : 'ok');

        return '<span class="status-code status-code--' + tone + '">' + format.escape(value) + '</span>';
    }

    /** Substitute {column} placeholders in a row-link template. */
    function expand(template, row) {
        return template.replace(/\{(\w+)\}/g, function (match, key) {
            return row[key] === undefined || row[key] === null ? '' : encodeURIComponent(String(row[key]));
        });
    }

    /**
     * A single table instance.
     *
     * @param {Element} element
     */
    function Table(element) {
        this.element = element;
        this.endpoint = element.getAttribute('data-endpoint');
        this.rowLink = element.getAttribute('data-row-link') || '';
        this.emptyMessage = element.getAttribute('data-empty') || 'Nothing to show yet.';

        this.columns = this.parse(element.getAttribute('data-columns'), []);
        this.fixedFilters = this.parse(element.getAttribute('data-fixed-filters'), {});

        this.state = {
            page: 1,
            perPage: 25,
            sort: element.getAttribute('data-sort') || '',
            direction: element.getAttribute('data-direction') || 'DESC',
            search: '',
            filters: {}
        };

        this.body = dom.one('[data-table-body]', element);
        this.request = null;

        this.bind();
    }

    Table.prototype.parse = function (json, fallback) {
        try {
            return JSON.parse(json || '');
        } catch (error) {
            return fallback;
        }
    };

    Table.prototype.bind = function () {
        var table = this;

        var search = dom.one('[data-table-search]', this.element);

        if (search) {
            search.addEventListener('input', VAMS.debounce(function () {
                table.state.search = search.value.trim();
                table.state.page = 1;
                table.load();
            }, 300));
        }

        dom.all('[data-filter]', this.element).forEach(function (control) {
            control.addEventListener('change', function () {
                var name = control.getAttribute('data-filter');

                table.state.filters[name] = control.type === 'checkbox'
                    ? (control.checked ? control.value || '1' : '')
                    : control.value;

                table.state.page = 1;
                table.load();
            });
        });

        dom.all('[data-table-sort]', this.element).forEach(function (header) {
            var activate = function () {
                var column = header.getAttribute('data-table-sort');

                if (table.state.sort === column) {
                    table.state.direction = table.state.direction === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    table.state.sort = column;
                    table.state.direction = 'DESC';
                }

                table.state.page = 1;
                table.load();
            };

            header.addEventListener('click', activate);
            header.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    activate();
                }
            });
        });

        var refresh = dom.one('[data-table-refresh]', this.element);

        if (refresh) {
            refresh.addEventListener('click', function () { table.load(); });
        }

        var reset = dom.one('[data-table-reset]', this.element);

        if (reset) {
            reset.addEventListener('click', function () { table.reset(); });
        }

        dom.on('click', '[data-page]', function (event, button) {
            event.preventDefault();
            table.state.page = Number(button.getAttribute('data-page'));
            table.load();
        }, this.element);
    };

    Table.prototype.reset = function () {
        this.state.search = '';
        this.state.filters = {};
        this.state.page = 1;

        var search = dom.one('[data-table-search]', this.element);

        if (search) {
            search.value = '';
        }

        dom.all('[data-filter]', this.element).forEach(function (control) {
            if (control.type === 'checkbox') {
                control.checked = false;

                return;
            }

            control.value = '';
        });

        this.load();
    };

    Table.prototype.query = function () {
        var query = Object.assign({}, this.fixedFilters, this.state.filters, {
            page: this.state.page,
            per_page: this.state.perPage,
            search: this.state.search
        });

        if (this.state.sort) {
            query.sort = this.state.sort;
            query.direction = this.state.direction;
        }

        return query;
    };

    Table.prototype.load = function () {
        var table = this;

        // A new request supersedes one still in flight; without this a slow
        // response can arrive after a faster later one and overwrite it.
        if (this.request) {
            this.request.abort();
        }

        var controller = new AbortController();

        this.request = controller;
        this.element.classList.add('is-loading');

        VAMS.http.get(this.endpoint, this.query(), { signal: controller.signal })
            .then(function (response) {
                table.request = null;
                table.element.classList.remove('is-loading');
                table.render(response.data || [], response.pagination);
                table.paintSortIndicators();

                table.element.dispatchEvent(new CustomEvent('table:loaded', {
                    bubbles: true,
                    detail: { rows: response.data || [], pagination: response.pagination }
                }));
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }

                table.request = null;
                table.element.classList.remove('is-loading');
                table.renderMessage(error.message || 'This list could not be loaded.');
            });
    };

    Table.prototype.render = function (rows, pagination) {
        var table = this;

        dom.empty(this.body);

        if (!rows.length) {
            this.renderMessage(this.emptyMessage);
            this.renderPagination(pagination);

            return;
        }

        var html = rows.map(function (row) {
            var cells = table.columns.map(function (column) {
                return '<td class="' + format.escape(column.class || '') + '">'
                    + renderCell(row[column.key], column, row) + '</td>';
            }).join('');

            var attributes = table.rowLink ? ' data-href="' + format.escape(expand(table.rowLink, row)) + '"' : '';

            return '<tr' + attributes + '>' + cells + '</tr>';
        }).join('');

        this.body.innerHTML = html;

        this.renderPagination(pagination);
        VAMS.ui.refreshTimes();
    };

    Table.prototype.renderMessage = function (message) {
        this.body.innerHTML = '<tr class="table__placeholder"><td colspan="' + this.columns.length + '">'
            + format.escape(message) + '</td></tr>';
    };

    Table.prototype.renderPagination = function (pagination) {
        var nav = dom.one('[data-table-pagination]', this.element);
        var summary = dom.one('[data-table-summary]', this.element);
        var count = dom.one('[data-table-count]', this.element);

        if (!pagination) {
            if (nav) { dom.empty(nav); }
            if (summary) { summary.textContent = ''; }

            return;
        }

        if (summary) {
            summary.textContent = pagination.total === 0
                ? 'No records'
                : 'Showing ' + format.number(pagination.from) + '–' + format.number(pagination.to)
                    + ' of ' + format.number(pagination.total);
        }

        if (count) {
            count.textContent = format.number(pagination.total) + ' record'
                + (Number(pagination.total) === 1 ? '' : 's');
        }

        if (!nav) {
            return;
        }

        if (pagination.last_page <= 1) {
            dom.empty(nav);

            return;
        }

        var buttons = [];

        buttons.push(pageButton('\u2039', pagination.current_page - 1, !pagination.has_previous, false, 'Previous page'));

        /*
         * The server decides which page numbers are worth offering and marks
         * the elisions with null, so the same window appears here and in any
         * other client.
         */
        var pages = pagination.window && pagination.window.length
            ? pagination.window
            : [pagination.current_page];

        pages.forEach(function (page) {
            if (page === null) {
                buttons.push('<span class="pagination__gap">\u2026</span>');

                return;
            }

            buttons.push(pageButton(
                String(page),
                page,
                false,
                page === pagination.current_page,
                'Page ' + page
            ));
        });

        buttons.push(pageButton('\u203a', pagination.current_page + 1, !pagination.has_next, false, 'Next page'));

        nav.innerHTML = buttons.join('');
    };

    function pageButton(label, page, disabled, current, title) {
        return '<button type="button" class="pagination__button' + (current ? ' is-current' : '') + '"'
            + ' data-page="' + page + '"' + (disabled ? ' disabled' : '')
            + ' title="' + format.escape(title) + '"'
            + (current ? ' aria-current="page"' : '') + '>' + format.escape(label) + '</button>';
    }

    Table.prototype.paintSortIndicators = function () {
        var state = this.state;

        dom.all('[data-table-sort]', this.element).forEach(function (header) {
            var sorted = header.getAttribute('data-table-sort') === state.sort;
            var icon = header.querySelector('.table__sort-icon');

            header.classList.toggle('is-sorted', sorted);

            if (icon) {
                icon.className = 'fa-solid table__sort-icon fa-sort'
                    + (sorted ? (state.direction === 'ASC' ? '-up' : '-down') : '');
            }
        });
    };

    VAMS.table = {
        /** @returns {Table|null} */
        get: function (id) {
            return registry[id] || null;
        },

        /** Reload one table by id, or every table when no id is given. */
        reload: function (id) {
            if (id) {
                if (registry[id]) {
                    registry[id].load();
                }

                return;
            }

            Object.keys(registry).forEach(function (key) { registry[key].load(); });
        }
    };

    VAMS.module('table', function () {
        dom.all('[data-table]').forEach(function (element) {
            var table = new Table(element);

            if (element.id) {
                registry[element.id] = table;
            }

            // A table inside a hidden tab panel waits: loading it now would
            // spend a request on something nobody has asked to see.
            var panel = element.closest('[data-tab-panel]');

            if (panel && panel.hidden) {
                panel.addEventListener('panel:shown', function once() {
                    panel.removeEventListener('panel:shown', once);
                    table.load();
                });

                return;
            }

            table.load();
        });
    });
}(window.VAMS));
