/**
 * The AJAX layer.
 *
 * Every call the interface makes goes through here, so the CSRF token, the
 * JSON envelope and the error handling are dealt with once. Nothing else in
 * the front end calls fetch directly.
 *
 * @package VAMS
 * @version 1.0.0
 */
(function (VAMS) {
    'use strict';

    /**
     * An API failure carrying the server's structured detail.
     *
     * The message is the one the server chose: it already decided how much to
     * reveal, and second-guessing it here would either leak or confuse.
     */
    function ApiError(message, status, code, details) {
        this.name = 'ApiError';
        this.message = message;
        this.status = status;
        this.code = code || '';
        this.details = details || {};
    }

    ApiError.prototype = Object.create(Error.prototype);
    ApiError.prototype.constructor = ApiError;

    var http = {
        ApiError: ApiError,

        /**
         * Perform a request against the API.
         *
         * @param {string} method
         * @param {string} path
         * @param {Object|null} body
         * @param {Object} options
         * @returns {Promise<Object>} The envelope's data, unwrapped.
         */
        request: function (method, path, body, options) {
            options = options || {};

            var headers = {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            };

            var init = {
                method: method.toUpperCase(),
                headers: headers,
                credentials: 'same-origin'
            };

            // The token guards every state-changing call. Safe methods do not
            // carry it, which keeps a plain GET cacheable.
            if (['POST', 'PUT', 'PATCH', 'DELETE'].indexOf(init.method) !== -1) {
                headers[VAMS.config.csrfHeader] = VAMS.config.csrfToken;
            }

            if (body !== null && body !== undefined) {
                if (body instanceof FormData) {
                    init.body = body;
                } else {
                    headers['Content-Type'] = 'application/json';
                    init.body = JSON.stringify(body);
                }
            }

            var url = VAMS.url(path);

            if (options.query) {
                var query = http.buildQuery(options.query);

                if (query !== '') {
                    url += (url.indexOf('?') === -1 ? '?' : '&') + query;
                }
            }

            if (options.signal) {
                init.signal = options.signal;
            }

            return window.fetch(url, init).then(function (response) {
                return http.handle(response, options);
            });
        },

        get: function (path, query, options) {
            return http.request('GET', path, null, Object.assign({ query: query }, options || {}));
        },

        post: function (path, body, options) {
            return http.request('POST', path, body || {}, options);
        },

        put: function (path, body, options) {
            return http.request('PUT', path, body || {}, options);
        },

        del: function (path, options) {
            return http.request('DELETE', path, null, options);
        },

        /**
         * Turn a response into data, or into an ApiError that callers can act
         * on without having to know about HTTP.
         */
        handle: function (response, options) {
            options = options || {};

            // A 401 means the session ended while the page was open. Sending
            // the user to sign in again is the only useful response, and doing
            // it here means no caller has to remember to.
            if (response.status === 401 && options.redirectOnExpiry !== false) {
                window.location.href = VAMS.url('/login?timeout=1');

                return Promise.reject(new ApiError('Your session has expired.', 401, 'SESSION_EXPIRED', {}));
            }

            var contentType = response.headers.get('Content-Type') || '';

            if (contentType.indexOf('application/json') === -1) {
                if (response.ok) {
                    return response.text();
                }

                return Promise.reject(new ApiError(
                    'The server returned an unexpected response.',
                    response.status,
                    'UNEXPECTED_RESPONSE',
                    {}
                ));
            }

            return response.json().then(function (payload) {
                if (response.ok && payload.success !== false) {
                    var meta = payload.meta || {};

                    return {
                        data: payload.data,
                        meta: meta,
                        // The envelope carries pagination inside meta; it is
                        // lifted here so callers do not have to know that.
                        pagination: meta.pagination || null,
                        message: payload.message || ''
                    };
                }

                throw new ApiError(
                    payload.message || 'The request could not be completed.',
                    response.status,
                    payload.error_code || '',
                    payload.details || payload.errors || {}
                );
            });
        },

        /**
         * Serialise a plain object into a query string, dropping empty values
         * so the URL carries only the filters that are actually set.
         */
        buildQuery: function (params) {
            var parts = [];

            Object.keys(params || {}).forEach(function (key) {
                var value = params[key];

                if (value === null || value === undefined || value === '' || value === false) {
                    return;
                }

                if (Array.isArray(value)) {
                    value.forEach(function (item) {
                        parts.push(encodeURIComponent(key + '[]') + '=' + encodeURIComponent(item));
                    });

                    return;
                }

                parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
            });

            return parts.join('&');
        },

        /**
         * Read a form into a plain object.
         *
         * Repeated names collect into an array, which is what the permission
         * pickers and the slot lists need.
         */
        serialise: function (form) {
            var data = {};

            new FormData(form).forEach(function (value, key) {
                var name = key.replace(/\[\]$/, '');
                var isList = key !== name;

                if (isList || Object.prototype.hasOwnProperty.call(data, name)) {
                    if (!Array.isArray(data[name])) {
                        data[name] = data[name] === undefined ? [] : [data[name]];
                    }

                    data[name].push(value);

                    return;
                }

                data[name] = value;
            });

            // An unchecked box submits nothing at all, which the server would
            // read as "unchanged" rather than "off". Sending an explicit zero
            // makes the intent unambiguous.
            VAMS.dom.all('input[type="checkbox"]', form).forEach(function (box) {
                var name = box.name.replace(/\[\]$/, '');

                if (name && !box.checked && !Array.isArray(data[name]) && data[name] === undefined) {
                    data[name] = '0';
                }
            });

            return data;
        }
    };

    VAMS.http = http;
}(window.VAMS));
