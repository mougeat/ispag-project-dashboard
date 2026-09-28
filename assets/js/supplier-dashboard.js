jQuery(function ($) {
    if (typeof ispagSupplier === 'undefined') { return; }
    const cfg = ispagSupplier;
    const I18N = cfg.i18n || {};
    const LOCALE = cfg.locale || 'en-US';

    // --- UTILS ---
    function t(key) { return I18N[key] !== undefined ? I18N[key] : key; }
    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
    function escAttr(s) { return esc(s).replace(/"/g, '&quot;'); }

    function numberFormat(opts) {
        try { return new Intl.NumberFormat(LOCALE, opts); }
        catch (e) { return new Intl.NumberFormat('en-US', opts); }
    }

    function num(v, dec) {
        if (v === null || v === undefined) { return '—'; }
        dec = dec || 0;
        return numberFormat({ minimumFractionDigits: dec, maximumFractionDigits: dec }).format(v);
    }

    function money(v, cur, dec) {
        if (v === null || v === undefined) { return '—'; }
        dec = dec || 0;
        if (/^[A-Z]{3}$/.test(cur || '')) {
            return numberFormat({
                style: 'currency', currency: cur,
                minimumFractionDigits: dec, maximumFractionDigits: dec
            }).format(v);
        }
        return num(v, dec) + (cur ? ' ' + esc(cur) : '');
    }

    function days(v) { return v === null || v === undefined ? '—' : num(v, 1) + ' ' + t('days_short'); }

    function evo(v) {
        if (v === null || v === undefined) { return '—'; }
        const arrow = v > 0 ? '▲' : (v < 0 ? '▼' : '=');
        return arrow + ' ' + num(Math.abs(v), 1) + ' %';
    }

    function delay(v) {
        if (v === null || v === undefined) { return '—'; }
        const color = v > 0 ? 'color:#d63638;' : 'color:#00a32a;';
        return '<span style="' + color + '">' + (v > 0 ? '+' : '') + num(v, 1) + ' ' + t('days_short') + '</span>';
    }

    function fetchStats(year, onlyProducts, limit) {
        return $.post(cfg.ajax_url, {
            action: 'ispag_pd_get_supplier_stats',
            nonce: cfg.nonce,
            year: year,
            only_products: onlyProducts ? 1 : 0,
            limit: limit || 0
        });
    }

    // --- PAGE SUPPLIERS ---
    const COLS = [
        { key: 'name',             label: 'col_name',        tip: 'tip_name',        left: true, render: r => esc(r.name) },
        { key: 'amount',           label: 'col_amount',      tip: 'tip_amount',      render: r => '<strong>' + money(r.amount, r.currency) + '</strong>' },
        { key: 'evo_amount',       label: 'col_evo_amount',  tip: 'tip_evo_amount',  render: r => evo(r.evo_amount) },
        { key: 'share',            label: 'col_share',       tip: 'tip_share',       render: r => r.share === null ? '—' : num(r.share, 1) + ' %' },
        { key: 'qty',              label: 'col_qty',         tip: 'tip_qty',         render: r => num(r.qty) },
        { key: 'evo_qty',          label: 'col_evo_qty',     tip: 'tip_evo_qty',     render: r => evo(r.evo_qty) },
        { key: 'orders',           label: 'col_orders',      tip: 'tip_orders',      render: r => num(r.orders) },
        { key: 'avg_basket',       label: 'col_avg_basket',  tip: 'tip_avg_basket',  render: r => money(r.avg_basket, r.currency) },
        { key: 'avg_unit_price',   label: 'col_avg_unit',    tip: 'tip_avg_unit',    render: r => money(r.avg_unit_price, r.currency, 2) },
        { key: 'avg_lead_days',    label: 'col_avg_lead',    tip: 'tip_avg_lead',    render: r => days(r.avg_lead_days) },
        { key: 'contractual_days', label: 'col_contractual', tip: 'tip_contractual', render: r => r.contractual_days === null ? '—' : r.contractual_days + ' ' + t('days_short') },
        { key: 'avg_delay_days',   label: 'col_avg_delay',   tip: 'tip_avg_delay',   render: r => delay(r.avg_delay_days) },
        { key: 'on_time_pct',      label: 'col_on_time',     tip: 'tip_on_time',     render: r => r.on_time_pct === null ? '—' : num(r.on_time_pct, 1) + ' %' }
    ];

    const state = { rows: [], sortKey: 'amount', sortDir: -1, year: cfg.current_year };
    let chartInstance = null;

    function sortedRows() {
        const k = state.sortKey, d = state.sortDir;
        return state.rows.slice().sort(function (a, b) {
            const va = a[k], vb = b[k];
            if (va === null && vb === null) { return 0; }
            if (va === null) { return 1; }   // valeurs vides toujours en bas
            if (vb === null) { return -1; }
            if (typeof va === 'string') { return d * va.localeCompare(vb, LOCALE); }
            return d * (va - vb);
        });
    }

    function renderHead() {
        const html = COLS.map(function (c, i) {
            const active = state.sortKey === c.key;
            const arrow = active ? (state.sortDir === 1 ? ' ▲' : ' ▼') : '';
            // Les info-bulles des colonnes de droite s'ouvrent vers la gauche pour rester visibles
            const tip = '<span class="ispag-tip' + (i >= 6 ? ' ispag-tip--right' : '') + '" tabindex="0" data-tip="' + escAttr(t(c.tip)) + '">ⓘ</span>';
            return '<th data-sort="' + c.key + '" style="cursor:pointer;text-align:' + (c.left ? 'left' : 'right') + ';">'
                + esc(t(c.label)) + arrow + tip + '</th>';
        }).join('');
        $('#ispag-sup-head').html(html);
    }

    function fullRow(text) {
        return '<tr><td colspan="' + COLS.length + '">' + esc(text) + '</td></tr>';
    }

    function renderBody() {
        const $body = $('#ispag-sup-body');
        if (!state.rows.length) {
            $body.html(fullRow(t('no_orders')));
            return;
        }
        const html = sortedRows().map(function (r) {
            return '<tr>' + COLS.map(function (c) {
                return '<td style="text-align:' + (c.left ? 'left' : 'right') + ';">' + c.render(r) + '</td>';
            }).join('') + '</tr>';
        }).join('');
        $body.html(html);
    }

    function renderTotals(totals) {
        const parts = Object.keys(totals).map(function (cur) {
            const tot = totals[cur];
            return '<div class="postbox" style="display:inline-block;padding:10px 15px;margin:0 10px 10px 0;">'
                + '<strong>' + esc(t('total_currency').replace('%s', cur)) + '</strong> : ' + money(tot.amount, cur)
                + ' · ' + num(tot.qty) + ' ' + esc(t('pieces')) + ' · ' + num(tot.orders) + ' ' + esc(t('orders_word')) + '</div>';
        });
        $('#ispag-sup-totals').html(parts.join(''));
    }

    function renderChart(year) {
        const canvas = document.getElementById('ispag-sup-chart');
        if (!canvas || typeof Chart === 'undefined') { return; }
        if (chartInstance) { chartInstance.destroy(); }

        const top = state.rows.slice().sort((a, b) => b.amount - a.amount).slice(0, 10);
        chartInstance = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: top.map(r => r.name),
                datasets: [
                    { label: t('purchases_year').replace('%s', year), data: top.map(r => r.amount),
                      backgroundColor: 'rgba(54, 162, 235, 0.7)', borderColor: 'rgba(54, 162, 235, 1)', borderWidth: 1 },
                    { label: t('purchases_year').replace('%s', year - 1), data: top.map(r => r.prev_amount),
                      backgroundColor: 'rgba(255, 99, 132, 0.7)', borderColor: 'rgba(255, 99, 132, 1)', borderWidth: 1 }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.label + ': ' + money(ctx.parsed.y, top[ctx.dataIndex].currency);
                            }
                        }
                    }
                },
                scales: { y: { beginAtZero: true, title: { display: true, text: t('chart_axis') } } }
            }
        });
    }

    function loadPage() {
        const year = parseInt($('#ispag-sup-year').val(), 10);
        const onlyProducts = $('#ispag-sup-products').is(':checked');
        state.year = year;

        $('#ispag-sup-body').html(fullRow(t('loading')));
        $('#ispag-sup-totals').empty();

        fetchStats(year, onlyProducts, 0).done(function (response) {
            if (!response.success) {
                $('#ispag-sup-body').html(fullRow(t('error_prefix') + ' ' + ((response.data && response.data.message) || '')));
                return;
            }
            state.rows = response.data.rows;
            renderTotals(response.data.totals);
            renderHead();
            renderBody();
            renderChart(year);
        }).fail(function () {
            $('#ispag-sup-body').html(fullRow(t('error_ajax')));
        });
    }

    if ($('#ispag-sup-body').length) {
        $('#ispag-sup-year, #ispag-sup-products').on('change', loadPage);
        $(document).on('click', '#ispag-sup-head th', function (e) {
            if ($(e.target).closest('.ispag-tip').length) { return; } // pas de tri en cliquant sur l'info-bulle
            const key = $(this).data('sort');
            if (state.sortKey === key) {
                state.sortDir = -state.sortDir;
            } else {
                state.sortKey = key;
                state.sortDir = key === 'name' ? 1 : -1;
            }
            renderHead();
            renderBody();
        });
        renderHead();
        loadPage();
    }

    // --- WIDGET ACCUEIL ADMIN (Top suppliers) ---
    $('.ispag-sup-widget').each(function () {
        const $w = $(this);
        const limit = parseInt($w.data('limit'), 10) || 5;

        fetchStats(cfg.current_year, true, limit).done(function (response) {
            if (!response.success || !response.data.rows.length) {
                $w.html('<p>' + esc(t('no_data')) + '</p>');
                return;
            }
            const rows = response.data.rows.map(function (r) {
                return '<tr><td>' + esc(r.name) + '</td>'
                    + '<td style="text-align:right;">' + money(r.amount, r.currency) + '</td>'
                    + '<td style="text-align:right;">' + num(r.qty) + '</td>'
                    + '<td style="text-align:right;">' + days(r.avg_lead_days) + '</td></tr>';
            }).join('');
            $w.html('<table class="widefat striped"><thead><tr><th>' + esc(t('col_name')) + '</th>'
                + '<th style="text-align:right;">' + esc(t('purchases_year').replace('%s', cfg.current_year)) + '</th>'
                + '<th style="text-align:right;">' + esc(t('qty_short')) + '</th>'
                + '<th style="text-align:right;">' + esc(t('lead_short')) + '</th></tr></thead><tbody>' + rows + '</tbody></table>');
        }).fail(function () {
            $w.html('<p>' + esc(t('error_ajax_short')) + '</p>');
        });
    });
});