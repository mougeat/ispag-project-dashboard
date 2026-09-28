jQuery(function ($) {
    if (typeof ispagSupplier === 'undefined') { return; }
    const cfg = ispagSupplier;
    const I18N = cfg.i18n || {};
    const LOCALE = cfg.locale || 'en-US';

    // ------------------------------------------------------------------
    // UTILS
    // ------------------------------------------------------------------
    function t(key) { return I18N[key] !== undefined ? I18N[key] : key; }

    /** sprintf minimal : %s et %1$s, %2$s… */
    function sprintf(str) {
        const args = Array.prototype.slice.call(arguments, 1);
        let i = 0;
        return String(str).replace(/%(?:(\d+)\$)?s/g, function (m, pos) {
            const idx = pos ? parseInt(pos, 10) - 1 : i++;
            return args[idx] !== undefined ? args[idx] : '';
        });
    }

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

    function pct(v) { return v === null || v === undefined ? '—' : num(v, 1) + ' %'; }
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

    function dateFmt(ts) {
        if (!ts) { return '—'; }
        try { return new Date(ts * 1000).toLocaleDateString(LOCALE); }
        catch (e) { return new Date(ts * 1000).toLocaleDateString(); }
    }

    function projectLink(dealId, label) {
        if (!dealId) { return esc(label || '—'); }
        const href = (cfg.project_url || '') + encodeURIComponent(dealId);
        return '<a href="' + escAttr(href) + '" target="_blank" rel="noopener">' + esc(label || ('#' + dealId)) + '</a>';
    }

    function csvUrl(params) {
        return cfg.ajax_url + '?' + $.param($.extend({ action: 'ispag_pd_export_csv', nonce: cfg.nonce }, params));
    }

    function post(action, data) {
        return $.post(cfg.ajax_url, $.extend({ action: action, nonce: cfg.nonce }, data));
    }

    function fetchStats(year, onlyProducts, limit) {
        return post('ispag_pd_get_supplier_stats', { year: year, only_products: onlyProducts ? 1 : 0, limit: limit || 0 });
    }

    function fetchLate(onlyProducts, supplierId, limit) {
        return post('ispag_pd_get_late_deliveries', { only_products: onlyProducts ? 1 : 0, supplier_id: supplierId || 0, limit: limit || 0 });
    }

    function fullRow(cols, text) { return '<tr><td colspan="' + cols + '">' + esc(text) + '</td></tr>'; }

    /** Tableau simple : cols = [{label: clé i18n, right: bool}], rows = tableaux de cellules HTML */
    function simpleTable(cols, rows) {
        if (!rows.length) { return '<p class="description">' + esc(t('none')) + '</p>'; }
        const head = cols.map(function (c) {
            return '<th style="text-align:' + (c.right ? 'right' : 'left') + ';">' + esc(t(c.label)) + '</th>';
        }).join('');
        const body = rows.map(function (r) {
            return '<tr>' + r.map(function (cell, i) {
                return '<td style="text-align:' + (cols[i].right ? 'right' : 'left') + ';">' + cell + '</td>';
            }).join('') + '</tr>';
        }).join('');
        return '<table class="widefat striped"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>';
    }

    // ------------------------------------------------------------------
    // PAGE : VUE D'ENSEMBLE
    // ------------------------------------------------------------------
    const COLS = [
        { key: 'name',             label: 'col_name',        tip: 'tip_name',        left: true,
          render: r => '<a href="#" class="ispag-sup-open" data-id="' + r.supplier_id + '" data-name="' + escAttr(r.name) + '">' + esc(r.name) + '</a>' },
        { key: 'amount',           label: 'col_amount',      tip: 'tip_amount',      render: r => '<strong>' + money(r.amount, r.currency) + '</strong>' },
        { key: 'evo_amount',       label: 'col_evo_amount',  tip: 'tip_evo_amount',  render: r => evo(r.evo_amount) },
        { key: 'share',            label: 'col_share',       tip: 'tip_share',       render: r => pct(r.share) },
        { key: 'qty',              label: 'col_qty',         tip: 'tip_qty',         render: r => num(r.qty) },
        { key: 'evo_qty',          label: 'col_evo_qty',     tip: 'tip_evo_qty',     render: r => evo(r.evo_qty) },
        { key: 'orders',           label: 'col_orders',      tip: 'tip_orders',      render: r => num(r.orders) },
        { key: 'avg_basket',       label: 'col_avg_basket',  tip: 'tip_avg_basket',  render: r => money(r.avg_basket, r.currency) },
        { key: 'avg_unit_price',   label: 'col_avg_unit',    tip: 'tip_avg_unit',    render: r => money(r.avg_unit_price, r.currency, 2) },
        { key: 'avg_lead_days',    label: 'col_avg_lead',    tip: 'tip_avg_lead',    render: r => days(r.avg_lead_days) },
        { key: 'contractual_days', label: 'col_contractual', tip: 'tip_contractual', render: r => r.contractual_days === null ? '—' : r.contractual_days + ' ' + t('days_short') },
        { key: 'avg_delay_days',   label: 'col_avg_delay',   tip: 'tip_avg_delay',   render: r => delay(r.avg_delay_days) },
        { key: 'on_time_pct',      label: 'col_on_time',     tip: 'tip_on_time',     render: r => pct(r.on_time_pct) }
    ];

    const state = {
        rows: [], sortKey: 'amount', sortDir: -1, year: cfg.current_year,
        late: [], lateSupplier: 0, detail: null
    };
    let chartInstance = null;

    function onlyProducts() { return $('#ispag-sup-products').is(':checked'); }

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

    function renderBody() {
        const $body = $('#ispag-sup-body');
        if (!state.rows.length) {
            $body.html(fullRow(COLS.length, t('no_orders')));
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
                + '<strong>' + esc(sprintf(t('total_currency'), cur)) + '</strong> : ' + money(tot.amount, cur)
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
                    { label: sprintf(t('purchases_year'), year), data: top.map(r => r.amount),
                      backgroundColor: 'rgba(54, 162, 235, 0.7)', borderColor: 'rgba(54, 162, 235, 1)', borderWidth: 1 },
                    { label: sprintf(t('purchases_year'), year - 1), data: top.map(r => r.prev_amount),
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
        state.year = year;

        $('#ispag-sup-export').attr('href', csvUrl({ type: 'overview', year: year, only_products: onlyProducts() ? 1 : 0 }));
        $('#ispag-sup-body').html(fullRow(COLS.length, t('loading')));
        $('#ispag-sup-totals').empty();

        fetchStats(year, onlyProducts(), 0).done(function (response) {
            if (!response.success) {
                $('#ispag-sup-body').html(fullRow(COLS.length, t('error_prefix') + ' ' + ((response.data && response.data.message) || '')));
                return;
            }
            state.rows = response.data.rows;
            renderTotals(response.data.totals);
            renderHead();
            renderBody();
            renderChart(year);
        }).fail(function () {
            $('#ispag-sup-body').html(fullRow(COLS.length, t('error_ajax')));
        });
    }

    // ------------------------------------------------------------------
    // PAGE : RETARDS EN COURS
    // ------------------------------------------------------------------
    const LATE_COLS = [
        { label: 'col_name' }, { label: 'col_order' }, { label: 'col_project' }, { label: 'col_article' },
        { label: 'col_open_qty', right: true }, { label: 'col_confirmed' },
        { label: 'col_days_late', right: true }, { label: 'col_open_amount', right: true }
    ];

    function lateSummaryText(rows) {
        const orders = {}, byCur = {};
        rows.forEach(function (r) {
            orders[r.order_id] = true;
            byCur[r.currency] = (byCur[r.currency] || 0) + r.amount_open;
        });
        const amounts = Object.keys(byCur).map(cur => money(byCur[cur], cur)).join(' + ') || '—';
        return sprintf(t('late_summary'), num(rows.length), num(Object.keys(orders).length), amounts);
    }

    function renderLate() {
        const rows = state.lateSupplier
            ? state.late.filter(r => r.supplier_id === state.lateSupplier)
            : state.late;

        $('#ispag-late-head').html(LATE_COLS.map(function (c) {
            return '<th style="text-align:' + (c.right ? 'right' : 'left') + ';">' + esc(t(c.label)) + '</th>';
        }).join(''));
        $('#ispag-late-export').attr('href', csvUrl({ type: 'late', year: state.year, supplier_id: state.lateSupplier || 0, only_products: onlyProducts() ? 1 : 0 }));

        if (!rows.length) {
            $('#ispag-late-summary').empty();
            $('#ispag-late-body').html(fullRow(LATE_COLS.length, t('late_none')));
            return;
        }
        $('#ispag-late-summary').text(lateSummaryText(rows));
        $('#ispag-late-body').html(rows.map(function (r) {
            return '<tr>'
                + '<td>' + esc(r.supplier_name) + '</td>'
                + '<td>' + esc(r.order_number) + '</td>'
                + '<td>' + projectLink(r.deal_id, r.deal_id ? '#' + r.deal_id : '') + '</td>'
                + '<td>' + esc(r.article) + '</td>'
                + '<td style="text-align:right;">' + num(r.qty_open) + '</td>'
                + '<td>' + dateFmt(r.confirmed) + '</td>'
                + '<td style="text-align:right;color:#d63638;font-weight:600;">' + num(r.days_late) + ' ' + esc(t('days_short')) + '</td>'
                + '<td style="text-align:right;">' + money(r.amount_open, r.currency) + '</td>'
                + '</tr>';
        }).join(''));
    }

    function renderLateFilter() {
        const seen = {};
        state.late.forEach(function (r) { seen[r.supplier_id] = r.supplier_name; });
        const options = Object.keys(seen)
            .sort((a, b) => seen[a].localeCompare(seen[b], LOCALE))
            .map(id => '<option value="' + id + '">' + esc(seen[id]) + '</option>');
        $('#ispag-late-filter').html('<option value="0">' + esc(t('late_all')) + '</option>' + options.join(''));
        $('#ispag-late-filter').val(String(state.lateSupplier || 0));
        if ($('#ispag-late-filter').val() === null) { state.lateSupplier = 0; $('#ispag-late-filter').val('0'); }
    }

    function loadLate() {
        $('#ispag-late-body').html(fullRow(LATE_COLS.length, t('loading')));
        fetchLate(onlyProducts(), 0, 0).done(function (response) {
            if (!response.success) {
                $('#ispag-late-body').html(fullRow(LATE_COLS.length, t('error_prefix') + ' ' + ((response.data && response.data.message) || '')));
                return;
            }
            state.late = response.data.rows;
            renderLateFilter();
            renderLate();
        }).fail(function () {
            $('#ispag-late-body').html(fullRow(LATE_COLS.length, t('error_ajax')));
        });
    }

    // ------------------------------------------------------------------
    // FICHE FOURNISSEUR (modale + impression)
    // ------------------------------------------------------------------
    function detailHtml(d) {
        const cur = d.supplier.currency;
        const h0 = d.history[0] || {};

        const kpis = [
            [t('col_amount'), money(h0.amount, cur)],
            [t('col_qty'), num(h0.qty)],
            [t('col_orders'), num(h0.orders)],
            [t('col_avg_lead'), days(h0.avg_lead_days)],
            [t('col_contractual'), d.supplier.contractual_days === null ? '—' : d.supplier.contractual_days + ' ' + t('days_short')],
            [t('col_avg_delay'), delay(h0.avg_delay_days)],
            [t('col_on_time'), pct(h0.on_time_pct)]
        ].map(function (k) {
            return '<div class="ispag-kpi"><span>' + esc(k[0]) + ' (' + d.year + ')</span><strong>' + k[1] + '</strong></div>';
        }).join('');

        const history = simpleTable(
            [{ label: 'col_year' }, { label: 'col_amount', right: true }, { label: 'col_qty', right: true }, { label: 'col_orders', right: true },
             { label: 'col_avg_lead', right: true }, { label: 'col_avg_delay', right: true }, { label: 'col_on_time', right: true }],
            d.history.map(h => [String(h.year), money(h.amount, cur), num(h.qty), num(h.orders), days(h.avg_lead_days), delay(h.avg_delay_days), pct(h.on_time_pct)])
        );

        const articles = simpleTable(
            [{ label: 'col_article' }, { label: 'col_qty', right: true }, { label: 'col_amount', right: true },
             { label: 'col_avg_unit', right: true }, { label: 'col_prev_unit', right: true }, { label: 'col_price_evo', right: true }],
            d.articles.map(a => [esc(a.article), num(a.qty), money(a.amount, cur), money(a.avg_unit_price, cur, 2), money(a.prev_unit_price, cur, 2), evo(a.price_evo)])
        );

        const projects = simpleTable(
            [{ label: 'col_project' }, { label: 'col_qty', right: true }, { label: 'col_amount', right: true }],
            d.projects.map(p => [projectLink(p.deal_id, p.project_name), num(p.qty), money(p.amount, cur)])
        );

        const orders = simpleTable(
            [{ label: 'col_date' }, { label: 'col_order' }, { label: 'col_state' }, { label: 'col_amount', right: true },
             { label: 'col_received', right: true }, { label: 'col_avg_lead', right: true }, { label: 'col_avg_delay', right: true }],
            d.orders.map(o => [dateFmt(o.created), esc(o.number) + (o.ref ? ' <span class="description">' + esc(o.ref) + '</span>' : ''),
                esc(o.state), money(o.amount, cur), num(o.qty_received) + ' / ' + num(o.qty), days(o.lead_days), delay(o.delay_days)])
        );

        const late = simpleTable(
            [{ label: 'col_order' }, { label: 'col_article' }, { label: 'col_open_qty', right: true }, { label: 'col_confirmed' },
             { label: 'col_days_late', right: true }, { label: 'col_open_amount', right: true }],
            d.late.map(l => [esc(l.order_number), esc(l.article), num(l.qty_open), dateFmt(l.confirmed),
                '<span style="color:#d63638;font-weight:600;">' + num(l.days_late) + ' ' + esc(t('days_short')) + '</span>', money(l.amount_open, l.currency)])
        );

        return '<div class="ispag-kpis">' + kpis + '</div>'
            + '<h3>' + esc(t('sec_late')) + '</h3>' + late
            + '<h3>' + esc(t('sec_history')) + '</h3>' + history
            + '<h3>' + esc(t('sec_articles')) + ' (' + d.year + ')</h3>' + articles
            + '<h3>' + esc(t('sec_projects')) + ' (' + d.year + ')</h3>' + projects
            + '<h3>' + esc(t('sec_orders')) + ' (' + d.year + ')</h3>' + orders;
    }

    function openSupplier(id, name) {
        state.detail = null;
        $('#ispag-sup-modal-title').text(name);
        $('#ispag-sup-modal-body').html('<p>' + esc(t('loading')) + '</p>');
        $('#ispag-sup-modal-export').attr('href', csvUrl({ type: 'detail', supplier_id: id, year: state.year, only_products: onlyProducts() ? 1 : 0 }));
        $('#ispag-sup-modal').fadeIn(120);

        post('ispag_pd_get_supplier_detail', { supplier_id: id, year: state.year, only_products: onlyProducts() ? 1 : 0 })
            .done(function (response) {
                if (!response.success) {
                    $('#ispag-sup-modal-body').html('<p>' + esc(t('error_prefix') + ' ' + ((response.data && response.data.message) || '')) + '</p>');
                    return;
                }
                state.detail = response.data;
                $('#ispag-sup-modal-title').text(response.data.supplier.name + ' · ' + response.data.supplier.currency);
                $('#ispag-sup-modal-body').html(detailHtml(response.data));
            })
            .fail(function () {
                $('#ispag-sup-modal-body').html('<p>' + esc(t('error_ajax_short')) + '</p>');
            });
    }

    function closeModal() { $('#ispag-sup-modal').fadeOut(120); }

    /** Fiche RDV : nouvelle fenêtre mise en page pour l'impression (ou l'enregistrement en PDF). */
    function printSheet() {
        const d = state.detail;
        if (!d) { return; }
        const w = window.open('', '_blank');
        if (!w) { window.alert(t('print_blocked')); return; }

        const css = 'body{font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:12px;color:#1d2327;margin:24px;}'
            + 'h1{font-size:20px;margin:0 0 4px;} h3{font-size:14px;margin:18px 0 6px;border-bottom:2px solid #d32f2f;padding-bottom:3px;}'
            + '.meta{color:#50575e;margin:0 0 12px;} table{width:100%;border-collapse:collapse;margin-bottom:6px;}'
            + 'th,td{border-bottom:1px solid #dcdcde;padding:4px 6px;font-size:11px;} th{background:#f6f7f7;}'
            + '.ispag-kpis{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0;} .ispag-kpi{border:1px solid #dcdcde;padding:6px 10px;min-width:110px;}'
            + '.ispag-kpi span{display:block;font-size:9px;text-transform:uppercase;color:#50575e;} .ispag-kpi strong{font-size:14px;}'
            + '.description{color:#787c82;} a{color:inherit;text-decoration:none;} h3,table{page-break-inside:avoid;}';

        w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>'
            + esc(t('sheet_title') + ' - ' + d.supplier.name) + '</title><style>' + css + '</style></head><body>'
            + '<h1>' + esc(t('sheet_title')) + ' — ' + esc(d.supplier.name) + '</h1>'
            + '<p class="meta">' + esc(sprintf(t('sheet_generated'), new Date().toLocaleDateString(LOCALE))) + ' · ' + esc(d.supplier.currency) + '</p>'
            + detailHtml(d) + '</body></html>');
        w.document.close();
        w.focus();
        setTimeout(function () { w.print(); }, 300);
    }

    // ------------------------------------------------------------------
    // INITIALISATION PAGE
    // ------------------------------------------------------------------
    if ($('#ispag-sup-body').length) {
        $('#ispag-sup-year').on('change', function () { loadPage(); });
        $('#ispag-sup-products').on('change', function () { loadPage(); loadLate(); });

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

        $(document).on('click', 'a.ispag-sup-open', function (e) {
            e.preventDefault();
            openSupplier(parseInt($(this).data('id'), 10), String($(this).data('name')));
        });

        $('#ispag-late-filter').on('change', function () {
            state.lateSupplier = parseInt($(this).val(), 10) || 0;
            renderLate();
        });

        $('#ispag-sup-modal-print').on('click', printSheet);
        $(document).on('click', '.ispag-modal-close', closeModal);
        $('#ispag-sup-modal').on('click', function (e) { if (e.target === this) { closeModal(); } });
        $(document).on('keyup', function (e) { if (e.key === 'Escape') { closeModal(); } });

        renderHead();
        loadPage();
        loadLate();
    }

    // ------------------------------------------------------------------
    // WIDGETS ACCUEIL ADMIN
    // ------------------------------------------------------------------
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
                + '<th style="text-align:right;">' + esc(sprintf(t('purchases_year'), cfg.current_year)) + '</th>'
                + '<th style="text-align:right;">' + esc(t('qty_short')) + '</th>'
                + '<th style="text-align:right;">' + esc(t('lead_short')) + '</th></tr></thead><tbody>' + rows + '</tbody></table>');
        }).fail(function () {
            $w.html('<p>' + esc(t('error_ajax_short')) + '</p>');
        });
    });

    $('.ispag-late-widget').each(function () {
        const $w = $(this);
        const limit = parseInt($w.data('limit'), 10) || 5;

        fetchLate(true, 0, limit).done(function (response) {
            if (!response.success) {
                $w.html('<p>' + esc(t('error_ajax_short')) + '</p>');
                return;
            }
            const rows = response.data.rows;
            const s = response.data.summary;
            if (!rows.length) {
                $w.html('<p>' + esc(t('late_none')) + '</p>');
                return;
            }
            const amounts = Object.keys(s.by_currency).map(cur => money(s.by_currency[cur], cur)).join(' + ') || '—';
            const body = rows.map(function (r) {
                return '<tr><td>' + esc(r.supplier_name) + '</td><td>' + esc(r.article) + '</td>'
                    + '<td style="text-align:right;color:#d63638;font-weight:600;">' + num(r.days_late) + ' ' + esc(t('days_short')) + '</td></tr>';
            }).join('');
            $w.html('<p><strong>' + esc(sprintf(t('late_summary'), num(s.lines), num(s.orders), amounts)) + '</strong></p>'
                + '<table class="widefat striped"><thead><tr><th>' + esc(t('col_name')) + '</th><th>' + esc(t('col_article'))
                + '</th><th style="text-align:right;">' + esc(t('col_days_late')) + '</th></tr></thead><tbody>' + body + '</tbody></table>');
        }).fail(function () {
            $w.html('<p>' + esc(t('error_ajax_short')) + '</p>');
        });
    });
});