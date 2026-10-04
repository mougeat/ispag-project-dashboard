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
