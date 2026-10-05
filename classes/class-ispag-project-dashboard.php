<?php
defined('ABSPATH') or die();

/**
 * Page admin « ISPAG stats → Projects » : suivi des projets (livraisons, facturation, achats, marge) par année.
 * Rendu côté serveur ; le tri des colonnes se fait dans le navigateur.
 */
class ISPAG_Project_Dashboard {

    const PAGE_SLUG = 'ispag-project-stats';

    protected static $instance = null;
    protected $repo;

    public static function run() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->repo = ISPAG_Project_Repository::run();
        // Priorité 21 : le menu parent « ISPAG stats » est créé à la priorité 20 par ISPAG_Supplier_Dashboard
        add_action('admin_menu', [$this, 'add_admin_menu'], 21);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function enqueue_scripts($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== self::PAGE_SLUG || !current_user_can(ISPAG_Supplier_Dashboard::CAPABILITY)) {
            return;
        }
        // Même handle que le dashboard fournisseurs : chargé une seule fois
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
    }

    /** Données des graphiques, calculées à partir des lignes du tableau. */
    protected function chart_data(array $projects) {
        $months = array_fill(1, 12, ['sold' => 0.0, 'invoiced' => 0.0]);
        foreach ($projects as $r) {
            if ($r['ordered_at']) {
                $m = (int) wp_date('n', $r['ordered_at']);
                $months[$m]['sold']     += $r['sales_total'];
                $months[$m]['invoiced'] += $r['invoiced'];
            }
        }
        $label = function ($r) {
            return $r['number'] !== '' ? $r['number'] : '#' . $r['deal_id'];
        };

        $by_margin = $projects;
        usort($by_margin, function ($a, $b) { return $b['margin'] <=> $a['margin']; });
        $by_margin = array_slice(array_filter($by_margin, function ($r) { return $r['sales_total'] > 0; }), 0, 10);

        $open = array_filter($projects, function ($r) { return $r['remaining_lines'] > 0; });
        usort($open, function ($a, $b) { return $b['remaining_lines'] <=> $a['remaining_lines']; });
        $open = array_slice($open, 0, 10);

        return [
            'months'   => array_map(function ($i) { return wp_date('M', mktime(0, 0, 0, $i, 1, 2000)); }, range(1, 12)),
            'sold'     => array_map(function ($m) { return round($m['sold'], 2); }, array_values($months)),
            'invoiced' => array_map(function ($m) { return round($m['invoiced'], 2); }, array_values($months)),
            'margin'   => [
                'labels' => array_map($label, array_values($by_margin)),
                'values' => array_map(function ($r) { return $r['margin']; }, array_values($by_margin)),
            ],
            'delivery' => [
                'labels'    => array_map($label, $open),
                'delivered' => array_map(function ($r) { return $r['delivered_lines']; }, $open),
                'pending'   => array_map(function ($r) { return $r['remaining_lines'] - $r['late_lines']; }, $open),
                'late'      => array_map(function ($r) { return $r['late_lines']; }, $open),
            ],
        ];
    }

    public function add_admin_menu() {
        add_submenu_page(
            ISPAG_Supplier_Dashboard::PAGE_SLUG,
            __('Projects', 'ispag-dashboard'),
            __('Projects', 'ispag-dashboard'),
            ISPAG_Supplier_Dashboard::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    protected function money($v, $cur) {
        return esc_html(number_format((float) $v, 2, '.', "'") . ' ' . $cur);
    }

    public function render_page() {
        if (!current_user_can(ISPAG_Supplier_Dashboard::CAPABILITY)) {
            return;
        }
        $current_year = (int) date('Y');
        $year   = isset($_GET['year']) ? absint($_GET['year']) : $current_year;
        if ($year < $current_year - 10 || $year > $current_year + 1) $year = $current_year;
        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'all';
        if (!in_array($status, ['all', 'active', 'closed'], true)) $status = 'all';

        // Montants de vente et marge : réservés à ceux qui voient les prix de vente
        $prices = current_user_can('display_sales_prices');
        $cur    = $this->repo->sales_currency();
        $data   = $this->repo->get_project_stats($year, $status);
        ?>
        <style>
            .ispag-pj-toolbar { margin: 15px 0; display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
            .ispag-pj-kpis { display: flex; gap: 12px; flex-wrap: wrap; margin: 0 0 18px; }
            .ispag-pj-kpi { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 8px 14px; min-width: 130px; }
            .ispag-pj-kpi span { display: block; font-size: 11px; color: #50575e; text-transform: uppercase; }
            .ispag-pj-kpi strong { font-size: 17px; }
            #ispag-pj-table th[data-sort] { cursor: pointer; white-space: nowrap; }
            #ispag-pj-table td.num, #ispag-pj-table th.num { text-align: right; white-space: nowrap; }
            .ispag-pj-bar { background: #dcdcde; border-radius: 3px; height: 6px; width: 90px; margin-top: 3px; }
            .ispag-pj-bar i { display: block; height: 6px; border-radius: 3px; background: #2271b1; }
            .ispag-pj-late { color: #b32d2e; font-weight: 600; }
            .ispag-pj-neg { color: #b32d2e; }
            .ispag-pj-charts { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 16px; margin: 0 0 20px; }
            .ispag-pj-card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 16px 14px; }
            .ispag-pj-card h2 { font-size: 14px; margin: 0 0 2px; padding: 0; }
            .ispag-pj-card p { margin: 0 0 8px; color: #50575e; font-size: 12px; }
            .ispag-pj-canvas { position: relative; height: 280px; }
        </style>
        <div class="wrap">
            <h1><?php echo esc_html__('Project follow-up', 'ispag-dashboard'); ?> 📋</h1>
            <p><?php echo esc_html__('Deliveries, invoicing, purchasing and margin per project. The year is the one of the project order date.', 'ispag-dashboard'); ?></p>

            <form method="get" class="ispag-pj-toolbar">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                <label><strong><?php echo esc_html__('Year', 'ispag-dashboard'); ?></strong>
                    <select name="year" onchange="this.form.submit()">
                        <?php for ($y = $current_year + 1; $y >= $current_year - 5; $y--) : ?>
                            <option value="<?php echo esc_attr($y); ?>" <?php selected($y, $year); ?>><?php echo esc_html($y); ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <label><strong><?php echo esc_html__('Status', 'ispag-dashboard'); ?></strong>
                    <select name="status" onchange="this.form.submit()">
                        <option value="all" <?php selected($status, 'all'); ?>><?php echo esc_html__('All projects', 'ispag-dashboard'); ?></option>
                        <option value="active" <?php selected($status, 'active'); ?>><?php echo esc_html__('Active', 'ispag-dashboard'); ?></option>
                        <option value="closed" <?php selected($status, 'closed'); ?>><?php echo esc_html__('Closed', 'ispag-dashboard'); ?></option>
                    </select>
                </label>
                <input type="search" id="ispag-pj-filter" placeholder="<?php echo esc_attr__('Filter…', 'ispag-dashboard'); ?>">
            </form>

            <?php if (is_wp_error($data)) : ?>
                <div class="notice notice-error"><p><?php echo esc_html__('SQL error while computing project statistics.', 'ispag-dashboard'); ?></p></div>
            <?php else :
                $t = $data['totals']; ?>

                <div class="ispag-pj-kpis">
                    <div class="ispag-pj-kpi"><span><?php echo esc_html__('Projects', 'ispag-dashboard'); ?></span><strong><?php echo (int) $t['projects']; ?></strong> <small>(<?php echo (int) $t['active']; ?> <?php echo esc_html__('active', 'ispag-dashboard'); ?>)</small></div>
                    <?php if ($prices) : ?>
                        <div class="ispag-pj-kpi"><span><?php echo esc_html__('Sold', 'ispag-dashboard'); ?></span><strong><?php echo $this->money($t['sales'], $cur); ?></strong></div>
                        <div class="ispag-pj-kpi"><span><?php echo esc_html__('Invoiced', 'ispag-dashboard'); ?></span><strong><?php echo $this->money($t['invoiced'], $cur); ?></strong></div>
                        <div class="ispag-pj-kpi"><span><?php echo esc_html__('Left to invoice', 'ispag-dashboard'); ?></span><strong><?php echo $this->money($t['to_invoice'], $cur); ?></strong></div>
                    <?php endif; ?>
                    <div class="ispag-pj-kpi"><span><?php echo esc_html__('Lines left to deliver', 'ispag-dashboard'); ?></span><strong><?php echo (int) $t['remaining_lines']; ?></strong></div>
                    <div class="ispag-pj-kpi"><span><?php echo esc_html__('Late lines', 'ispag-dashboard'); ?></span><strong class="<?php echo $t['late_lines'] ? 'ispag-pj-late' : ''; ?>"><?php echo (int) $t['late_lines']; ?></strong></div>
                    <?php if ($prices) : ?>
                        <div class="ispag-pj-kpi"><span><?php echo esc_html__('Purchase cost', 'ispag-dashboard'); ?></span><strong><?php echo $this->money($t['cost'], $cur); ?></strong></div>
                        <div class="ispag-pj-kpi"><span><?php echo esc_html__('Margin', 'ispag-dashboard'); ?></span><strong><?php echo $this->money($t['margin'], $cur); ?></strong>
                            <?php if ($t['margin_pct'] !== null) echo ' <small>(' . esc_html($t['margin_pct']) . ' %)</small>'; ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($prices && $t['foreign_lines'] > 0) : ?>
                    <p class="description">* <?php
                        /* translators: 1: number of lines, 2: currency */
                        echo esc_html(sprintf(__('%1$d purchase line(s) are in a currency other than %2$s and are not converted: the cost and margin of those projects are approximate.', 'ispag-dashboard'), $t['foreign_lines'], $cur)); ?></p>
                <?php endif; ?>

                <?php $charts = $this->chart_data($data['projects']); ?>
                <div class="ispag-pj-charts">
                    <?php if ($prices) : ?>
                    <div class="ispag-pj-card">
                        <h2><?php echo esc_html__('Sold vs invoiced, by order month', 'ispag-dashboard'); ?></h2>
                        <p><?php echo esc_html(sprintf(__('Amounts in %s. Invoiced amounts are attributed to the month the project was ordered.', 'ispag-dashboard'), $cur)); ?></p>
                        <div class="ispag-pj-canvas"><canvas id="ispag-pj-chart-sales" role="img" aria-label="<?php echo esc_attr__('Sold vs invoiced by month', 'ispag-dashboard'); ?>"></canvas></div>
                    </div>
                    <div class="ispag-pj-card">
                        <h2><?php echo esc_html__('Margin, top 10 projects', 'ispag-dashboard'); ?></h2>
                        <p><?php echo esc_html(sprintf(__('Sold minus purchase cost, in %s.', 'ispag-dashboard'), $cur)); ?></p>
                        <div class="ispag-pj-canvas"><canvas id="ispag-pj-chart-margin" role="img" aria-label="<?php echo esc_attr__('Margin of the top 10 projects', 'ispag-dashboard'); ?>"></canvas></div>
                    </div>
                    <?php endif; ?>
                    <div class="ispag-pj-card">
                        <h2><?php echo esc_html__('Deliveries, projects with the most lines left', 'ispag-dashboard'); ?></h2>
                        <p><?php echo esc_html__('Number of project lines: delivered, still to deliver, and late.', 'ispag-dashboard'); ?></p>
                        <div class="ispag-pj-canvas"><canvas id="ispag-pj-chart-delivery" role="img" aria-label="<?php echo esc_attr__('Delivery status of the projects with the most lines left', 'ispag-dashboard'); ?>"></canvas></div>
                    </div>
                </div>

                <table class="widefat striped" id="ispag-pj-table">
                    <thead><tr>
                        <th data-sort="text"><?php echo esc_html__('Project', 'ispag-dashboard'); ?></th>
                        <th data-sort="text"><?php echo esc_html__('Customer', 'ispag-dashboard'); ?></th>
                        <th data-sort="text"><?php echo esc_html__('Manager', 'ispag-dashboard'); ?></th>
                        <th data-sort="num"><?php echo esc_html__('Ordered', 'ispag-dashboard'); ?></th>
                        <?php if ($prices) : ?>
                            <th class="num" data-sort="num"><?php echo esc_html__('Sold', 'ispag-dashboard'); ?></th>
                            <th class="num" data-sort="num"><?php echo esc_html__('Left to invoice', 'ispag-dashboard'); ?></th>
                        <?php endif; ?>
                        <th data-sort="num"><?php echo esc_html__('Delivery', 'ispag-dashboard'); ?></th>
                        <th class="num" data-sort="num"><?php echo esc_html__('Late', 'ispag-dashboard'); ?></th>
                        <th data-sort="num"><?php echo esc_html__('Purchasing', 'ispag-dashboard'); ?></th>
                        <?php if ($prices) : ?>
                            <th class="num" data-sort="num"><?php echo esc_html__('Cost', 'ispag-dashboard'); ?></th>
                            <th class="num" data-sort="num"><?php echo esc_html__('Margin', 'ispag-dashboard'); ?></th>
                        <?php endif; ?>
                    </tr></thead>
                    <tbody>
                    <?php if (!$data['projects']) : ?>
                        <tr><td colspan="11"><?php echo esc_html__('No projects for this year.', 'ispag-dashboard'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($data['projects'] as $r) :
                        $dpct = $r['lines'] > 0 ? round($r['delivered_lines'] / $r['lines'] * 100) : 0; ?>
                        <tr>
                            <td data-v="<?php echo esc_attr($r['number']); ?>"><strong><?php echo esc_html($r['number'] ?: '#' . $r['deal_id']); ?></strong>
                                <?php if (!$r['active']) echo '<small>(' . esc_html__('closed', 'ispag-dashboard') . ')</small>'; ?>
                                <br><small><?php echo esc_html(wp_trim_words($r['subject'], 10)); ?></small></td>
                            <td><?php echo esc_html($r['customer']); ?></td>
                            <td><?php echo esc_html($r['manager']); ?></td>
                            <td data-v="<?php echo (int) $r['ordered_at']; ?>"><?php echo $r['ordered_at'] ? esc_html(wp_date('d.m.Y', $r['ordered_at'])) : '—'; ?></td>
                            <?php if ($prices) : ?>
                                <td class="num" data-v="<?php echo esc_attr($r['sales_total']); ?>"><?php echo $this->money($r['sales_total'], $cur); ?></td>
                                <td class="num" data-v="<?php echo esc_attr($r['to_invoice']); ?>"><?php echo $this->money($r['to_invoice'], $cur); ?></td>
                            <?php endif; ?>
                            <td data-v="<?php echo (int) $dpct; ?>"><?php echo (int) $r['delivered_lines']; ?> / <?php echo (int) $r['lines']; ?>
                                <div class="ispag-pj-bar"><i style="width:<?php echo (int) $dpct; ?>%"></i></div>
                                <?php if ($r['next_delivery']) echo '<small>' . esc_html__('next', 'ispag-dashboard') . ' ' . esc_html(wp_date('d.m.Y', $r['next_delivery'])) . '</small>'; ?></td>
                            <td class="num <?php echo $r['late_lines'] ? 'ispag-pj-late' : ''; ?>" data-v="<?php echo (int) $r['late_lines']; ?>"><?php echo (int) $r['late_lines']; ?></td>
                            <td data-v="<?php echo (int) $r['orders_open']; ?>">
                                <?php echo (int) $r['purchase_orders']; ?> <?php echo esc_html__('orders', 'ispag-dashboard'); ?>
                                <br><small><?php echo (int) $r['orders_open']; ?> <?php echo esc_html__('in progress', 'ispag-dashboard'); ?>, <?php echo (int) $r['orders_received']; ?> <?php echo esc_html__('received', 'ispag-dashboard'); ?>
                                · <?php echo esc_html(rtrim(rtrim(number_format($r['qty_received'], 2, '.', ''), '0'), '.')); ?> / <?php echo esc_html(rtrim(rtrim(number_format($r['qty_ordered'], 2, '.', ''), '0'), '.')); ?> <?php echo esc_html__('pcs', 'ispag-dashboard'); ?></small></td>
                            <?php if ($prices) : ?>
                                <td class="num" data-v="<?php echo esc_attr($r['cost']); ?>"><?php echo $this->money($r['cost'], $cur); ?><?php echo $r['foreign_lines'] ? ' *' : ''; ?></td>
                                <td class="num <?php echo $r['margin'] < 0 ? 'ispag-pj-neg' : ''; ?>" data-v="<?php echo esc_attr($r['margin']); ?>"><?php echo $this->money($r['margin'], $cur); ?>
                                    <?php if ($r['margin_pct'] !== null) echo '<br><small>' . esc_html($r['margin_pct']) . ' %</small>'; ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description"><?php echo esc_html__('Click a column header to sort. Quantities exclude transport and customs lines; costs include them.', 'ispag-dashboard'); ?></p>
            <?php endif; ?>
        </div>
        <?php if (!is_wp_error($data)) : ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof Chart === 'undefined') return;
            var D = <?php echo wp_json_encode($charts); ?>;
            var CUR = <?php echo wp_json_encode($cur); ?>;
            var C1 = '#2a78d6', C2 = '#eb6834', C3 = '#1baf7a';   // palette catégorielle validée (clair)
            var INK = '#50575e', GRID = '#e6e6e6';
            var fmt = function (v) { return Number(v).toLocaleString('fr-CH', {maximumFractionDigits: 0}) + ' ' + CUR; };
            var base = {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: INK, boxWidth: 12, boxHeight: 12 } } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: INK } },
                    y: { grid: { color: GRID }, ticks: { color: INK }, beginAtZero: true }
                }
            };
            var bar = function (id, cfg) { var el = document.getElementById(id); if (el) new Chart(el.getContext('2d'), cfg); };
            var ds = function (label, data, color, extra) {
                return Object.assign({ label: label, data: data, backgroundColor: color, borderColor: color, borderWidth: 0,
                    borderRadius: 4, maxBarThickness: 26 }, extra || {});
            };
            var money = { tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + fmt(c.parsed.y !== undefined && c.chart.options.indexAxis !== 'y' ? c.parsed.y : c.parsed.x); } } } };

            bar('ispag-pj-chart-sales', { type: 'bar',
                data: { labels: D.months, datasets: [ds('<?php echo esc_js(__('Sold', 'ispag-dashboard')); ?>', D.sold, C1), ds('<?php echo esc_js(__('Invoiced', 'ispag-dashboard')); ?>', D.invoiced, C2)] },
                options: Object.assign({}, base, { plugins: Object.assign({}, base.plugins, money) }) });

            bar('ispag-pj-chart-margin', { type: 'bar',
                data: { labels: D.margin.labels, datasets: [ds('<?php echo esc_js(__('Margin', 'ispag-dashboard')); ?>', D.margin.values, C1)] },
                options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y',
                    plugins: Object.assign({}, base.plugins, { legend: { display: false } }, money),
                    scales: { x: { grid: { color: GRID }, ticks: { color: INK } }, y: { grid: { display: false }, ticks: { color: INK } } } } });

            bar('ispag-pj-chart-delivery', { type: 'bar',
                data: { labels: D.delivery.labels, datasets: [
                    ds('<?php echo esc_js(__('Delivered', 'ispag-dashboard')); ?>', D.delivery.delivered, C1, { stack: 's', borderRadius: 0, borderColor: '#fff', borderWidth: 1 }),
                    ds('<?php echo esc_js(__('To deliver', 'ispag-dashboard')); ?>', D.delivery.pending, C3, { stack: 's', borderRadius: 0, borderColor: '#fff', borderWidth: 1 }),
                    ds('<?php echo esc_js(__('Late', 'ispag-dashboard')); ?>', D.delivery.late, C2, { stack: 's', borderRadius: 0, borderColor: '#fff', borderWidth: 1 })] },
                options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: base.plugins,
                    scales: { x: { stacked: true, grid: { color: GRID }, ticks: { color: INK, precision: 0 } }, y: { stacked: true, grid: { display: false }, ticks: { color: INK } } } } });
        });
        </script>
        <?php endif; ?>
        <script>
        (function () {
            var table = document.getElementById('ispag-pj-table');
            if (!table) return;
            var body = table.tBodies[0];
            table.tHead.addEventListener('click', function (e) {
                var th = e.target.closest('th[data-sort]');
                if (!th) return;
                var idx = Array.prototype.indexOf.call(th.parentNode.children, th);
                var num = th.getAttribute('data-sort') === 'num';
                var dir = th.getAttribute('data-dir') === 'asc' ? -1 : 1;
                Array.prototype.forEach.call(th.parentNode.children, function (h) { h.removeAttribute('data-dir'); });
                th.setAttribute('data-dir', dir === 1 ? 'asc' : 'desc');
                var rows = Array.prototype.slice.call(body.rows);
                var val = function (r) {
                    var c = r.cells[idx]; if (!c) return '';
                    var v = c.hasAttribute('data-v') ? c.getAttribute('data-v') : c.textContent.trim();
                    return num ? (parseFloat(v) || 0) : v.toLowerCase();
                };
                rows.sort(function (a, b) { var x = val(a), y = val(b); return (x > y ? 1 : x < y ? -1 : 0) * dir; });
                rows.forEach(function (r) { body.appendChild(r); });
            });
            var f = document.getElementById('ispag-pj-filter');
            if (f) f.addEventListener('input', function () {
                var q = f.value.toLowerCase();
                Array.prototype.forEach.call(body.rows, function (r) { r.style.display = r.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : ''; });
            });
            // Le champ de filtre ne doit pas soumettre le formulaire
            if (f && f.form) f.form.addEventListener('submit', function (e) { if (document.activeElement === f) e.preventDefault(); });
        })();
        </script>
        <?php
    }
}
