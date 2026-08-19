/**
 * Charts.
 *
 * Chart.js is used when it is present. When it is not, each chart falls back
 * to a plain HTML table of the same numbers — the figures matter more than the
 * picture, and a blank rectangle where a chart should be tells the reader
 * nothing.
 *
 * @package VAMS
 * @version 1.0.0
 */
(function (VAMS) {
    'use strict';

    var dom = VAMS.dom;
    var format = VAMS.format;

    /** @type {Array<{element: Element, instance: Object, build: Function}>} */
    var charts = [];

    /** Read the current theme's colours so charts match the page. */
    function palette() {
        var styles = window.getComputedStyle(document.documentElement);

        var read = function (name, fallback) {
            var value = styles.getPropertyValue(name);

            return value && value.trim() !== '' ? value.trim() : fallback;
        };

        return {
            accent:  read('--colour-accent', '#1d6f42'),
            success: read('--colour-success', '#1d7a45'),
            warning: read('--colour-warning', '#a1650a'),
            danger:  read('--colour-danger', '#b32217'),
            info:    read('--colour-info', '#17607f'),
            neutral: read('--colour-neutral', '#5c6773'),
            ink:     read('--ink-muted', '#6b7785'),
            line:    read('--line', '#dde3e9')
        };
    }

    /** The categorical sequence, ordered so adjacent series stay distinct. */
    function series(colours) {
        return [colours.accent, colours.info, colours.warning, colours.danger, colours.success, colours.neutral];
    }

    /**
     * Reshape a payload into labels and datasets.
     *
     * Each chart name knows the shape its endpoint returns; keeping that
     * mapping here rather than in the templates means a page declares what it
     * wants to show, not how to draw it.
     */
    function shape(name, payload, colours) {
        var rows = Array.isArray(payload) ? payload : [];

        switch (name) {
            case 'hourly':
                return {
                    labels: rows.map(function (row) {
                        return String(row.hour).padStart(2, '0') + ':00';
                    }),
                    datasets: [
                        { label: 'Entries', data: rows.map(function (r) { return Number(r.entries) || 0; }), colour: colours.success },
                        { label: 'Exits', data: rows.map(function (r) { return Number(r.exits) || 0; }), colour: colours.info }
                    ]
                };

            case 'daily':
                return {
                    labels: rows.map(function (row) { return format.date(row.activity_date); }),
                    datasets: [
                        { label: 'Visits', data: rows.map(function (r) { return Number(r.total_visits ?? r.visits) || 0; }), colour: colours.accent },
                        { label: 'Visitors', data: rows.map(function (r) { return Number(r.visitor_visits) || 0; }), colour: colours.info }
                    ]
                };

            case 'by_type':
                return {
                    labels: rows.map(function (row) { return String(row.type_name || row.label || '—'); }),
                    datasets: [{
                        label: 'Vehicles',
                        data: rows.map(function (r) { return Number(r.total ?? r.vehicles) || 0; }),
                        colours: series(colours)
                    }]
                };

            case 'denials':
            case 'denial-breakdown':
                return {
                    labels: rows.map(function (row) { return format.label(row.reason_code || row.reason || '—'); }),
                    datasets: [{
                        label: 'Refusals',
                        data: rows.map(function (r) { return Number(r.total) || 0; }),
                        colour: colours.danger
                    }]
                };

            case 'security':
                return {
                    labels: rows.map(function (row) { return format.date(row.day || row.date); }),
                    datasets: [{
                        label: 'Events',
                        data: rows.map(function (r) { return Number(r.total) || 0; }),
                        colour: colours.warning
                    }]
                };

            case 'heartbeats':
                return {
                    labels: rows.map(function (row) { return format.time(row.received_at || row.bucket); }),
                    datasets: [
                        { label: 'Signal (dBm)', data: rows.map(function (r) { return Number(r.signal_strength ?? r.signal) || 0; }), colour: colours.info },
                        { label: 'Memory (%)', data: rows.map(function (r) { return Number(r.memory_usage_pct ?? r.memory) || 0; }), colour: colours.warning }
                    ]
                };

            default:
                return {
                    labels: rows.map(function (row) { return String(row.label || row.name || '—'); }),
                    datasets: [{
                        label: 'Value',
                        data: rows.map(function (r) { return Number(r.value ?? r.total) || 0; }),
                        colour: colours.accent
                    }]
                };
        }
    }

    /**
     * Draw the same numbers as an accessible table.
     *
     * Used when Chart.js is absent, and always present in the DOM behind the
     * canvas for screen readers.
     */
    function fallbackTable(container, shaped) {
        if (!shaped.labels.length) {
            container.innerHTML = '<p class="chart__fallback">No data for this period.</p>';

            return;
        }

        var maximum = 1;

        shaped.datasets.forEach(function (dataset) {
            dataset.data.forEach(function (value) {
                maximum = Math.max(maximum, Number(value) || 0);
            });
        });

        var head = '<tr><th scope="col">Period</th>'
            + shaped.datasets.map(function (dataset) {
                return '<th scope="col">' + format.escape(dataset.label) + '</th>';
            }).join('')
            + '</tr>';

        var body = shaped.labels.map(function (label, index) {
            var cells = shaped.datasets.map(function (dataset) {
                var value = Number(dataset.data[index]) || 0;
                var width = Math.round((value / maximum) * 100);

                return '<td>' + format.escape(format.number(value))
                    + '<span class="chart__bar" style="width:' + width + '%"></span></td>';
            }).join('');

            return '<tr><th scope="row">' + format.escape(label) + '</th>' + cells + '</tr>';
        }).join('');

        container.innerHTML = '<div class="table-scroll"><table class="chart__table">'
            + '<thead>' + head + '</thead><tbody>' + body + '</tbody></table></div>';
    }

    function build(container) {
        var name = container.getAttribute('data-chart');
        var type = container.getAttribute('data-chart-type') || 'bar';
        var colours = palette();

        var payload;

        try {
            payload = JSON.parse(container.getAttribute('data-chart-payload') || '[]');
        } catch (error) {
            payload = [];
        }

        var shaped = shape(name, payload, colours);

        if (!window.Chart) {
            fallbackTable(container, shaped);

            return null;
        }

        var canvas = container.querySelector('canvas');

        if (!canvas) {
            return null;
        }

        var horizontal = type === 'bar-horizontal';
        var chartType = horizontal ? 'bar' : type;

        var datasets = shaped.datasets.map(function (dataset, index) {
            var colour = dataset.colours || dataset.colour || series(colours)[index % 6];

            return {
                label: dataset.label,
                data: dataset.data,
                backgroundColor: chartType === 'line'
                    ? 'transparent'
                    : (Array.isArray(colour) ? colour : colour),
                borderColor: Array.isArray(colour) ? colours.line : colour,
                borderWidth: chartType === 'line' ? 2 : 0,
                tension: .3,
                fill: false,
                pointRadius: 2,
                borderRadius: chartType === 'bar' ? 3 : 0
            };
        });

        return new window.Chart(canvas.getContext('2d'), {
            type: chartType,
            data: { labels: shaped.labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: horizontal ? 'y' : 'x',
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: datasets.length > 1 || chartType === 'doughnut',
                        position: chartType === 'doughnut' ? 'right' : 'top',
                        labels: { color: colours.ink, boxWidth: 12, usePointStyle: true }
                    },
                    tooltip: { backgroundColor: 'rgba(15,20,25,.92)', padding: 10, cornerRadius: 6 }
                },
                scales: chartType === 'doughnut' ? {} : {
                    x: { grid: { display: false }, ticks: { color: colours.ink, maxRotation: 0, autoSkip: true } },
                    y: { beginAtZero: true, grid: { color: colours.line }, ticks: { color: colours.ink, precision: 0 } }
                }
            }
        });
    }

    function render(container) {
        var existing = charts.find(function (entry) { return entry.element === container; });

        if (existing && existing.instance) {
            existing.instance.destroy();
        }

        var instance = build(container);

        if (existing) {
            existing.instance = instance;

            return;
        }

        charts.push({ element: container, instance: instance });
    }

    VAMS.charts = {
        /** Replace a chart's data without rebuilding the page. */
        update: function (name, payload) {
            var container = dom.one('[data-chart="' + name + '"]');

            if (!container) {
                return;
            }

            container.setAttribute('data-chart-payload', JSON.stringify(payload));
            render(container);
        },

        redrawAll: function () {
            dom.all('[data-chart]').forEach(render);
        }
    };

    VAMS.module('charts', function () {
        dom.all('[data-chart]').forEach(function (container) {
            var endpoint = container.getAttribute('data-chart-endpoint');

            if (!endpoint) {
                render(container);

                return;
            }

            // A chart with an endpoint fetches its own data; the path names
            // which part of the response holds it.
            var path = container.getAttribute('data-chart-path') || '';

            VAMS.http.get(endpoint).then(function (response) {
                var data = path ? (response.data || {})[path] : response.data;

                container.setAttribute('data-chart-payload', JSON.stringify(data || []));
                render(container);
            }).catch(function () {
                container.innerHTML = '<p class="chart__fallback">This chart could not be loaded.</p>';
            });
        });

        // Redraw on a theme change so the axes and legend stay readable.
        document.addEventListener('theme:changed', function () {
            VAMS.charts.redrawAll();
        });
    });

    // Simple numeric readouts that fetch their own value.
    VAMS.module('metrics', function () {
        dom.all('[data-metric-endpoint]').forEach(function (node) {
            var path = node.getAttribute('data-metric-path') || '';
            var suffix = node.getAttribute('data-metric-suffix') || '';

            VAMS.http.get(node.getAttribute('data-metric-endpoint')).then(function (response) {
                var value = path ? (response.data || {})[path] : response.data;

                node.textContent = (value === null || value === undefined ? '—' : value) + suffix;
            }).catch(function () {
                node.textContent = '—';
            });
        });
    });
}(window.VAMS));
