/**
 * Page behaviours.
 *
 * The modules above are general; this file is where each screen's specific
 * wiring lives — the dashboard poller, the live feed, the administrative
 * actions and the lookups.
 *
 * @package VAMS
 * @version 1.0.0
 */
(function (VAMS) {
    'use strict';

    var dom = VAMS.dom;
    var format = VAMS.format;
    var ui = VAMS.ui;
    var http = VAMS.http;

    // -----------------------------------------------------------------
    // Dashboard
    // -----------------------------------------------------------------

    VAMS.module('dashboard', function () {
        var dashboard = dom.one('[data-dashboard]');

        if (!dashboard) {
            return;
        }

        var feed = dom.one('[data-activity-feed]', dashboard);
        var pulse = dom.one('[data-live-pulse]', dashboard);
        var endpoint = dashboard.getAttribute('data-poll-endpoint');

        function paintCards(cards) {
            Object.keys(cards || {}).forEach(function (key) {
                dom.all('[data-bind="card.' + key + '"]').forEach(function (node) {
                    var value = String(cards[key].value);

                    if (node.textContent !== value) {
                        node.textContent = value;
                    }
                });
            });
        }

        function paintDevices(devices) {
            (devices || []).forEach(function (device) {
                var item = dom.one('[data-device-id="' + device.device_id + '"]');

                if (!item) {
                    return;
                }

                var dot = item.querySelector('.device-list__dot');

                if (dot) {
                    dot.className = 'device-list__dot device-list__dot--' + device.connectivity;
                }
            });
        }

        function poll() {
            var since = feed ? Number(feed.getAttribute('data-since')) || 0 : 0;

            http.get(endpoint, { since_id: since })
                .then(function (response) {
                    var data = response.data || {};

                    paintCards(data.cards);
                    paintDevices(data.devices);

                    if (feed && Array.isArray(data.activity) && data.activity.length) {
                        VAMS.feed.prepend(feed, data.activity);
                    }

                    if (pulse) {
                        pulse.classList.remove('is-paused');
                    }
                })
                .catch(function () {
                    if (pulse) {
                        pulse.classList.add('is-paused');
                    }
                });
        }

        var poller = VAMS.poller(poll, Number(dashboard.getAttribute('data-refresh')) || 15);

        dom.on('click', '[data-dashboard-refresh]', function () { poll(); });

        dom.on('change', '[data-dashboard-autorefresh]', function (event, toggle) {
            if (toggle.checked) {
                poller.start();

                return;
            }

            poller.stop();

            if (pulse) {
                pulse.classList.add('is-paused');
            }
        });

        poller.start();
    });

    // -----------------------------------------------------------------
    // Live feed
    // -----------------------------------------------------------------

    /**
     * Rows added by the poller must look exactly like the ones the server
     * rendered, so the markup is built to match partials/activity-row.php.
     */
    VAMS.feed = {
        row: function (movement) {
            var inside = String(movement.status) === 'inside';
            var visitor = Number(movement.is_visitor) === 1;
            var time = inside ? movement.entry_time : (movement.exit_time || movement.entry_time);
            var who = visitor ? (movement.visitor_name || 'Visitor') : (movement.owner_name || '—');
            var station = inside
                ? (movement.entry_device_name || '')
                : (movement.exit_device_name || movement.entry_device_name || '');

            var item = dom.create('li', {
                class: 'feed__item is-new',
                'data-access-log-id': String(movement.access_log_id)
            });

            item.innerHTML =
                '<span class="feed__direction feed__direction--' + (inside ? 'in' : 'out') + '" aria-hidden="true">'
                + '<i class="fa-solid ' + (inside ? 'fa-right-to-bracket' : 'fa-right-from-bracket') + '"></i></span>'
                + '<span class="feed__body">'
                + '<span class="feed__headline">'
                + '<a class="feed__plate" href="' + format.escape(VAMS.url('/monitoring/' + movement.access_log_id)) + '">'
                + format.escape(movement.plate_number || 'Unknown') + '</a>'
                + (visitor ? '<span class="badge badge--info">Visitor</span>' : '')
                + '</span>'
                + '<span class="feed__meta">' + format.escape(who)
                + ' <span aria-hidden="true">·</span> ' + format.escape(movement.vehicle_type || 'Unclassified')
                + ' <span aria-hidden="true">·</span> ' + format.escape(station)
                + '</span></span>'
                + '<span class="feed__time">'
                + '<time data-relative-time="' + format.escape(time) + '">' + format.escape(format.relative(time)) + '</time>'
                + '<span class="badge badge--' + (inside ? 'success' : 'neutral') + '">'
                + format.escape(format.label(movement.status)) + '</span>'
                + '</span>';

            return item;
        },

        /**
         * Add new movements to the top of a feed.
         *
         * The list is capped so a screen left open for a shift does not grow
         * until the browser struggles, and the highest identifier is recorded
         * so the next poll asks only for what came after it.
         */
        prepend: function (feed, movements) {
            var highest = Number(feed.getAttribute('data-since')) || 0;
            var empty = feed.parentNode ? feed.parentNode.querySelector('.empty-state') : null;

            movements.slice().reverse().forEach(function (movement) {
                var id = Number(movement.access_log_id);

                if (id <= highest) {
                    return;
                }

                highest = Math.max(highest, id);
                feed.insertBefore(VAMS.feed.row(movement), feed.firstChild);
            });

            feed.setAttribute('data-since', String(highest));

            while (feed.children.length > 100) {
                feed.removeChild(feed.lastChild);
            }

            if (empty && feed.children.length) {
                empty.parentNode.removeChild(empty);
            }
        }
    };

    VAMS.module('live', function () {
        var monitor = dom.one('[data-live-monitor]');

        if (!monitor) {
            return;
        }

        var feed = dom.one('[data-activity-feed]', monitor);
        var pulse = dom.one('[data-live-pulse]', monitor);
        var status = dom.one('[data-live-status]', monitor);
        var soundToggle = dom.one('[data-live-sound]');

        /**
         * A short tone on a new movement, generated rather than loaded so the
         * feature needs no audio file and no network round trip.
         */
        function chime() {
            if (!soundToggle || !soundToggle.checked) {
                return;
            }

            var AudioContextClass = window.AudioContext || window.webkitAudioContext;

            if (!AudioContextClass) {
                return;
            }

            try {
                var context = new AudioContextClass();
                var oscillator = context.createOscillator();
                var gain = context.createGain();

                oscillator.type = 'sine';
                oscillator.frequency.value = 880;
                gain.gain.setValueAtTime(.0001, context.currentTime);
                gain.gain.exponentialRampToValueAtTime(.15, context.currentTime + .01);
                gain.gain.exponentialRampToValueAtTime(.0001, context.currentTime + .25);

                oscillator.connect(gain);
                gain.connect(context.destination);
                oscillator.start();
                oscillator.stop(context.currentTime + .26);

                oscillator.onended = function () { context.close(); };
            } catch (error) {
                // Audio is a convenience; a browser that refuses it changes
                // nothing about the monitoring itself.
            }
        }

        function poll() {
            http.get(monitor.getAttribute('data-endpoint'), {
                since_id: Number(feed.getAttribute('data-since')) || 0,
                limit: 25
            })
                .then(function (response) {
                    var movements = response.data || [];

                    if (movements.length) {
                        VAMS.feed.prepend(feed, movements);
                        chime();
                    }

                    if (pulse) { pulse.classList.remove('is-paused'); }
                    if (status) { status.textContent = 'Connected'; }
                })
                .catch(function () {
                    if (pulse) { pulse.classList.add('is-paused'); }
                    if (status) { status.textContent = 'Reconnecting…'; }
                });
        }

        var poller = VAMS.poller(poll, Number(monitor.getAttribute('data-interval')) || 5);

        dom.on('change', '[data-live-toggle]', function (event, toggle) {
            if (toggle.checked) {
                poller.start();
                if (status) { status.textContent = 'Connected'; }

                return;
            }

            poller.stop();

            if (pulse) { pulse.classList.add('is-paused'); }
            if (status) { status.textContent = 'Paused'; }
        });

        poller.start();

        // The summary figures on the live page come from the dashboard poll.
        VAMS.poller(function () {
            http.get('/api/v1/dashboard/cards')
                .then(function (response) {
                    Object.keys(response.data || {}).forEach(function (key) {
                        dom.all('[data-bind="card.' + key + '"]').forEach(function (node) {
                            node.textContent = String(response.data[key].value);
                        });
                    });
                })
                .catch(function () {});
        }, 15).start();
    });

    // -----------------------------------------------------------------
    // Global search
    // -----------------------------------------------------------------

    VAMS.module('search', function () {
        var form = dom.one('[data-quick-search]');

        if (!form) {
            return;
        }

        var input = form.querySelector('input[name="q"]');
        var panel = dom.one('[data-search-suggestions]', form);

        if (!input || !panel) {
            return;
        }

        function hide() {
            panel.hidden = true;
            dom.empty(panel);
        }

        input.addEventListener('input', VAMS.debounce(function () {
            var term = input.value.trim();

            if (term.length < 2) {
                hide();

                return;
            }

            http.get('/api/v1/search/quick', { q: term, limit: 8 })
                .then(function (response) {
                    var results = response.data || [];

                    if (!results.length) {
                        panel.innerHTML = '<p class="dropdown__empty">Nothing matched.</p>';
                        panel.hidden = false;

                        return;
                    }

                    panel.innerHTML = results.map(function (result) {
                        return '<a class="topbar__suggestion" href="' + format.escape(VAMS.url(result.link || '/')) + '">'
                            + '<i class="fa-solid ' + format.escape(result.icon || 'fa-file') + '" aria-hidden="true"></i>'
                            + '<span class="topbar__suggestion-body">'
                            + '<span class="topbar__suggestion-title">' + format.escape(result.title) + '</span>'
                            + '<span class="topbar__suggestion-meta">' + format.escape(result.module_label)
                            + (result.subtitle ? ' · ' + format.escape(result.subtitle) : '') + '</span>'
                            + '</span></a>';
                    }).join('');

                    panel.hidden = false;
                })
                .catch(hide);
        }, 250));

        input.addEventListener('blur', function () {
            // Delayed so a click on a suggestion registers before the panel
            // disappears out from under the pointer.
            window.setTimeout(hide, 200);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === '/' && document.activeElement !== input
                && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) {
                event.preventDefault();
                input.focus();
            }
        });
    });

    // -----------------------------------------------------------------
    // Administrative actions
    // -----------------------------------------------------------------

    /**
     * Ask, then call, then report.
     *
     * Used by every one-click administrative action so that confirmation,
     * error handling and the follow-up reload are consistent.
     */
    function act(options) {
        return ui.confirm({
            title: options.title,
            text: options.text,
            confirmText: options.confirmText || 'Continue',
            tone: options.tone || 'warning'
        }).then(function (confirmed) {
            if (!confirmed) {
                return null;
            }

            return http.request(options.method || 'POST', options.endpoint, options.body || {})
                .then(function (response) {
                    if (options.reveal) {
                        return ui.reveal(options.reveal.title, (response.data || {})[options.reveal.field], options.reveal.note)
                            .then(function () { return response; });
                    }

                    return response;
                })
                .then(function (response) {
                    ui.toast(response.message || 'Done.', 'success');

                    if (options.reload) {
                        window.location.reload();

                        return response;
                    }

                    VAMS.table.reload();

                    return response;
                })
                .catch(function (error) {
                    ui.toast(error.message || 'That could not be completed.', 'error');
                });
        });
    }

    VAMS.act = act;

    VAMS.module('actions', function () {
        // --- Devices ---
        dom.on('click', '[data-rotate-key]', function (event, button) {
            var id = button.getAttribute('data-rotate-key');

            act({
                title: 'Issue a new API key?',
                text: 'The current key stops working immediately. ' + (button.getAttribute('data-device-name') || 'This station')
                    + ' will be refused until it is reflashed with the new one.',
                confirmText: 'Rotate the key',
                endpoint: '/api/v1/devices/' + encodeURIComponent(id) + '/rotate-key',
                reveal: {
                    title: 'The new API key',
                    field: 'api_key',
                    note: 'Copy it now — it is stored only as a hash and cannot be shown again.'
                }
            });
        });

        dom.on('click', '[data-suspend-device]', function (event, button) {
            var id = button.getAttribute('data-suspend-device');

            ui.prompt({
                title: 'Suspend ' + (button.getAttribute('data-device-name') || 'this station'),
                text: 'Every call from it will be refused. Give a reason and the number of minutes, e.g. "60 | faulty reader".',
                placeholder: '60 | faulty reader',
                confirmText: 'Suspend'
            }).then(function (answer) {
                if (answer === null) {
                    return;
                }

                var parts = answer.split('|');
                var minutes = parseInt(parts[0], 10);
                var reason = (parts[1] || '').trim();

                if (!minutes || minutes < 1 || reason.length < 5) {
                    ui.toast('Give the minutes and a reason of at least five characters.', 'error');

                    return;
                }

                http.post('/api/v1/devices/' + encodeURIComponent(id) + '/suspend', { minutes: minutes, reason: reason })
                    .then(function (response) {
                        ui.toast(response.message, 'success');
                        window.location.reload();
                    })
                    .catch(function (error) { ui.toast(error.message, 'error'); });
            });
        });

        dom.on('click', '[data-reinstate-device]', function (event, button) {
            act({
                title: 'Reinstate this station?',
                text: 'It will be able to record movements again.',
                confirmText: 'Reinstate',
                tone: 'question',
                endpoint: '/api/v1/devices/' + encodeURIComponent(button.getAttribute('data-reinstate-device')) + '/reinstate',
                reload: true
            });
        });

        // --- Users ---
        dom.on('click', '[data-lock-user]', function (event, button) {
            ui.prompt({
                title: 'Lock this account',
                text: 'The account will not be able to sign in. Give a reason.',
                confirmText: 'Lock'
            }).then(function (reason) {
                if (reason === null) {
                    return;
                }

                http.post('/api/v1/users/' + encodeURIComponent(button.getAttribute('data-lock-user')) + '/lock', { reason: reason })
                    .then(function (response) {
                        ui.toast(response.message, 'success');
                        window.location.reload();
                    })
                    .catch(function (error) { ui.toast(error.message, 'error'); });
            });
        });

        dom.on('click', '[data-unlock-user]', function (event, button) {
            act({
                title: 'Unlock this account?',
                text: 'The account will be able to sign in again.',
                confirmText: 'Unlock',
                tone: 'question',
                endpoint: '/api/v1/users/' + encodeURIComponent(button.getAttribute('data-unlock-user')) + '/unlock',
                reload: true
            });
        });

        dom.on('click', '[data-reset-password]', function (event, button) {
            act({
                title: 'Reset this password?',
                text: 'A new password is generated and every other session for this account is signed out. '
                    + 'The account must change it at first sign-in.',
                confirmText: 'Reset it',
                endpoint: '/api/v1/users/' + encodeURIComponent(button.getAttribute('data-reset-password')) + '/reset-password',
                reveal: {
                    title: 'The new password',
                    field: 'password',
                    note: 'Give it to the account holder now — it cannot be shown again.'
                }
            });
        });

        dom.on('click', '[data-terminate-session]', function (event, button) {
            act({
                title: 'Sign this session out?',
                text: 'The person using it will be signed out immediately.',
                confirmText: 'Sign out',
                method: 'DELETE',
                endpoint: '/api/v1/users/' + encodeURIComponent(button.getAttribute('data-user-id'))
                    + '/sessions/' + encodeURIComponent(button.getAttribute('data-terminate-session')),
                reload: true
            });
        });

        // --- Roles ---
        dom.on('click', '[data-save-permissions]', function (event, button) {
            var matrix = dom.one('[data-permission-matrix]');

            if (!matrix) {
                return;
            }

            var keys = dom.all('input[type="checkbox"]:checked', matrix).map(function (box) {
                return box.value;
            });

            http.put('/api/v1/roles/' + encodeURIComponent(button.getAttribute('data-save-permissions')) + '/permissions',
                { permissions: keys })
                .then(function (response) {
                    ui.toast(response.message || 'Permissions saved.', 'success');
                })
                .catch(function (error) { ui.toast(error.message, 'error'); });
        });

        dom.on('click', '[data-toggle-group]', function (event, button) {
            var group = dom.one('[data-group="' + button.getAttribute('data-toggle-group') + '"]');

            if (!group) {
                return;
            }

            var boxes = dom.all('input[type="checkbox"]:not(:disabled)', group);
            var target = boxes.some(function (box) { return !box.checked; });

            boxes.forEach(function (box) { box.checked = target; });
        });

        dom.on('click', '[data-duplicate-role]', function (event, button) {
            ui.prompt({
                title: 'Duplicate "' + button.getAttribute('data-role-name') + '"',
                text: 'The new role starts with the same permissions.',
                placeholder: 'Name for the new role',
                confirmText: 'Duplicate'
            }).then(function (name) {
                if (name === null) {
                    return;
                }

                http.post('/api/v1/roles/' + encodeURIComponent(button.getAttribute('data-duplicate-role')) + '/duplicate',
                    { role_name: name })
                    .then(function (response) {
                        ui.toast(response.message, 'success');
                        window.location.reload();
                    })
                    .catch(function (error) { ui.toast(error.message, 'error'); });
            });
        });

        dom.on('click', '[data-delete-role]', function (event, button) {
            var members = Number(button.getAttribute('data-member-count')) || 0;

            act({
                title: 'Remove "' + button.getAttribute('data-role-name') + '"?',
                text: members > 0
                    ? members + ' account(s) hold this role and must be reassigned first.'
                    : 'Nobody holds this role, so removing it affects no accounts.',
                confirmText: 'Remove',
                tone: 'warning',
                method: 'DELETE',
                endpoint: '/api/v1/roles/' + encodeURIComponent(button.getAttribute('data-delete-role')),
                reload: true
            });
        });

        // --- Visitors ---
        dom.on('click', '[data-revoke-pass]', function (event, button) {
            ui.prompt({
                title: 'Revoke this pass',
                text: 'The card is released and the visitor may not re-enter on it. Give a reason.',
                confirmText: 'Revoke'
            }).then(function (reason) {
                if (reason === null) {
                    return;
                }

                http.post('/api/v1/visitors/passes/' + encodeURIComponent(button.getAttribute('data-revoke-pass')) + '/revoke',
                    { reason: reason })
                    .then(function (response) {
                        ui.toast(response.message, 'success');
                        window.location.reload();
                    })
                    .catch(function (error) { ui.toast(error.message, 'error'); });
            });
        });

        // --- Fingerprints ---
        dom.on('click', '[data-close-operator]', function (event, button) {
            act({
                title: 'End the duty session at this station?',
                text: 'The operator will have to sign on again with their fingerprint before the station can record movements.',
                confirmText: 'End session',
                endpoint: '/api/v1/fingerprints/operators/'
                    + encodeURIComponent(button.getAttribute('data-close-operator')) + '/close',
                reload: true
            });
        });

        // --- Security rules ---
        dom.on('click', '[data-edit-rule]', function (event, button) {
            var rule;

            try {
                rule = JSON.parse(button.getAttribute('data-rule') || '{}');
            } catch (error) {
                return;
            }

            var name = dom.one('[data-rule-name]');

            if (name) {
                name.textContent = (rule.rule_name || '') + ' — ' + (rule.description || '');
            }

            VAMS.forms.fill('rule-form', {
                id: rule.security_rule_id,
                threshold_value: rule.threshold_value,
                window_seconds: rule.window_seconds,
                action: rule.action,
                severity: rule.severity,
                is_enabled: rule.is_enabled
            });
        });

        // --- Settings ---
        dom.on('click', '[data-settings-save]', function (event, button) {
            var form = dom.one('[data-settings-form]');

            if (!form) {
                return;
            }

            button.disabled = true;

            http.put(form.getAttribute('data-endpoint'), { settings: collectSettings(form) })
                .then(function (response) {
                    var changed = Object.keys((response.data || {}).changed || {}).length;

                    ui.toast(
                        changed === 0 ? 'No settings needed changing.' : changed + ' setting(s) saved.',
                        'success'
                    );
                })
                .catch(function (error) {
                    if (error.details && Object.keys(error.details).length) {
                        Object.keys(error.details).forEach(function (key) {
                            var messages = error.details[key];

                            ui.toast(Array.isArray(messages) ? messages[0] : String(messages), 'error');
                        });

                        return;
                    }

                    ui.toast(error.message, 'error');
                })
                .finally(function () { button.disabled = false; });
        });

        /**
         * Read the settings form into a flat map.
         *
         * The controls are named settings[key], which FormData flattens into
         * literal "settings[key]" entries rather than a nested object.
         */
        function collectSettings(form) {
            var values = {};

            dom.all('[name^="settings["]', form).forEach(function (control) {
                var match = control.name.match(/^settings\[(.+)\]$/);

                if (!match) {
                    return;
                }

                if (control.type === 'checkbox') {
                    values[match[1]] = control.checked ? '1' : '0';

                    return;
                }

                // An untouched sensitive field submits nothing, which means
                // "keep the stored value" rather than "set it to empty".
                if (control.type === 'password' && control.value === '') {
                    return;
                }

                values[match[1]] = control.value;
            });

            return values;
        }

        dom.on('click', '[data-reset-setting]', function (event, button) {
            act({
                title: 'Restore this setting to its default?',
                confirmText: 'Restore',
                endpoint: '/api/v1/settings/' + encodeURIComponent(button.getAttribute('data-reset-setting')) + '/reset',
                reload: true
            });
        });

        // --- Backups ---
        dom.on('click', '[data-reconcile-backups]', function (event, button) {
            http.post(button.getAttribute('data-endpoint'))
                .then(function (response) {
                    var data = response.data || {};
                    var missing = (data.missing_files || []).length;
                    var orphaned = (data.orphaned_files || []).length;

                    ui.toast(
                        missing === 0 && orphaned === 0
                            ? 'The register matches what is on disk.'
                            : missing + ' missing, ' + orphaned + ' unrecorded file(s).',
                        missing === 0 && orphaned === 0 ? 'success' : 'warning'
                    );
                })
                .catch(function (error) { ui.toast(error.message, 'error'); });
        });

        // --- Lookups ---
        var lookup = dom.one('[data-uid-lookup]');

        if (lookup) {
            var uidInput = dom.one('[data-uid-input]', lookup);
            var uidResult = dom.one('[data-uid-result]', lookup);

            uidInput.addEventListener('input', VAMS.debounce(function () {
                var uid = uidInput.value.trim();

                if (uid.length < 8) {
                    uidResult.innerHTML = '';

                    return;
                }

                http.get(lookup.getAttribute('data-endpoint'), { uid: uid })
                    .then(function (response) {
                        var data = response.data || {};

                        if (!data.known) {
                            uidResult.innerHTML = '<strong>Not registered.</strong> '
                                + 'This UID is free to add as a tag or a card.';

                            return;
                        }

                        var record = data.tag || data.card || {};

                        uidResult.innerHTML = '<strong>' + format.escape(format.label(data.kind)) + ' found.</strong> '
                            + format.escape(record.tag_code || record.card_code || '')
                            + ' — status ' + format.escape(record.status || 'unknown')
                            + (record.plate_number ? ', fitted to ' + format.escape(record.plate_number) : '');
                    })
                    .catch(function (error) {
                        uidResult.innerHTML = format.escape(error.message);
                    });
            }, 350));
        }

        var reference = dom.one('[data-reference-lookup]');

        if (reference) {
            var refInput = dom.one('[data-reference-input]', reference);
            var refResult = dom.one('[data-reference-result]', reference);

            refInput.addEventListener('input', VAMS.debounce(function () {
                var value = refInput.value.trim();

                if (value.length < 6) {
                    refResult.innerHTML = '';

                    return;
                }

                http.get(reference.getAttribute('data-endpoint') + encodeURIComponent(value))
                    .then(function (response) {
                        var error = response.data || {};

                        refResult.innerHTML = '<strong>' + format.escape(error.severity || '') + '</strong> — '
                            + format.escape(error.message || '')
                            + '<br><span class="table__mono">' + format.escape(error.module || '') + '</span>'
                            + '<br>Seen ' + format.escape(String(error.occurrence_count || 1)) + ' time(s), last '
                            + format.escape(format.datetime(error.last_seen_at));
                    })
                    .catch(function (error) {
                        refResult.innerHTML = format.escape(error.message);
                    });
            }, 350));
        }

        // --- Active sessions panel ---
        var sessionsPanel = dom.one('[data-sessions-panel]');

        if (sessionsPanel) {
            var sessionsModal = sessionsPanel.closest('.modal');

            if (sessionsModal) {
                sessionsModal.addEventListener('modal:open', function () {
                    var body = dom.one('[data-sessions-body]', sessionsPanel);

                    http.get(sessionsPanel.getAttribute('data-endpoint'))
                        .then(function (response) {
                            var rows = response.data || [];

                            if (!rows.length) {
                                body.innerHTML = '<tr class="table__placeholder"><td colspan="5">No sessions are open.</td></tr>';

                                return;
                            }

                            body.innerHTML = rows.map(function (row) {
                                return '<tr>'
                                    + '<td><strong>' + format.escape(row.full_name || row.username || '') + '</strong></td>'
                                    + '<td>' + format.escape(format.datetime(row.login_at)) + '</td>'
                                    + '<td>' + format.escape(format.relative(row.last_activity_at)) + '</td>'
                                    + '<td class="table__mono">' + format.escape(row.ip_address || '—') + '</td>'
                                    + '<td class="table__actions">'
                                    + '<button type="button" class="button button--sm button--ghost"'
                                    + ' data-terminate-session="' + format.escape(row.user_session_id) + '"'
                                    + ' data-user-id="' + format.escape(row.user_id) + '">Sign out</button>'
                                    + '</td></tr>';
                            }).join('');
                        })
                        .catch(function (error) {
                            body.innerHTML = '<tr class="table__placeholder"><td colspan="5">'
                                + format.escape(error.message) + '</td></tr>';
                        });
                });
            }
        }
    });

    // -----------------------------------------------------------------
    // Session timeout
    // -----------------------------------------------------------------

    VAMS.module('session', function () {
        if (!VAMS.config.user) {
            return;
        }

        var lifetime = Number(VAMS.config.sessionTimeout) || 1800;
        var warnAt = Number(VAMS.config.idleWarning) || 120;
        var lastActivity = Date.now();
        var warned = false;

        ['click', 'keydown', 'scroll', 'mousemove'].forEach(function (type) {
            document.addEventListener(type, VAMS.debounce(function () {
                lastActivity = Date.now();
                warned = false;
            }, 2000), { passive: true });
        });

        window.setInterval(function () {
            var idle = (Date.now() - lastActivity) / 1000;

            if (idle < lifetime - warnAt || warned) {
                return;
            }

            warned = true;

            ui.confirm({
                title: 'Are you still there?',
                text: 'Your session will end shortly for security. Continue working?',
                confirmText: 'Keep me signed in',
                cancelText: 'Sign out now',
                tone: 'question'
            }).then(function (stay) {
                if (!stay) {
                    window.location.href = VAMS.url('/login');

                    return;
                }

                http.post('/api/v1/session/extend')
                    .then(function () {
                        lastActivity = Date.now();
                        warned = false;
                    })
                    .catch(function () {
                        window.location.href = VAMS.url('/login?timeout=1');
                    });
            });
        }, 20000);

        // A system-status indicator in the sidebar, so an operator sees that
        // the server is reachable without opening the health page.
        var indicator = dom.one('[data-system-status]');

        if (indicator) {
            var text = dom.one('[data-system-status-text]', indicator);

            VAMS.poller(function () {
                http.get('/api/v1/dashboard/health', null, { redirectOnExpiry: false })
                    .then(function (response) {
                        var state = (response.data || {}).status === 'ok' ? 'ok' : 'degraded';

                        indicator.className = 'sidebar__status is-' + state;

                        if (text) {
                            text.textContent = state === 'ok' ? 'System healthy' : 'Degraded';
                        }
                    })
                    .catch(function () {
                        indicator.className = 'sidebar__status is-down';

                        if (text) {
                            text.textContent = 'Unreachable';
                        }
                    });
            }, 60).start(true);
        }
    });
}(window.VAMS));
