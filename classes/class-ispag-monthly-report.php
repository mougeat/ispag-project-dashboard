<?php
defined('ABSPATH') or die();

/**
 * Page admin « ISPAG stats → Monthly report » : remplace le tableau Excel mensuel.
 *
 * Quatre blocs, mois en lignes et années en colonnes, avec total et variation sur l'année précédente.
 * Tout vient du CRM : ispag_deals_list contient un document par ligne (colonne process_type), rattaché à un deal
 * (deal_group_ref) ; les lignes « ignorer les statistiques » (is_copie = 1) sont exclues partout.
 *  - Entrée de commande : documents « Commande », une commande par deal (dernière version), montant HT
 *                         au mois de création du premier document « Commande » du deal ;
 *  - Facturation        : documents « Situation… » et « Facture » (la « Facture interne » est exclue), montant HT
 *                         au mois de création du document ;
 *  - Rédaction d'offres : nombre d'offres (une par deal), au mois de création ;
 *  - Note de crédit     : documents dont le type contient « crédit » ou « avoir » ; une valeur saisie à la main
 *                         pour un mois remplace celle du CRM.
 * Les objectifs mensuels (commandes, facturation) sont saisis à la main.
 *
 * Valeurs manuelles : option ispag_pd_monthly_manual = ['credit' => [année => [12]], 'goal_orders' => [année => [12]],
 * 'goal_invoicing' => [année => [12]]].
 */
class ISPAG_Monthly_Report {

    const PAGE_SLUG = 'ispag-monthly-report';
    const OPTION    = 'ispag_pd_monthly_manual';
    const YEARS     = 8; // nombre d'années affichées en colonnes

    protected static $instance = null;
    protected $wpdb;

    public static function run() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        add_action('admin_menu', [$this, 'add_admin_menu'], 22);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_post_ispag_pd_save_monthly', [$this, 'handle_save']);
        add_action('admin_post_ispag_pd_export_monthly', [$this, 'handle_export']);
    }

    public function add_admin_menu() {
        add_submenu_page(
            ISPAG_Supplier_Dashboard::PAGE_SLUG,
            __('Monthly report', 'ispag-dashboard'),
            __('Monthly report', 'ispag-dashboard'),
            ISPAG_Supplier_Dashboard::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function enqueue_scripts($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== self::PAGE_SLUG || !current_user_can(ISPAG_Supplier_Dashboard::CAPABILITY)) {
            return;
        }
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
    }

    private function t($name) {
        return $this->wpdb->prefix . $name;
    }

    // ------------------------------------------------------------------ données

    /** @return int[] années affichées, de la plus ancienne à la plus récente */
    protected function years($year) {
        return range($year - self::YEARS + 1, $year);
    }

    /** Résultat de requête (ym, v) -> [année => [1..12 => valeur]] */
    protected function bucket(array $rows, array $years) {
        $out = [];
        foreach ($years as $y) {
            $out[$y] = array_fill(1, 12, 0.0);
        }
        foreach ($rows as $r) {
            $y = (int) substr($r['ym'], 0, 4);
            $m = (int) substr($r['ym'], 5, 2);
            if (isset($out[$y]) && $m >= 1 && $m <= 12) {
                $out[$y][$m] += (float) $r['v'];
            }
        }
        return $out;
    }

    protected function window($years) {
        return [strtotime(reset($years) . '-01-01 00:00:00'), strtotime((end($years) + 1) . '-01-01 00:00:00')];
    }

    /** Montant HT d'une ligne de ispag_deals_list (colonne texte : apostrophes et virgules tolérées). */
    protected function amount_sql($alias = 'd') {
        return "CAST(REPLACE(REPLACE(NULLIF({$alias}.total_excl_vat, ''), CHAR(39), ''), ',', '.') AS DECIMAL(14,2))";
    }

    /** Entrée de commande : une commande par deal (dernière version), au mois du premier document « Commande ». */
    protected function orders(array $years) {
        $from = reset($years) . '-01-01';
        $to   = (end($years) + 1) . '-01-01';
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT DATE_FORMAT(g.first_date, '%%Y-%%m') AS ym, SUM(" . $this->amount_sql('o') . ") AS v
             FROM (
                 SELECT MIN(date_creation) AS first_date, MAX(id) AS last_id
                 FROM {$this->t('ispag_deals_list')}
                 WHERE process_type = 'Commande' AND is_copie = 0
                 GROUP BY COALESCE(NULLIF(deal_group_ref, ''), CONCAT('id', id))
             ) g
             INNER JOIN {$this->t('ispag_deals_list')} o ON o.id = g.last_id
             WHERE g.first_date >= %s AND g.first_date < %s
             GROUP BY ym",
            $from, $to
        ), ARRAY_A);
        return $this->bucket((array) $rows, $years);
    }

    /** Facturation : situations et factures (hors facture interne), au mois de création du document. */
    protected function invoicing(array $years) {
        $from = reset($years) . '-01-01';
        $to   = (end($years) + 1) . '-01-01';
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT DATE_FORMAT(d.date_creation, '%%Y-%%m') AS ym, SUM(" . $this->amount_sql('d') . ") AS v
             FROM {$this->t('ispag_deals_list')} d
             WHERE d.is_copie = 0
               AND (d.process_type LIKE 'Situation%%' OR d.process_type = 'Facture')
               AND d.date_creation >= %s AND d.date_creation < %s
             GROUP BY ym",
            $from, $to
        ), ARRAY_A);
        return $this->bucket((array) $rows, $years);
    }

    /** Notes de crédit du CRM (montant en valeur absolue), au mois de création du document. */
    protected function credit_notes(array $years) {
        $from = reset($years) . '-01-01';
        $to   = (end($years) + 1) . '-01-01';
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT DATE_FORMAT(d.date_creation, '%%Y-%%m') AS ym, SUM(ABS(" . $this->amount_sql('d') . ")) AS v
             FROM {$this->t('ispag_deals_list')} d
             WHERE d.is_copie = 0
               AND (d.process_type LIKE '%%cr_dit%%' OR d.process_type LIKE '%%avoir%%')
               AND d.date_creation >= %s AND d.date_creation < %s
             GROUP BY ym",
            $from, $to
        ), ARRAY_A);
        return $this->bucket((array) $rows, $years);
    }

    /** Types de document présents pour l'année affichée (contrôle de la correspondance avec les blocs). */
    protected function process_types($year) {
        return (array) $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT COALESCE(NULLIF(process_type, ''), '(vide)') AS type, COUNT(*) AS n, SUM(is_copie = 1) AS copies
             FROM {$this->t('ispag_deals_list')}
             WHERE date_creation >= %s AND date_creation < %s
             GROUP BY type ORDER BY n DESC",
            $year . '-01-01', ($year + 1) . '-01-01'
        ), ARRAY_A);
    }

    /** Une valeur saisie à la main (non nulle) remplace celle du CRM. */
    protected function merge_manual(array $auto, array $manual) {
        foreach ($manual as $y => $months) {
            foreach ($months as $i => $v) {
                if ($v != 0) $auto[$y][$i] = $v;
            }
        }
        return $auto;
    }

    /** Rédaction d'offres : une offre par groupe de deal, au mois de création. */
    protected function offers(array $years) {
        $from = reset($years) . '-01-01';
        $to   = (end($years) + 1) . '-01-01';
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT DATE_FORMAT(date_creation, '%%Y-%%m') AS ym,
                    COUNT(DISTINCT COALESCE(NULLIF(deal_group_ref, ''), CONCAT('id', id))) AS v
             FROM {$this->t('ispag_deals_list')}
             WHERE date_creation >= %s AND date_creation < %s AND is_copie = 0
             GROUP BY ym",
            $from, $to
        ), ARRAY_A);
        return $this->bucket((array) $rows, $years);
    }

    protected function manual() {
        $m = get_option(self::OPTION, []);
        return is_array($m) ? $m : [];
    }

    /** Valeurs manuelles d'une série pour les années demandées : [année => [1..12]] */
    protected function manual_series($key, array $years) {
        $m = $this->manual();
        $out = [];
        foreach ($years as $y) {
            $out[$y] = array_fill(1, 12, 0.0);
            if (!empty($m[$key][$y]) && is_array($m[$key][$y])) {
                for ($i = 1; $i <= 12; $i++) {
                    $out[$y][$i] = (float) ($m[$key][$y][$i] ?? 0);
                }
            }
        }
        return $out;
    }

    protected function report($year) {
        $years   = $this->years($year);
        $prices  = current_user_can('display_sales_prices');
        return [
            'years'    => $years,
            'prices'   => $prices,
            'orders'   => $prices ? $this->orders($years) : null,
            'invoicing' => $prices ? $this->invoicing($years) : null,
            'offers'   => $this->offers($years),
            'credit'   => $prices ? $this->merge_manual($this->credit_notes($years), $this->manual_series('credit', $years)) : null,
            'goal_orders'    => $prices ? $this->manual_series('goal_orders', [$year]) : null,
            'goal_invoicing' => $prices ? $this->manual_series('goal_invoicing', [$year]) : null,
        ];
    }

    // ------------------------------------------------------------------ enregistrement & export

    public function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', 'ispag-dashboard'), '', ['response' => 403]);
        }
        check_admin_referer('ispag_pd_save_monthly');
        $year = isset($_POST['year']) ? absint($_POST['year']) : (int) date('Y');
        $m = $this->manual();

        $read = function ($field) {
            $src = isset($_POST[$field]) && is_array($_POST[$field]) ? wp_unslash($_POST[$field]) : [];
            $out = [];
            foreach ($src as $y => $months) {
                $y = (int) $y;
                if (!is_array($months)) continue;
                for ($i = 1; $i <= 12; $i++) {
                    $v = isset($months[$i]) ? str_replace(["'", ' ', ','], ['', '', '.'], (string) $months[$i]) : '';
                    $out[$y][$i] = is_numeric($v) ? (float) $v : 0.0;
                }
            }
            return $out;
        };
        foreach (['credit', 'goal_orders', 'goal_invoicing'] as $key) {
            foreach ($read($key) as $y => $vals) {
                $m[$key][$y] = $vals;
            }
        }
        update_option(self::OPTION, $m, false);
        wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'year' => $year, 'saved' => 1], admin_url('admin.php')));
        exit;
    }

    public function handle_export() {
        if (!current_user_can(ISPAG_Supplier_Dashboard::CAPABILITY)) {
            wp_die(esc_html__('Access denied.', 'ispag-dashboard'), '', ['response' => 403]);
        }
        check_admin_referer('ispag_pd_export_monthly');
        $year = isset($_GET['year']) ? absint($_GET['year']) : (int) date('Y');
        $r    = $this->report($year);
        $names = $this->month_names();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ispag-monthly-report-' . $year . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM : Excel lit l'UTF-8
        $out = fopen('php://output', 'w');
        $blocks = [
            'orders'    => __('Orders received', 'ispag-dashboard'),
            'invoicing' => __('Invoicing', 'ispag-dashboard'),
            'offers'    => __('Offers written', 'ispag-dashboard'),
            'credit'    => __('Credit notes', 'ispag-dashboard'),
        ];
        foreach ($blocks as $key => $title) {
            if ($r[$key] === null) continue;
            fputcsv($out, [$title], ';');
            fputcsv($out, array_merge([''], $r['years']), ';');
            for ($i = 1; $i <= 12; $i++) {
                $row = [$names[$i]];
                foreach ($r['years'] as $y) $row[] = round($r[$key][$y][$i], 2);
                fputcsv($out, $row, ';');
            }
            $row = [__('Total', 'ispag-dashboard')];
            foreach ($r['years'] as $y) $row[] = round(array_sum($r[$key][$y]), 2);
            fputcsv($out, $row, ';');
            fputcsv($out, [], ';');
        }
        fclose($out);
        exit;
    }

    // ------------------------------------------------------------------ affichage

    protected function month_names() {
        $n = [];
        for ($i = 1; $i <= 12; $i++) {
            $n[$i] = wp_date('M', mktime(0, 0, 0, $i, 1, 2000));
        }
        return $n;
    }

    protected function fmt($v, $decimals = 0) {
        return $v == 0 ? '—' : number_format((float) $v, $decimals, '.', "'");
    }

    /** Variation en % du total de l'année par rapport à la précédente. */
    protected function yoy($cur, $prev) {
        if ($prev <= 0) return '';
        $p = ($cur / $prev - 1) * 100;
        $cls = $p >= 0 ? 'ispag-mr-up' : 'ispag-mr-down';
        return '<span class="' . $cls . '">' . esc_html(($p >= 0 ? '+' : '') . number_format($p, 1, '.', '')) . ' %</span>';
    }

    /**
     * Un bloc du tableau. $goal : [mois => valeur] de l'année affichée (objectif) ou null ; $edit : champs de saisie.
     */
    protected function render_block($title, $key, array $data, array $years, $year, $money, $goal = null, $goal_key = '', $edit = false, $edit_key = '') {
        $names = $this->month_names();
        ?>
        <div class="ispag-mr-card">
            <h2><?php echo esc_html($title); ?></h2>
            <div class="ispag-mr-canvas"><canvas id="ispag-mr-chart-<?php echo esc_attr($key); ?>" role="img" aria-label="<?php echo esc_attr($title); ?>"></canvas></div>
            <div style="overflow-x:auto">
            <table class="widefat striped ispag-mr-table">
                <thead><tr>
                    <th></th>
                    <?php foreach ($years as $y) echo '<th class="num' . ($y === $year ? ' cur' : '') . '">' . esc_html($y) . '</th>'; ?>
                    <?php if ($goal !== null) : ?>
                        <th class="num"><?php echo esc_html(sprintf(__('Goal %d', 'ispag-dashboard'), $year)); ?></th>
                        <th class="num"><?php echo esc_html__('% of total', 'ispag-dashboard'); ?></th>
                    <?php endif; ?>
                </tr></thead>
                <tbody>
                <?php $goal_total = $goal !== null ? array_sum($goal) : 0;
                for ($i = 1; $i <= 12; $i++) : ?>
                    <tr>
                        <th><?php echo esc_html($names[$i]); ?></th>
                        <?php foreach ($years as $y) :
                            if ($edit && $edit_key === 'credit') {
                                echo '<td class="num"><input type="text" inputmode="decimal" name="credit[' . (int) $y . '][' . $i . ']" value="' . esc_attr($data[$y][$i] ? $data[$y][$i] : '') . '"></td>';
                            } else {
                                echo '<td class="num' . ($y === $year ? ' cur' : '') . '">' . esc_html($this->fmt($data[$y][$i], $money ? 2 : 0)) . '</td>';
                            }
                        endforeach; ?>
                        <?php if ($goal !== null) : ?>
                            <td class="num"><?php if ($edit) : ?>
                                <input type="text" inputmode="decimal" name="<?php echo esc_attr($goal_key); ?>[<?php echo (int) $year; ?>][<?php echo $i; ?>]" value="<?php echo esc_attr($goal[$i] ? $goal[$i] : ''); ?>">
                            <?php else : echo esc_html($this->fmt($goal[$i], 2)); endif; ?></td>
                            <td class="num"><?php echo $goal_total > 0 ? esc_html(number_format($goal[$i] / $goal_total * 100, 2, '.', '')) . ' %' : '—'; ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th><?php echo esc_html__('Total', 'ispag-dashboard'); ?></th>
                        <?php foreach ($years as $y) echo '<td class="num' . ($y === $year ? ' cur' : '') . '"><strong>' . esc_html($this->fmt(array_sum($data[$y]), $money ? 2 : 0)) . '</strong></td>'; ?>
                        <?php if ($goal !== null) : ?>
                            <td class="num"><strong><?php echo esc_html($this->fmt($goal_total, 2)); ?></strong></td>
                            <td></td>
                        <?php endif; ?>
                    </tr>
                    <tr>
                        <th>Δ</th>
                        <?php foreach ($years as $idx => $y) echo '<td class="num">' . ($idx > 0 ? $this->yoy(array_sum($data[$y]), array_sum($data[$years[$idx - 1]])) : '') . '</td>'; ?>
                        <?php if ($goal !== null) : ?>
                            <td class="num"><?php echo $goal_total > 0 ? $this->yoy(array_sum($data[$year]), $goal_total) : ''; ?></td><td></td>
                        <?php endif; ?>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>
        <?php
    }

    public function render_page() {
        if (!current_user_can(ISPAG_Supplier_Dashboard::CAPABILITY)) {
            return;
        }
        $current_year = (int) date('Y');
        $year = isset($_GET['year']) ? absint($_GET['year']) : $current_year;
        if ($year < $current_year - 12 || $year > $current_year + 1) $year = $current_year;
        $r       = $this->report($year);
        $years   = $r['years'];
        $prices  = $r['prices'];
        $can_edit = $prices && current_user_can('manage_options');
        $cur     = strtoupper(trim((string) get_option('wpcb_currency', 'CHF'))) ?: 'CHF';
        $prev    = $year - 1;
        ?>
        <style>
            .ispag-mr-toolbar { margin: 15px 0; display: flex; gap: 14px; align-items: center; flex-wrap: wrap; }
            .ispag-mr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 820px), 1fr)); gap: 18px; }
            .ispag-mr-card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 16px 14px; min-width: 0; }
            .ispag-mr-card h2 { font-size: 15px; margin: 0 0 8px; padding: 0; }
            .ispag-mr-canvas { position: relative; height: 220px; margin-bottom: 10px; }
            .ispag-mr-table th, .ispag-mr-table td { padding: 4px 8px; font-size: 12px; }
            .ispag-mr-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
            .ispag-mr-table .cur { background: #f0f6fc; }
            .ispag-mr-table input { width: 88px; text-align: right; font-size: 12px; padding: 1px 4px; }
            .ispag-mr-table tfoot td, .ispag-mr-table tfoot th { border-top: 2px solid #2271b1; }
            .ispag-mr-up { color: #00a32a; } .ispag-mr-down { color: #b32d2e; }
        </style>
        <div class="wrap">
            <h1><?php echo esc_html__('Monthly report', 'ispag-dashboard'); ?> 📅</h1>
            <p><?php echo esc_html__('Orders received, invoicing, offers written and credit notes, month by month and year by year.', 'ispag-dashboard'); ?></p>

            <?php if (!empty($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Saved.', 'ispag-dashboard'); ?></p></div>
            <?php endif; ?>

            <div class="ispag-mr-toolbar">
                <form method="get">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                    <label><strong><?php echo esc_html__('Year', 'ispag-dashboard'); ?></strong>
                        <select name="year" onchange="this.form.submit()">
                            <?php for ($y = $current_year + 1; $y >= $current_year - 6; $y--) : ?>
                                <option value="<?php echo esc_attr($y); ?>" <?php selected($y, $year); ?>><?php echo esc_html($y); ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                </form>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ispag_pd_export_monthly&year=' . $year), 'ispag_pd_export_monthly')); ?>"><?php echo esc_html__('Export CSV', 'ispag-dashboard'); ?></a>
            </div>

            <?php if ($can_edit) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ispag_pd_save_monthly">
                <input type="hidden" name="year" value="<?php echo esc_attr($year); ?>">
                <?php wp_nonce_field('ispag_pd_save_monthly'); ?>
            <?php endif; ?>

            <div class="ispag-mr-grid">
                <?php if ($prices) {
                    $this->render_block(sprintf(__('Orders received (%s)', 'ispag-dashboard'), $cur), 'orders', $r['orders'], $years, $year, true,
                        $r['goal_orders'][$year], 'goal_orders', $can_edit);
                    $this->render_block(sprintf(__('Invoicing (%s)', 'ispag-dashboard'), $cur), 'invoicing', $r['invoicing'], $years, $year, true,
                        $r['goal_invoicing'][$year], 'goal_invoicing', $can_edit);
                } ?>
                <?php $this->render_block(__('Offers written', 'ispag-dashboard'), 'offers', $r['offers'], $years, $year, false); ?>
                <?php if ($prices) $this->render_block(sprintf(__('Credit notes (%s)', 'ispag-dashboard'), $cur), 'credit', $r['credit'], $years, $year, true, null, '', $can_edit, 'credit'); ?>
            </div>

            <?php if ($can_edit) : ?>
                <p><button type="submit" class="button button-primary"><?php echo esc_html__('Save goals and credit notes', 'ispag-dashboard'); ?></button></p>
            </form>
            <?php endif; ?>

            <p class="description">
                <?php echo esc_html__('Everything comes from the CRM deal documents. Orders: "Commande" documents, one per deal (latest version), in the month the first order document was created. Invoicing: "Situation" and "Facture" documents (internal invoices excluded), in the month of their creation. Offers: one per deal, in the month of creation. Credit notes: documents whose type contains "crédit" or "avoir"; an amount typed in below replaces the CRM one for that month. Documents flagged "ignore statistics" are always excluded. Goals are typed in.', 'ispag-dashboard'); ?>
            </p>
            <?php if (current_user_can('manage_options')) : $types = $this->process_types($year); ?>
                <details style="margin-top:10px">
                    <summary><?php echo esc_html(sprintf(__('Document types found in the CRM for %d (to check the mapping)', 'ispag-dashboard'), $year)); ?></summary>
                    <table class="widefat striped" style="max-width:520px;margin-top:6px">
                        <thead><tr><th><?php echo esc_html__('Type', 'ispag-dashboard'); ?></th><th class="num"><?php echo esc_html__('Documents', 'ispag-dashboard'); ?></th><th class="num"><?php echo esc_html__('Ignored (statistics)', 'ispag-dashboard'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($types as $t) : ?>
                            <tr><td><?php echo esc_html($t['type']); ?></td><td class="num"><?php echo (int) $t['n']; ?></td><td class="num"><?php echo (int) $t['copies']; ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$types) echo '<tr><td colspan="3">—</td></tr>'; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof Chart === 'undefined') return;
            var months = <?php echo wp_json_encode(array_values($this->month_names())); ?>;
            var series = <?php echo wp_json_encode([
                'orders'    => $prices ? ['cur' => array_values($r['orders'][$year]), 'prev' => array_values($r['orders'][$prev] ?? array_fill(0, 12, 0)), 'goal' => array_values($r['goal_orders'][$year])] : null,
                'invoicing' => $prices ? ['cur' => array_values($r['invoicing'][$year]), 'prev' => array_values($r['invoicing'][$prev] ?? array_fill(0, 12, 0)), 'goal' => array_values($r['goal_invoicing'][$year])] : null,
                'offers'    => ['cur' => array_values($r['offers'][$year]), 'prev' => array_values($r['offers'][$prev] ?? array_fill(0, 12, 0)), 'goal' => null],
                'credit'    => $prices ? ['cur' => array_values($r['credit'][$year]), 'prev' => array_values($r['credit'][$prev] ?? array_fill(0, 12, 0)), 'goal' => null] : null,
            ]); ?>;
            var YEAR = <?php echo (int) $year; ?>, PREV = <?php echo (int) $prev; ?>;
            var C1 = '#2a78d6', C2 = '#eb6834', INK = '#50575e', GRID = '#e6e6e6';
            Object.keys(series).forEach(function (k) {
                var s = series[k], el = document.getElementById('ispag-mr-chart-' + k);
                if (!s || !el) return;
                var ds = [
                    { label: String(YEAR), data: s.cur, backgroundColor: C1, borderRadius: 4, maxBarThickness: 22, order: 2 },
                    { label: String(PREV), data: s.prev, backgroundColor: C2, borderRadius: 4, maxBarThickness: 22, order: 3 }
                ];
                if (s.goal && s.goal.some(function (v) { return v > 0; })) {
                    ds.push({ type: 'line', label: '<?php echo esc_js(__('Goal', 'ispag-dashboard')); ?>', data: s.goal.map(function (v) { return v > 0 ? v : null; }), borderColor: INK, backgroundColor: INK,
                        borderWidth: 2, borderDash: [6, 4], pointRadius: 3, pointBackgroundColor: '#fff', pointBorderColor: INK, tension: 0, order: 1 });
                }
                new Chart(el.getContext('2d'), { type: 'bar', data: { labels: months, datasets: ds },
                    options: { responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom', labels: { color: INK, boxWidth: 12, boxHeight: 12 } } },
                        scales: { x: { grid: { display: false }, ticks: { color: INK } },
                                  y: { beginAtZero: true, grid: { color: GRID }, ticks: { color: INK } } } } });
            });
        });
        </script>
        <?php
    }
}
