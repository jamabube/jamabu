/**
 * Forms.
 *
 * A form marked data-ajax-form submits through the API, shows field errors
 * where they belong, and reports the outcome. Nothing here duplicates the
 * server's validation — the server is the authority, and this only presents
 * what it said.
 *
 * @package VAMS
 * @version 1.0.0
 */
(function (VAMS) {
    'use strict';

    var dom = VAMS.dom;
    var format = VAMS.format;
    var ui = VAMS.ui;

    /** Remove every error mark left by a previous attempt. */
    function clearErrors(form) {
        dom.all('.is-invalid', form).forEach(function (control) {
            control.classList.remove('is-invalid');
        });

        dom.all('[data-field-error]', form).forEach(function (node) {
            node.parentNode.removeChild(node);
        });
    }

    /**
     * Put each message next to the control it belongs to.
     *
     * A field the form does not contain — the server validating something the
     * page did not send — is reported as a toast instead of being dropped.
     *
     * @param {Object<string, Array<string>>} errors
     */
    function showErrors(form, errors) {
        var orphans = [];
        var first = null;

        Object.keys(errors || {}).forEach(function (field) {
            var messages = errors[field];
            var message = Array.isArray(messages) ? messages[0] : String(messages);
            var control = form.querySelector('[name="' + field + '"], [name="' + field + '[]"]');

            if (!control) {
                orphans.push(message);

                return;
            }

            control.classList.add('is-invalid');

            var holder = control.closest('.field') || control.parentNode;
            var note = dom.create('p', { class: 'field__error', 'data-field-error': '' }, message);

            holder.appendChild(note);

            if (!first) {
                first = control;
            }
        });

        if (first) {
            first.focus();
            first.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }

        orphans.forEach(function (message) { ui.toast(message, 'error'); });
    }

    /**
     * Submit a form to its endpoint.
     *
     * @param {HTMLFormElement} form
     * @param {HTMLElement|null} trigger
     */
    function submit(form, trigger) {
        clearErrors(form);

        var payload = VAMS.http.serialise(form);
        var recordId = payload.id || '';
        var endpoint = form.getAttribute('data-endpoint');
        var method = (form.getAttribute('data-method') || 'POST').toUpperCase();

        // A form that can create and update uses the update endpoint once it
        // has been given a record, so one modal serves both.
        if (recordId && form.getAttribute('data-update-endpoint')) {
            endpoint = form.getAttribute('data-update-endpoint').replace('{id}', encodeURIComponent(recordId));
            method = 'PUT';
        } else if (recordId && endpoint && endpoint.indexOf('{id}') !== -1) {
            endpoint = endpoint.replace('{id}', encodeURIComponent(recordId));
        }

        delete payload.id;

        if (trigger) {
            trigger.classList.add('is-busy');
            trigger.disabled = true;
        }

        return VAMS.http.request(method, endpoint, payload)
            .then(function (response) {
                form.dispatchEvent(new CustomEvent('form:success', { bubbles: true, detail: response }));

                // A value the server will never show again is presented in a
                // dialog the user has to dismiss deliberately.
                var secretField = form.getAttribute('data-secret-field');
                var secret = secretField ? (response.data || {})[secretField] : null;

                var announce = function () {
                    ui.toast(form.getAttribute('data-success') || response.message || 'Saved.', 'success');
                };

                var finish = function () {
                    var target = form.getAttribute('data-result-target');

                    if (target) {
                        var node = dom.one(target);

                        if (node) {
                            node.innerHTML = renderResult(response.data);
                        }

                        return;
                    }

                    var modal = form.closest('.modal');

                    if (modal) {
                        ui.closeModal(modal);
                    }

                    if (form.getAttribute('data-reload-on-success') === 'true') {
                        window.location.reload();

                        return;
                    }

                    VAMS.table.reload();
                    form.reset();
                };

                if (secret) {
                    return ui.reveal(
                        'Copy this now',
                        secret,
                        form.getAttribute('data-secret-message') || ''
                    ).then(function () {
                        announce();
                        finish();
                    });
                }

                announce();
                finish();

                return response;
            })
            .catch(function (error) {
                if (error.status === 422 && error.details && Object.keys(error.details).length) {
                    showErrors(form, error.details);
                    ui.toast(error.message || 'Some values were not accepted.', 'error');

                    return;
                }

                ui.toast(error.message || 'The request could not be completed.', 'error');
            })
            .finally(function () {
                if (trigger) {
                    trigger.classList.remove('is-busy');
                    trigger.disabled = false;
                }
            });
    }

    /** Render a small result summary inside a modal, for reconcile-style forms. */
    function renderResult(data) {
        if (!data || typeof data !== 'object') {
            return '';
        }

        return '<dl class="definition-list definition-list--tight">'
            + Object.keys(data).map(function (key) {
                var value = data[key];
                var text = Array.isArray(value)
                    ? (value.length ? value.join(', ') : 'none')
                    : String(value);

                return '<div class="definition-list__row"><dt>' + format.escape(format.label(key))
                    + '</dt><dd>' + format.escape(text) + '</dd></div>';
            }).join('')
            + '</dl>';
    }

    VAMS.forms = {
        submit: submit,
        showErrors: showErrors,
        clearErrors: clearErrors,

        /**
         * Load a record into a form and open its modal.
         *
         * @param {string} modalId
         * @param {Object} record
         */
        fill: function (modalId, record) {
            var modal = document.getElementById(modalId);

            if (!modal) {
                return;
            }

            var form = modal.querySelector('form');

            if (!form) {
                return;
            }

            clearErrors(form);
            form.reset();

            Object.keys(record || {}).forEach(function (key) {
                var control = form.querySelector('[name="' + key + '"]');

                if (!control) {
                    return;
                }

                if (control.type === 'checkbox') {
                    control.checked = Number(record[key]) === 1 || record[key] === true;

                    return;
                }

                control.value = record[key] === null || record[key] === undefined ? '' : String(record[key]);
            });

            var idField = form.querySelector('[data-record-id]');

            if (idField) {
                idField.value = record.id || '';
            }

            // Fields that only make sense when creating are hidden while
            // editing; fields that must not change are locked.
            var editing = Boolean(record.id);

            dom.all('[data-create-only]', form).forEach(function (node) {
                node.hidden = editing;
            });

            dom.all('[data-lock-on-edit]', form).forEach(function (node) {
                node.readOnly = editing;
            });

            ui.openModal(modalId);
        }
    };

    VAMS.module('forms', function () {
        // Explicit submit buttons in a modal footer, outside the form element.
        dom.on('click', '[data-modal-submit]', function (event, button) {
            event.preventDefault();

            var modal = document.getElementById(button.getAttribute('data-modal-submit'));
            var form = modal ? modal.querySelector('form[data-ajax-form]') : null;

            if (!form) {
                return;
            }

            if (!form.reportValidity()) {
                return;
            }

            submit(form, button);
        });

        // A form submitted with the Enter key.
        dom.on('submit', 'form[data-ajax-form]', function (event, form) {
            event.preventDefault();
            submit(form, form.querySelector('[type="submit"]'));
        });

        // Reset a create form each time its modal opens, so yesterday's values
        // do not reappear.
        dom.on('click', '[data-modal-open][data-form-mode="create"]', function (event, button) {
            var modal = document.getElementById(button.getAttribute('data-modal-open'));
            var form = modal ? modal.querySelector('form') : null;

            if (!form) {
                return;
            }

            form.reset();
            clearErrors(form);

            var idField = form.querySelector('[data-record-id]');

            if (idField) {
                idField.value = '';
            }

            dom.all('[data-create-only]', form).forEach(function (node) { node.hidden = false; });
            dom.all('[data-lock-on-edit]', form).forEach(function (node) { node.readOnly = false; });
        });

        // Inputs that must hold an upper-case value, so a UID or a plate typed
        // in lower case matches what the server stores.
        dom.on('input', '[data-uppercase]', function (event, input) {
            var start = input.selectionStart;

            input.value = input.value.toUpperCase();
            input.setSelectionRange(start, start);
        });

        // Two selects where choosing one must clear the other.
        dom.on('change', '[data-exclusive-with]', function (event, control) {
            if (control.value === '') {
                return;
            }

            var other = document.getElementById(control.getAttribute('data-exclusive-with'));

            if (other) {
                other.value = '';
            }
        });

        // A comma-separated field posted as an array.
        dom.on('submit', 'form', function (event, form) {
            dom.all('[data-list-field]', form).forEach(function (input) {
                input.value = input.value.split(',').map(function (part) {
                    return part.trim();
                }).filter(function (part) {
                    return part !== '';
                }).join(',');
            });
        });

        // Selects that refresh their options from an endpoint when opened, so
        // a list of available cards or tags is never stale.
        dom.all('[data-refresh-from]').forEach(function (select) {
            var loaded = false;

            var load = function () {
                if (loaded) {
                    return;
                }

                loaded = true;

                VAMS.http.get(select.getAttribute('data-refresh-from')).then(function (response) {
                    var rows = response.data;

                    if (!Array.isArray(rows)) {
                        return;
                    }

                    var valueKey = select.getAttribute('data-option-value');
                    var labelKey = select.getAttribute('data-option-label');
                    var placeholder = select.querySelector('option[value=""]');

                    dom.empty(select);

                    if (placeholder) {
                        select.appendChild(placeholder);
                    }

                    rows.forEach(function (row) {
                        select.appendChild(dom.create(
                            'option',
                            { value: String(row[valueKey]) },
                            String(row[labelKey] || row[valueKey])
                        ));
                    });
                }).catch(function () {
                    loaded = false;
                });
            };

            select.addEventListener('focus', load);

            var modal = select.closest('.modal');

            if (modal) {
                modal.addEventListener('modal:open', load);
            }
        });

        // The enrolment form asks the server which sensor slot is free.
        dom.on('change', '[data-slot-source]', function (event, select) {
            var target = dom.one('[data-slot-target]');

            if (!target || select.value === '') {
                return;
            }

            VAMS.http.get(select.getAttribute('data-slot-source'), { device_id: select.value })
                .then(function (response) {
                    var slot = (response.data || {}).slot;

                    target.placeholder = slot ? 'Next free slot: ' + slot : 'This sensor is full';
                    target.value = slot || '';
                })
                .catch(function () {
                    target.placeholder = 'Could not read the sensor';
                });
        });

        // Password strength, scored by the server so the meter and the policy
        // can never disagree.
        var strengthInput = dom.one('[data-strength-input]');

        if (strengthInput) {
            var meter = dom.one('[data-strength]');
            var fill = dom.one('[data-strength-fill]');
            var label = dom.one('[data-strength-label]');
            var failures = dom.one('[data-strength-failures]');

            strengthInput.addEventListener('input', VAMS.debounce(function () {
                var candidate = strengthInput.value;

                if (candidate === '') {
                    if (meter) { meter.hidden = true; }
                    if (failures) { dom.empty(failures); }

                    return;
                }

                VAMS.http.post(strengthInput.getAttribute('data-strength-endpoint'), { password: candidate })
                    .then(function (response) {
                        var data = response.data || {};
                        var score = Number(data.score) || 0;

                        if (meter) { meter.hidden = false; }

                        if (fill) {
                            fill.style.width = Math.max(5, Math.min(100, score)) + '%';
                            fill.className = 'strength__fill' + (score >= 80 ? ' is-good' : (score >= 50 ? ' is-fair' : ''));
                        }

                        if (label) {
                            label.textContent = String(data.label || '');
                        }

                        if (failures) {
                            dom.empty(failures);

                            (data.failures || []).forEach(function (message) {
                                failures.appendChild(dom.create('li', {}, message));
                            });
                        }
                    })
                    .catch(function () {
                        // The meter is an aid, not a gate: the server still
                        // enforces the policy on submit.
                    });
            }, 400));
        }
    });
}(window.VAMS));
