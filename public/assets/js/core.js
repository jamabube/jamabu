/**
 * Core utilities.
 *
 * Everything here is plain ES2015+ with no dependencies. jQuery and the vendor
 * libraries are used where they are present, but nothing in the interface
 * depends on them: a workstation where assets:fetch was never run still gets a
 * working system, which is the point of shipping this locally at all.
 *
 * @package VAMS
 * @version 1.0.0
 */
(function (window, document) {
    'use strict';

    /** @type {Object} Values the server put in the page. */
    var bootstrapData = {};

    var element = document.getElementById('app-bootstrap');

    if (element) {
        try {
            bootstrapData = JSON.parse(element.textContent || '{}');
        } catch (error) {
            // A malformed data island must not take the page down; the
            // defaults below keep every module working.
            bootstrapData = {};
        }
    }

    var VAMS = {
        config: {
            csrfToken: bootstrapData.csrfToken || readMeta('csrf-token'),
            csrfHeader: bootstrapData.csrfHeader || readMeta('csrf-header') || 'X-CSRF-Token',
            base: (readMeta('app-base') || '').replace(/\/$/, ''),
            pollInterval: bootstrapData.pollInterval || 5,
            refreshSeconds: bootstrapData.refreshSeconds || 15,
            sessionTimeout: bootstrapData.sessionTimeout || 1800,
            /*
             * The application timezone's UTC offset. The API sends naive local
             * timestamps, so without this a browser set to a different zone
             * would read every one of them as its own local time.
             */
            serverOffset: bootstrapData.serverOffset || '',
            idleWarning: bootstrapData.idleWarning || 120,
            notifications: bootstrapData.notifications || { pollInterval: 30, unread: 0 },
            user: bootstrapData.user || null,
            route: bootstrapData.route || ''
        },

        /** Modules register themselves here and are started on DOM ready. */
        modules: {},

        /**
         * Register a module. Each is started once, and a failure in one must
         * not stop the others: a broken chart should not disable the tables.
         */
        module: function (name, factory) {
            this.modules[name] = factory;
        },

        start: function () {
            Object.keys(this.modules).forEach(function (name) {
                try {
                    VAMS.modules[name](VAMS);
                } catch (error) {
                    if (window.console && window.console.error) {
                        window.console.error('[VAMS] module "' + name + '" failed to start:', error);
                    }
                }
            });
        }
    };

    function readMeta(name) {
        var meta = document.querySelector('meta[name="' + name + '"]');

        return meta ? meta.getAttribute('content') || '' : '';
    }

    // -----------------------------------------------------------------
    // DOM helpers
    // -----------------------------------------------------------------

    VAMS.dom = {
        /** @returns {Element|null} */
        one: function (selector, scope) {
            return (scope || document).querySelector(selector);
        },

        /** @returns {Array<Element>} A real array, so map and filter work. */
        all: function (selector, scope) {
            return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
        },

        /**
         * Delegate an event to elements matching a selector, including ones
         * added to the page later. Every table row and dynamic control relies
         * on this rather than being rebound after each render.
         */
        on: function (type, selector, handler, scope) {
            (scope || document).addEventListener(type, function (event) {
                var target = event.target;

                while (target && target !== (scope || document)) {
                    if (target.matches && target.matches(selector)) {
                        handler.call(target, event, target);

                        return;
                    }

                    target = target.parentElement;
                }
            });
        },

        create: function (tag, attributes, text) {
            var node = document.createElement(tag);

            Object.keys(attributes || {}).forEach(function (key) {
                if (key === 'class') {
                    node.className = attributes[key];
                } else if (key === 'html') {
                    node.innerHTML = attributes[key];
                } else {
                    node.setAttribute(key, attributes[key]);
                }
            });

            if (text !== undefined && text !== null) {
                node.appendChild(document.createTextNode(String(text)));
            }

            return node;
        },

        /** Remove every child without touching the parent's attributes. */
        empty: function (node) {
            while (node && node.firstChild) {
                node.removeChild(node.firstChild);
            }
        }
    };

    // -----------------------------------------------------------------
    // Formatting
    // -----------------------------------------------------------------

    VAMS.format = {
        /**
         * Escape a value for insertion as text.
         *
         * Every value that reaches the DOM through innerHTML passes through
         * here. A plate number is user-supplied data and has to be treated as
         * such even though it arrived from our own API.
         */
        escape: function (value) {
            if (value === null || value === undefined) {
                return '';
            }

            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        number: function (value) {
            var number = Number(value);

            if (!isFinite(number)) {
                return '0';
            }

            return number.toLocaleString();
        },

        /** A byte count in the largest unit that keeps it readable. */
        bytes: function (value) {
            var bytes = Number(value) || 0;
            var units = ['B', 'KB', 'MB', 'GB', 'TB'];
            var index = 0;

            while (bytes >= 1024 && index < units.length - 1) {
                bytes /= 1024;
                index += 1;
            }

            return (index === 0 ? bytes : bytes.toFixed(1)) + ' ' + units[index];
        },

        /** A duration in seconds as "2h 05m", or "45s" when under a minute. */
        duration: function (seconds) {
            var total = Number(seconds);

            if (!isFinite(total) || total < 0) {
                return '—';
            }

            if (total < 60) {
                return Math.round(total) + 's';
            }

            var hours = Math.floor(total / 3600);
            var minutes = Math.floor((total % 3600) / 60);

            if (hours === 0) {
                return minutes + 'm';
            }

            return hours + 'h ' + String(minutes).padStart(2, '0') + 'm';
        },

        milliseconds: function (value) {
            var ms = Number(value);

            if (!isFinite(ms)) {
                return '—';
            }

            return ms >= 1000 ? (ms / 1000).toFixed(2) + ' s' : Math.round(ms) + ' ms';
        },

        /**
         * Parse a server timestamp into a correct instant.
         *
         * The API sends "YYYY-MM-DD HH:MM:SS" in the application's timezone
         * with no offset attached. Two things have to be handled:
         *
         *   - Safari refuses the space separator, so it is normalised to "T".
         *   - A string with no zone is anchored to the server's offset rather
         *     than the browser's. Without that, a workstation set to a
         *     different timezone shows every movement hours out — and reads
         *     "just now" as "in seven hours".
         *
         * A value that already carries a zone (an ISO-8601 timestamp from the
         * API envelope) is left exactly as it is.
         */
        parseDate: function (value) {
            if (!value) {
                return null;
            }

            var text = String(value).trim().replace(' ', 'T');
            var hasZone = /(?:Z|[+-]\d{2}:?\d{2})$/.test(text);

            if (!hasZone && VAMS.config.serverOffset) {
                text += VAMS.config.serverOffset;
            }

            var date = new Date(text);

            return isNaN(date.getTime()) ? null : date;
        },

        datetime: function (value) {
            var date = this.parseDate(value);

            if (!date) {
                return '—';
            }

            return date.toLocaleString(undefined, {
                year: 'numeric', month: 'short', day: '2-digit',
                hour: '2-digit', minute: '2-digit'
            });
        },

        date: function (value) {
            var date = this.parseDate(value);

            if (!date) {
                return '—';
            }

            return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: '2-digit' });
        },

        time: function (value) {
            var date = this.parseDate(value);

            return date ? date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '—';
        },

        /**
         * "4 minutes ago", falling back to an absolute date once a week has
         * passed — "37 days ago" is harder to read than the date itself.
         */
        relative: function (value) {
            var date = this.parseDate(value);

            if (!date) {
                return '—';
            }

            var seconds = Math.round((Date.now() - date.getTime()) / 1000);
            var future = seconds < 0;
            var magnitude = Math.abs(seconds);
            var text;

            if (magnitude < 10) {
                return future ? 'in a moment' : 'just now';
            }

            if (magnitude < 60) {
                text = magnitude + ' second' + (magnitude === 1 ? '' : 's');
            } else if (magnitude < 3600) {
                var minutes = Math.floor(magnitude / 60);
                text = minutes + ' minute' + (minutes === 1 ? '' : 's');
            } else if (magnitude < 86400) {
                var hours = Math.floor(magnitude / 3600);
                text = hours + ' hour' + (hours === 1 ? '' : 's');
            } else if (magnitude < 604800) {
                var days = Math.floor(magnitude / 86400);
                text = days + ' day' + (days === 1 ? '' : 's');
            } else {
                return this.date(value);
            }

            return future ? 'in ' + text : text + ' ago';
        },

        /** Title-case a machine value: "pending_sync" becomes "Pending sync". */
        label: function (value) {
            if (value === null || value === undefined || value === '') {
                return '';
            }

            var text = String(value).replace(/[_-]+/g, ' ');

            return text.charAt(0).toUpperCase() + text.slice(1);
        }
    };

    // -----------------------------------------------------------------
    // Timing
    // -----------------------------------------------------------------

    /** Run at most once per wait period, after the calls stop. */
    VAMS.debounce = function (fn, wait) {
        var timer = null;

        return function () {
            var context = this;
            var args = arguments;

            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                fn.apply(context, args);
            }, wait || 250);
        };
    };

    /**
     * A repeating timer that pauses while the tab is hidden.
     *
     * A dashboard left open on a background tab overnight would otherwise make
     * thousands of pointless requests; this stops when nobody is looking and
     * fires once immediately on return so the screen is current.
     */
    VAMS.poller = function (fn, seconds) {
        var handle = null;
        var running = false;

        function tick() {
            if (document.hidden) {
                return;
            }

            fn();
        }

        var poller = {
            /**
             * @param {boolean} immediate Run once now rather than waiting out
             *        the first interval. Used where the initial state matters
             *        — a status indicator that reads "Checking…" for a minute
             *        is worse than no indicator.
             */
            start: function (immediate) {
                if (running) {
                    return poller;
                }

                running = true;
                handle = window.setInterval(tick, Math.max(1, seconds) * 1000);

                if (immediate) {
                    tick();
                }

                return poller;
            },

            stop: function () {
                running = false;
                window.clearInterval(handle);
                handle = null;

                return poller;
            },

            isRunning: function () {
                return running;
            },

            setInterval: function (newSeconds) {
                seconds = Math.max(1, newSeconds);

                if (running) {
                    poller.stop().start();
                }

                return poller;
            }
        };

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && running) {
                tick();
            }
        });

        return poller;
    };

    /** Build an absolute URL for an application path. */
    VAMS.url = function (path) {
        if (/^https?:\/\//i.test(path)) {
            return path;
        }

        return VAMS.config.base + (path.charAt(0) === '/' ? path : '/' + path);
    };

    window.VAMS = VAMS;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { VAMS.start(); });
    } else {
        VAMS.start();
    }
}(window, document));
