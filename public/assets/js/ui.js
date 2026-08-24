/**
 * Interface behaviour: sidebar, dropdowns, modals, tabs, toasts, dialogs and
 * the small live-text helpers.
 *
 * SweetAlert2 is used for confirmations when it is present; when it is not,
 * the native confirm() is used instead. A destructive action must always be
 * confirmable, whether or not a library loaded.
 *
 * @package VAMS
 * @version 1.0.0
 */
(function (VAMS) {
    'use strict';

    var dom = VAMS.dom;
    var format = VAMS.format;

    var ui = {
        // -------------------------------------------------------------
        // Toasts
        // -------------------------------------------------------------

        /**
         * @param {string} message
         * @param {string} tone success | error | warning | info
         */
        toast: function (message, tone) {
            var stack = dom.one('.toast-stack');

            if (!stack) {
                stack = dom.create('div', { class: 'toast-stack', 'aria-live': 'polite' });
                document.body.appendChild(stack);
            }

            var icons = {
                success: 'fa-circle-check',
                error: 'fa-circle-exclamation',
                warning: 'fa-triangle-exclamation',
                info: 'fa-circle-info'
            };

            var toast = dom.create('div', {
                class: 'toast toast--' + (tone || 'info'),
                role: (tone === 'error' ? 'alert' : 'status'),
                html: '<i class="fa-solid ' + (icons[tone] || icons.info) + '" aria-hidden="true"></i>'
                    + '<span>' + format.escape(message) + '</span>'
                    + '<button type="button" class="toast__close" aria-label="Dismiss">'
                    + '<i class="fa-solid fa-xmark" aria-hidden="true"></i></button>'
            });

            stack.appendChild(toast);

            var remove = function () {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            };

            toast.querySelector('.toast__close').addEventListener('click', remove);

            // An error stays until dismissed: it may be the only record the
            // user has of what went wrong.
            if (tone !== 'error') {
                window.setTimeout(remove, 5000);
            }
        },

        /**
         * Ask before doing something destructive.
         *
         * @returns {Promise<boolean>}
         */
        confirm: function (options) {
            var settings = Object.assign({
                title: 'Are you sure?',
                text: '',
                confirmText: 'Continue',
                cancelText: 'Cancel',
                tone: 'warning'
            }, options || {});

            if (window.Swal) {
                return window.Swal.fire({
                    title: settings.title,
                    text: settings.text,
                    icon: settings.tone,
                    showCancelButton: true,
                    confirmButtonText: settings.confirmText,
                    cancelButtonText: settings.cancelText,
                    reverseButtons: true,
                    focusCancel: true,
                    customClass: { confirmButton: 'button button--primary', cancelButton: 'button button--ghost' },
                    buttonsStyling: false
                }).then(function (result) {
                    return Boolean(result.isConfirmed);
                });
            }

            var text = settings.title + (settings.text ? '\n\n' + settings.text : '');

            return Promise.resolve(window.confirm(text));
        },

        /** Ask for a single value; resolves to null when cancelled. */
        prompt: function (options) {
            var settings = Object.assign({
                title: 'Enter a value',
                text: '',
                placeholder: '',
                confirmText: 'Save',
                inputValue: ''
            }, options || {});

            if (window.Swal) {
                return window.Swal.fire({
                    title: settings.title,
                    text: settings.text,
                    input: 'text',
                    inputValue: settings.inputValue,
                    inputPlaceholder: settings.placeholder,
                    showCancelButton: true,
                    confirmButtonText: settings.confirmText,
                    reverseButtons: true,
                    customClass: { confirmButton: 'button button--primary', cancelButton: 'button button--ghost' },
                    buttonsStyling: false,
                    inputValidator: function (value) {
                        return value && value.trim() !== '' ? null : 'This is required.';
                    }
                }).then(function (result) {
                    return result.isConfirmed ? String(result.value) : null;
                });
            }

            var answer = window.prompt(settings.title + (settings.text ? '\n\n' + settings.text : ''), settings.inputValue);

            return Promise.resolve(answer === null || answer.trim() === '' ? null : answer);
        },

        /**
         * Show a value the server will never show again — an API key, a
         * generated password. It is deliberately modal and deliberately
         * copyable: a key the operator failed to write down means reflashing
         * a gate.
         */
        reveal: function (title, value, note) {
            if (window.Swal) {
                return window.Swal.fire({
                    title: title,
                    html: '<p style="margin-bottom:.75rem">' + format.escape(note || '') + '</p>'
                        + '<code style="display:block;padding:.75rem;border-radius:.4rem;'
                        + 'background:var(--surface-sunken);word-break:break-all;user-select:all">'
                        + format.escape(value) + '</code>',
                    icon: 'warning',
                    confirmButtonText: 'I have copied it',
                    allowOutsideClick: false,
                    customClass: { confirmButton: 'button button--primary' },
                    buttonsStyling: false
                });
            }

            window.alert((note ? note + '\n\n' : '') + value);

            return Promise.resolve();
        },

        // -------------------------------------------------------------
        // Modals
        // -------------------------------------------------------------

        openModal: function (id) {
            var modal = document.getElementById(id);

            if (!modal) {
                return null;
            }

            modal.hidden = false;
            document.body.classList.add('is-modal-open');

            var focusable = modal.querySelector(
                'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])'
            );

            if (focusable) {
                focusable.focus();
            }

            modal.dispatchEvent(new CustomEvent('modal:open', { bubbles: true }));

            return modal;
        },

        closeModal: function (modal) {
            if (typeof modal === 'string') {
                modal = document.getElementById(modal);
            }

            if (!modal) {
                return;
            }

            modal.hidden = true;

            if (!dom.all('.modal:not([hidden])').length) {
                document.body.classList.remove('is-modal-open');
            }

            modal.dispatchEvent(new CustomEvent('modal:close', { bubbles: true }));
        },

        // -------------------------------------------------------------
        // Live text
        // -------------------------------------------------------------

        /**
         * Refresh every relative timestamp and elapsed counter on the page.
         *
         * Called on a timer so "2 minutes ago" does not quietly become wrong
         * on a screen that stays open all day.
         */
        refreshTimes: function () {
            dom.all('[data-relative-time]').forEach(function (node) {
                var value = node.getAttribute('data-relative-time');

                if (!value) {
                    return;
                }

                node.textContent = format.relative(value);
                node.setAttribute('title', format.datetime(value));
            });

            dom.all('[data-elapsed-since]').forEach(function (node) {
                var since = format.parseDate(node.getAttribute('data-elapsed-since'));

                if (!since) {
                    return;
                }

                node.textContent = format.duration((Date.now() - since.getTime()) / 1000);
            });

            dom.all('[data-duration]').forEach(function (node) {
                var seconds = node.getAttribute('data-duration');

                node.textContent = seconds === '' || seconds === null ? '—' : format.duration(seconds);
            });
        }
    };

    VAMS.ui = ui;

    // -----------------------------------------------------------------
    // Wiring
    // -----------------------------------------------------------------

    VAMS.module('ui', function () {
        // --- Sidebar ---
        var sidebar = document.getElementById('sidebar');
        var scrim = document.getElementById('sidebar-scrim');

        function closeSidebar() {
            if (sidebar) {
                sidebar.classList.remove('is-open');
            }

            if (scrim) {
                scrim.hidden = true;
            }
        }

        dom.on('click', '[data-sidebar-toggle]', function () {
            if (!sidebar) {
                return;
            }

            var open = sidebar.classList.toggle('is-open');

            if (scrim) {
                scrim.hidden = !open;
            }
        });

        dom.on('click', '[data-sidebar-close]', closeSidebar);

        if (scrim) {
            scrim.addEventListener('click', closeSidebar);
        }

        // Collapsible menu groups.
        dom.on('click', '.nav-list__toggle', function (event, button) {
            event.preventDefault();

            var group = button.closest('.nav-list__item--group');
            var open = group.classList.toggle('is-open');

            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        // --- Dropdowns ---
        dom.on('click', '[data-dropdown-toggle]', function (event, button) {
            event.preventDefault();
            event.stopPropagation();

            var dropdown = button.closest('[data-dropdown]');
            var menu = dropdown.querySelector('[data-dropdown-menu]');
            var open = menu.hidden;

            // Only one at a time: two open menus overlapping is never useful.
            dom.all('[data-dropdown-menu]').forEach(function (other) {
                other.hidden = true;
            });
            dom.all('[data-dropdown-toggle]').forEach(function (other) {
                other.setAttribute('aria-expanded', 'false');
            });

            menu.hidden = !open;
            button.setAttribute('aria-expanded', open ? 'true' : 'false');

            if (open) {
                dropdown.dispatchEvent(new CustomEvent('dropdown:open', { bubbles: true }));
            }
        });

        document.addEventListener('click', function (event) {
            if (event.target.closest && event.target.closest('[data-dropdown]')) {
                return;
            }

            dom.all('[data-dropdown-menu]').forEach(function (menu) { menu.hidden = true; });
            dom.all('[data-dropdown-toggle]').forEach(function (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            });
        });

        // --- Modals ---
        dom.on('click', '[data-modal-open]', function (event, button) {
            event.preventDefault();
            ui.openModal(button.getAttribute('data-modal-open'));
        });

        dom.on('click', '[data-modal-close]', function (event, button) {
            event.preventDefault();
            ui.closeModal(button.closest('.modal'));
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            var open = dom.all('.modal:not([hidden])').pop();

            if (open) {
                ui.closeModal(open);

                return;
            }

            dom.all('[data-dropdown-menu]').forEach(function (menu) { menu.hidden = true; });
        });

        // --- Tabs ---
        dom.on('click', '[data-tab]', function (event, tab) {
            event.preventDefault();

            var container = tab.closest('[data-tabs]');
            var name = tab.getAttribute('data-tab');

            dom.all('[data-tab]', container).forEach(function (other) {
                var active = other === tab;

                other.classList.toggle('is-active', active);
                other.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            dom.all('[data-tab-panel]', container).forEach(function (panel) {
                var active = panel.getAttribute('data-tab-panel') === name;

                panel.classList.toggle('is-active', active);
                panel.hidden = !active;

                // A table inside a hidden panel never loaded; tell it to now
                // that it is visible.
                if (active) {
                    panel.dispatchEvent(new CustomEvent('panel:shown', { bubbles: true }));
                }
            });
        });

        // --- Dismissible alerts ---
        dom.on('click', '[data-dismiss]', function (event, button) {
            var alert = button.closest('.alert');

            if (alert && alert.parentNode) {
                alert.parentNode.removeChild(alert);
            }
        });

        // --- Small conveniences ---
        dom.on('click', '[data-reload]', function () { window.location.reload(); });
        dom.on('click', '[data-print]', function () { window.print(); });
        dom.on('click', '[data-history-back]', function () { window.history.back(); });

        dom.on('click', '[data-copy-trigger]', function (event, button) {
            var value = button.getAttribute('data-copy-trigger');

            if (navigator.clipboard) {
                navigator.clipboard.writeText(value).then(function () {
                    ui.toast('Copied to the clipboard.', 'success');
                });

                return;
            }

            ui.toast('Copying is not available in this browser; select the text instead.', 'info');
        });

        dom.on('click', '[data-password-toggle]', function (event, button) {
            var input = document.getElementById(button.getAttribute('data-password-toggle'));

            if (!input) {
                return;
            }

            var reveal = input.type === 'password';

            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
            button.innerHTML = '<i class="fa-solid fa-eye' + (reveal ? '-slash' : '') + '" aria-hidden="true"></i>';
        });

        // Rows that behave as links, without nesting anchors inside cells.
        dom.on('click', 'tr[data-href]', function (event, row) {
            if (event.target.closest('a, button, input, select, label')) {
                return;
            }

            window.location.href = row.getAttribute('data-href');
        });

        // Client-side filtering of a server-rendered list.
        dom.on('input', '[data-filter-list]', function (event, input) {
            var target = dom.one(input.getAttribute('data-filter-list'));

            if (!target) {
                return;
            }

            var term = input.value.trim().toLowerCase();
            var rows = dom.all('tbody tr, .card', target);

            rows.forEach(function (row) {
                var matches = term === '' || (row.textContent || '').toLowerCase().indexOf(term) !== -1;

                row.hidden = !matches;
            });
        });

        // --- Theme ---
        var stored = null;

        try {
            stored = window.localStorage.getItem('vams.theme');
        } catch (error) {
            // Private browsing can refuse localStorage; the system preference
            // still applies, so this is not worth reporting.
            stored = null;
        }

        // A page that fixes its own theme says so, and the stored preference
        // does not apply to it. Without this the sign-in screen would render
        // as designed and then repaint a moment later for anyone who had
        // chosen the other theme behind it.
        var themeLocked = document.documentElement.hasAttribute('data-theme-locked');

        if (!themeLocked && (stored === 'dark' || stored === 'light')) {
            document.documentElement.setAttribute('data-bs-theme', stored);
        }

        function currentTheme() {
            var attribute = document.documentElement.getAttribute('data-bs-theme');

            if (attribute === 'dark' || attribute === 'light') {
                return attribute;
            }

            return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }

        function paintThemeToggle() {
            var next = currentTheme() === 'dark' ? 'light' : 'dark';

            dom.all('[data-theme-toggle]').forEach(function (button) {
                button.innerHTML = '<i class="fa-solid fa-' + (next === 'dark' ? 'moon' : 'sun') + '" aria-hidden="true"></i>';
                button.setAttribute('aria-label', 'Switch to the ' + next + ' theme');
            });
        }

        dom.on('click', '[data-theme-toggle]', function () {
            var next = currentTheme() === 'dark' ? 'light' : 'dark';

            document.documentElement.setAttribute('data-bs-theme', next);
            paintThemeToggle();

            try {
                window.localStorage.setItem('vams.theme', next);
            } catch (error) {
                // Nothing to do: the choice simply will not survive a reload.
            }

            document.dispatchEvent(new CustomEvent('theme:changed', { detail: { theme: next } }));
        });

        paintThemeToggle();

        // --- Clock ---
        var clock = dom.one('[data-clock]');

        if (clock) {
            var tickClock = function () {
                clock.textContent = new Date().toLocaleTimeString(undefined, {
                    hour: '2-digit', minute: '2-digit', second: '2-digit'
                });
            };

            tickClock();
            window.setInterval(tickClock, 1000);
        }

        // --- Live text ---
        ui.refreshTimes();
        window.setInterval(function () { ui.refreshTimes(); }, 30000);
    });
}(window.VAMS));
