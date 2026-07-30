(function () {
    'use strict';

    /**
     * Builds DOM nodes directly (createElement + textContent) rather than
     * concatenating strings into innerHTML - customer names and shipping
     * methods come straight from order billing data, so this is the fix for
     * the unescaped-HTML gap the original n8n version had.
     */
    function el(tag, props, children) {
        var node = document.createElement(tag);
        if (props) {
            Object.keys(props).forEach(function (key) {
                if (key === 'class') {
                    node.className = props[key];
                } else if (key === 'text') {
                    node.textContent = props[key];
                } else {
                    node.setAttribute(key, props[key]);
                }
            });
        }
        (children || []).forEach(function (child) {
            node.appendChild(child);
        });
        return node;
    }

    function todayIso() {
        var d = new Date();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + day;
    }

    function renderGroups(container, groups) {
        container.textContent = '';

        if (!groups || !groups.length) {
            container.appendChild(el('p', { class: 'woohoo-po-empty', text: 'Keine Bestellungen gefunden.' }));
            return;
        }

        groups.forEach(function (group) {
            var rows = group.orders.map(function (order) {
                return el('tr', null, [
                    el('td', { text: order.customer_name }),
                    el('td', { text: order.shipping_method }),
                    el('td', { text: order.variant }),
                    el('td', { class: 'woohoo-po-qty', text: order.qty_label }),
                ]);
            });

            var table = el('table', { class: 'woohoo-po-table' }, [
                el('thead', null, [
                    el('tr', null, [
                        el('th', { text: 'Kunde' }),
                        el('th', { text: 'Versandart' }),
                        el('th', { text: 'Variante' }),
                        el('th', { text: 'Menge' }),
                    ]),
                ]),
                el('tbody', null, rows),
            ]);

            var head = el('div', { class: 'woohoo-po-group__head' }, [
                el('strong', { text: group.name }),
                el('span', { class: 'woohoo-po-group__total', text: group.total_label + ' gesamt' }),
            ]);

            container.appendChild(el('div', { class: 'woohoo-po-group' }, [head, table]));
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('woohoo-po');
        if (!root || typeof woohooPO === 'undefined') {
            return;
        }

        var dateInput  = document.getElementById('woohoo-po-date');
        var dateField  = document.getElementById('woohoo-po-date-field');
        var plzInput   = document.getElementById('woohoo-po-plz');
        var modeInputs = document.getElementsByName('woohoo-po-mode');
        var submitBtn  = document.getElementById('woohoo-po-submit');
        var status     = document.getElementById('woohoo-po-status');
        var results    = document.getElementById('woohoo-po-results');

        dateInput.value = todayIso();

        function currentMode() {
            var checked = Array.prototype.filter.call(modeInputs, function (input) {
                return input.checked;
            })[0];
            return checked ? checked.value : 'local';
        }

        function syncDateVisibility() {
            dateField.style.display = currentMode() === 'local' ? '' : 'none';
        }

        Array.prototype.forEach.call(modeInputs, function (input) {
            input.addEventListener('change', syncDateVisibility);
        });
        syncDateVisibility();

        function setStatus(text, isError) {
            status.textContent = text;
            status.className = isError ? 'woohoo-po-status woohoo-po-status--error' : 'woohoo-po-status';
        }

        submitBtn.addEventListener('click', function () {
            var mode = currentMode();

            if (mode === 'local' && !dateInput.value) {
                setStatus('Bitte ein Datum wählen.', true);
                return;
            }

            var params = new URLSearchParams();
            params.set('mode', mode);
            if (mode === 'local') {
                params.set('date', dateInput.value);
            }
            params.set('exclude_postcodes', plzInput.value.trim());

            submitBtn.disabled = true;
            setStatus('Wird geladen…', false);
            results.textContent = '';

            fetch(woohooPO.restUrl + '?' + params.toString(), {
                headers: { 'X-WP-Nonce': woohooPO.nonce },
                credentials: 'same-origin',
            })
                .then(function (response) {
                    // Read the body regardless of status - WP_Error REST
                    // responses (400/401/403/500) still carry a useful
                    // { message: "..." } JSON payload we want to show
                    // instead of a generic failure string.
                    return response.json().then(function (data) {
                        if (!response.ok) {
                            var err = new Error(data && data.message ? data.message : 'HTTP ' + response.status);
                            throw err;
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    setStatus('', false);
                    renderGroups(results, data.groups);
                })
                .catch(function (err) {
                    setStatus((err && err.message) || 'Fehler beim Laden. Bitte erneut versuchen.', true);
                })
                .finally(function () {
                    submitBtn.disabled = false;
                });
        });
    });
})();
