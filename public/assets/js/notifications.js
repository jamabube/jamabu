/**
 * The notification bell and its dropdown.
 *
 * @package VAMS
 * @version 1.0.0
 */
(function (VAMS) {
    'use strict';

    var dom = VAMS.dom;
    var format = VAMS.format;

    VAMS.module('notifications', function () {
        var list = dom.one('[data-notification-list]');
        var counter = dom.one('[data-notification-count]');

        if (!list && !counter) {
            return;
        }

        function paintCount(unread) {
            if (!counter) {
                return;
            }

            counter.textContent = String(Math.min(99, unread));
            counter.classList.toggle('is-hidden', unread === 0);
        }

        function paintList(items) {
            if (!list) {
                return;
            }

            if (!items.length) {
                list.innerHTML = '<p class="dropdown__empty">Nothing new.</p>';

                return;
            }

            list.innerHTML = items.map(function (item) {
                var unread = Number(item.is_read) === 0;

                return '<div class="notification-item' + (unread ? ' is-unread' : '') + '"'
                    + ' data-notification-id="' + format.escape(item.notification_id) + '"'
                    + (item.link ? ' data-link="' + format.escape(item.link) + '"' : '') + '>'
                    + (unread ? '<span class="notification-item__dot" aria-hidden="true"></span>' : '')
                    + '<span class="notification-item__body">'
                    + '<span class="notification-item__title">' + format.escape(item.title) + '</span>'
                    + '<span class="notification-item__message">' + format.escape(item.message || '') + '</span>'
                    + '<span class="notification-item__time">' + format.escape(format.relative(item.created_at)) + '</span>'
                    + '</span></div>';
            }).join('');
        }

        function refresh() {
            return VAMS.http.get('/api/v1/notifications/recent', { limit: 10 })
                .then(function (response) {
                    var data = response.data || {};

                    paintCount(Number(data.unread) || 0);
                    paintList(data.items || []);
                })
                .catch(function () {
                    // A failed poll leaves the previous state on screen, which
                    // is better than blanking a list the user may be reading.
                });
        }

        // Load when the dropdown is opened rather than on every page load: a
        // guard who never opens the bell should not cost a request a minute.
        var dropdown = list ? list.closest('[data-dropdown]') : null;

        if (dropdown) {
            dropdown.addEventListener('dropdown:open', refresh);
        }

        dom.on('click', '.notification-item', function (event, item) {
            var id = item.getAttribute('data-notification-id');
            var link = item.getAttribute('data-link');

            VAMS.http.post('/api/v1/notifications/' + encodeURIComponent(id) + '/read')
                .then(function () {
                    item.classList.remove('is-unread');

                    var dot = item.querySelector('.notification-item__dot');

                    if (dot) {
                        dot.parentNode.removeChild(dot);
                    }

                    return refresh();
                })
                .then(function () {
                    if (link) {
                        window.location.href = VAMS.url(link);
                    }
                })
                .catch(function (error) {
                    VAMS.ui.toast(error.message, 'error');
                });
        });

        dom.on('click', '[data-notifications-read-all]', function (event) {
            event.preventDefault();
            event.stopPropagation();

            VAMS.http.post('/api/v1/notifications/read-all')
                .then(function (response) {
                    VAMS.ui.toast(response.message || 'All notifications were marked read.', 'success');
                    paintCount(0);

                    return refresh();
                })
                .then(function () {
                    VAMS.table.reload('notifications-table');
                })
                .catch(function (error) {
                    VAMS.ui.toast(error.message, 'error');
                });
        });

        paintCount(Number(VAMS.config.notifications.unread) || 0);

        // The count is polled even when the dropdown is shut, because the
        // badge is the only signal that something needs attention.
        var interval = Number(VAMS.config.notifications.pollInterval) || 30;

        VAMS.poller(function () {
            VAMS.http.get('/api/v1/notifications/unread-count')
                .then(function (response) {
                    paintCount(Number((response.data || {}).unread) || 0);
                })
                .catch(function () {
                    // Silent: a missing badge update is not worth a toast.
                });
        }, interval).start(true);
    });
}(window.VAMS));
