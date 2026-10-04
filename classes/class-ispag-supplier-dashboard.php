<?php
defined('ABSPATH') or die();

/**
 * Dashboard "Suppliers" : page admin + widgets pour l'accueil admin.
 * Classe autonome (menu, scripts, AJAX, export CSV).
 * Textes en anglais, traduisibles avec le text domain 'ispag-dashboard'.
 */
class ISPAG_Supplier_Dashboard {

    const PAGE_SLUG    = 'ispag-supplier-stats';
    const NONCE_ACTION = 'ispag-supplier-nonce';
    const CAPABILITY   = 'view_reports';

    protected static $instance = null;
    protected $repo;

    public static function run() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->repo = ISPAG_Supplier_Repository::run();

        // 'view_reports' n'est attribué par aucun rôle : les administrateurs l'obtiennent d'office
        add_filter('user_has_cap', function ($allcaps) {
            if (!empty($allcaps['manage_options'])) {
                $allcaps[self::CAPABILITY] = true;
            }
            return $allcaps;
        });

        add_action('admin_menu', [$this, 'add_admin_menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widgets']);

        add_action('wp_ajax_ispag_pd_get_supplier_stats', [$this, 'ajax_get_supplier_stats']);
        add_action('wp_ajax_ispag_pd_get_supplier_detail', [$this, 'ajax_get_supplier_detail']);
        add_action('wp_ajax_ispag_pd_get_late_deliveries', [$this, 'ajax_get_late_deliveries']);
        add_action('wp_ajax_ispag_pd_export_csv', [$this, 'ajax_export_csv']);
    }

    // ------------------------------------------------------------------
    // Menu, page, widgets
    // ------------------------------------------------------------------

    public function add_admin_menu() {
        add_submenu_page(
            'ispag-entreprises',
            __('Suppliers', 'ispag-dashboard'),
            __('Suppliers', 'ispag-dashboard'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page() {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        include ISPAG_PD_PATH . 'classes/admin/supplier-stats-page.php';
    }

    public function add_dashboard_widgets() {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        wp_add_dashboard_widget('ispag_top_suppliers_widget', __('Top suppliers', 'ispag-dashboard'), [$this, 'render_widget']);
        wp_add_dashboard_widget('ispag_late_deliveries_widget', __('Late supplier deliveries', 'ispag-dashboard'), [$this, 'render_late_widget']);
    }

    public function render_widget() {
        echo '<div class="ispag-sup-widget" data-limit="5"><p>' . esc_html__('Loading…', 'ispag-dashboard') . '</p></div>';
        echo '<p style="text-align:right;margin-bottom:0;"><a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '">'
            . esc_html__('Full supplier dashboard', 'ispag-dashboard') . ' &rarr;</a></p>';
    }

    public function render_late_widget() {
        echo '<div class="ispag-late-widget" data-limit="5"><p>' . esc_html__('Loading…', 'ispag-dashboard') . '</p></div>';
        echo '<p style="text-align:right;margin-bottom:0;"><a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '#ispag-late')) . '">'
            . esc_html__('See all late deliveries', 'ispag-dashboard') . ' &rarr;</a></p>';
    }

    /**
     * Scripts : uniquement sur la page fournisseurs et sur l'accueil admin.
     */
    public function enqueue_scripts($hook) {
        $page    = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $is_page = ($page === self::PAGE_SLUG);
        $is_home = ($hook === 'index.php');

        if ((!$is_page && !$is_home) || !current_user_can(self::CAPABILITY)) {
            return;
        }

        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );

        wp_enqueue_script(
            'ispag-supplier-script',
            ISPAG_PD_URL . 'assets/js/supplier-dashboard.js',
            ['jquery', 'chart-js'],
            '1.1.0',
            true
        );

        wp_localize_script('ispag-supplier-script', 'ispagSupplier', [
            'ajax_url'     => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce(self::NONCE_ACTION),
            'current_year' => (int) date('Y'),
            'locale'       => $this->get_js_locale(),
            'project_url'  => apply_filters('ispag_pd_project_url_base', 'https://app.ispag-asp.ch/projectdetail/'),
            'i18n'         => $this->get_js_strings(),
        ]);
    }

    protected function get_js_locale() {
        $parts = explode('_', determine_locale());
        $tag   = $parts[0];
        if (!empty($parts[1]) && strlen($parts[1]) === 2) {
            $tag .= '-' . $parts[1];
        }
        return $tag;
    }

    /**
     * Chaînes utilisées par le JS et par l'export CSV (littéraux pour les outils de traduction).
     */
    protected function get_js_strings() {
        return [
            // Généralités
            'loading'          => __('Loading…', 'ispag-dashboard'),
            'no_data'          => __('No data available.', 'ispag-dashboard'),
            'no_orders'        => __('No supplier orders for this year.', 'ispag-dashboard'),
            'none'             => __('None.', 'ispag-dashboard'),
            'error_prefix'     => __('Error:', 'ispag-dashboard'),
            'error_ajax'       => __('AJAX connection error (supplier statistics).', 'ispag-dashboard'),
            'error_ajax_short' => __('AJAX connection error.', 'ispag-dashboard'),
            'days_short'       => __('d', 'ispag-dashboard'),
            'pieces'           => __('pieces', 'ispag-dashboard'),
            'orders_word'      => __('orders', 'ispag-dashboard'),
            /* translators: %s: currency code (CHF, EUR…) */
            'total_currency'   => __('Total %s', 'ispag-dashboard'),
            /* translators: %s: year */
            'purchases_year'   => __('Purchases %s', 'ispag-dashboard'),
            'chart_axis'       => __('Amount (supplier currency)', 'ispag-dashboard'),
            'qty_short'        => __('Qty', 'ispag-dashboard'),
            'lead_short'       => __('Avg. lead time', 'ispag-dashboard'),

            // En-têtes de colonnes
            'col_name'         => __('Supplier', 'ispag-dashboard'),
            'col_currency'     => __('Currency', 'ispag-dashboard'),
            'col_amount'       => __('Purchases', 'ispag-dashboard'),
            'col_prev_amount'  => __('Purchases previous year', 'ispag-dashboard'),
            'col_evo_amount'   => __('Purchases evol.', 'ispag-dashboard'),
            'col_share'        => __('Share', 'ispag-dashboard'),
            'col_qty'          => __('Quantity', 'ispag-dashboard'),
            'col_prev_qty'     => __('Quantity previous year', 'ispag-dashboard'),
            'col_evo_qty'      => __('Qty evol.', 'ispag-dashboard'),
            'col_orders'       => __('Orders', 'ispag-dashboard'),
            'col_avg_basket'   => __('Avg. order value', 'ispag-dashboard'),
            'col_avg_unit'     => __('Avg. unit price', 'ispag-dashboard'),
            'col_avg_lead'     => __('Avg. lead time', 'ispag-dashboard'),
            'col_contractual'  => __('Supplier lead time', 'ispag-dashboard'),
            'col_avg_delay'    => __('Avg. delay', 'ispag-dashboard'),
            'col_on_time'      => __('On-time rate', 'ispag-dashboard'),

            // Info-bulles
            'tip_name'         => __('Supplier name. Click it to open the supplier record. Only suppliers with at least one order during the year are listed.', 'ispag-dashboard'),
            'tip_amount'       => __('Amount purchased from this supplier during the year: unit price × quantity, discount deducted, in the supplier\'s currency. The year is the one of the order date.', 'ispag-dashboard'),
            'tip_evo_amount'   => __('Change in purchases compared with the previous year. Empty if the supplier had no purchases last year.', 'ispag-dashboard'),
            'tip_share'        => __('Share of this supplier in the total purchases of the year, computed among suppliers using the same currency.', 'ispag-dashboard'),
            'tip_qty'          => __('Total number of pieces ordered from this supplier during the year.', 'ispag-dashboard'),
            'tip_evo_qty'      => __('Change in ordered quantity compared with the previous year.', 'ispag-dashboard'),
            'tip_orders'       => __('Number of distinct supplier orders placed during the year.', 'ispag-dashboard'),
            'tip_avg_basket'   => __('Purchases divided by the number of orders: average value of an order.', 'ispag-dashboard'),
            'tip_avg_unit'     => __('Purchases divided by quantity: average price of one piece. Useful to spot a price increase from one year to the next.', 'ispag-dashboard'),
            'tip_avg_lead'     => __('Average number of days between the order date and the delivery date (actual date when recorded, otherwise the date confirmed by the supplier, who delivers directly to the site). Only delivered lines are counted.', 'ispag-dashboard'),
            'tip_contractual'  => __('Delivery lead time announced in the supplier record. Used as a reference to compare with the average lead time actually observed.', 'ispag-dashboard'),
            'tip_avg_delay'    => __('Average gap between the actual delivery date and the delivery date confirmed by the supplier. Only lines with both dates are counted. Positive (red) = late, negative (green) = early.', 'ispag-dashboard'),
            'tip_on_time'      => __('Share of lines received on or before the delivery date confirmed by the supplier.', 'ispag-dashboard'),

            // Fiche fournisseur (modale, impression, export)
            'col_year'         => __('Year', 'ispag-dashboard'),
            'col_article'      => __('Article', 'ispag-dashboard'),
            'col_prev_unit'    => __('Unit price previous year', 'ispag-dashboard'),
            'col_price_evo'    => __('Unit price evol.', 'ispag-dashboard'),
            'col_project'      => __('Project', 'ispag-dashboard'),
            'col_order'        => __('Order', 'ispag-dashboard'),
            'col_date'         => __('Date', 'ispag-dashboard'),
            'col_state'        => __('Status', 'ispag-dashboard'),
            'col_received'     => __('Received / ordered', 'ispag-dashboard'),
            'col_open_qty'     => __('Open qty', 'ispag-dashboard'),
            'col_confirmed'    => __('Confirmed date', 'ispag-dashboard'),
            'col_days_late'    => __('Days late', 'ispag-dashboard'),
            'col_open_amount'  => __('Open amount', 'ispag-dashboard'),
            'sec_history'      => __('Last 5 years', 'ispag-dashboard'),
            'sec_articles'     => __('Top 10 articles', 'ispag-dashboard'),
            'sec_projects'     => __('Projects concerned', 'ispag-dashboard'),
            'sec_orders'       => __('Latest orders', 'ispag-dashboard'),
            'sec_late'         => __('Late deliveries for this supplier', 'ispag-dashboard'),
            'btn_export'       => __('Export CSV', 'ispag-dashboard'),
            'btn_print'        => __('Print meeting sheet', 'ispag-dashboard'),
            'sheet_title'      => __('Supplier meeting sheet', 'ispag-dashboard'),
            /* translators: %s: date */
            'sheet_generated'  => __('Generated on %s', 'ispag-dashboard'),
            'print_blocked'    => __('Your browser blocked the print window. Please allow pop-ups for this site.', 'ispag-dashboard'),

            // Retards en cours
            'late_all'         => __('All suppliers', 'ispag-dashboard'),
            /* translators: 1: number of late lines, 2: number of orders, 3: open amount */
            'late_summary'     => __('%1$s late lines across %2$s orders. Open amount: %3$s', 'ispag-dashboard'),
            'late_none'        => __('No late deliveries. 🎉', 'ispag-dashboard'),
        ];
    }

    // ------------------------------------------------------------------
    // Utilitaires AJAX
    // ------------------------------------------------------------------

    protected function guard() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => __('Access denied.', 'ispag-dashboard')], 403);
        }
    }

    /** @return int|false */
    protected function read_year($src) {
        $current = (int) date('Y');
        $year    = isset($src['year']) ? intval($src['year']) : $current;
        return ($year >= 2000 && $year <= $current + 1) ? $year : false;
    }

    protected function read_products($src) {
        return !isset($src['only_products']) || (string) $src['only_products'] === '1';
    }

    protected function sql_error() {
        wp_send_json_error(['message' => __('SQL error while computing supplier statistics.', 'ispag-dashboard')], 500);
    }

    // ------------------------------------------------------------------
    // Endpoints AJAX
    // ------------------------------------------------------------------

    /** POST : year, only_products (1|0), limit (0 = tout) */
    public function ajax_get_supplier_stats() {
        $this->guard();

        $year = $this->read_year($_POST);
        if ($year === false) {
            wp_send_json_error(['message' => __('Invalid year.', 'ispag-dashboard')], 400);
        }
        $limit = isset($_POST['limit']) ? max(0, intval($_POST['limit'])) : 0;

        $data = $this->repo->get_supplier_stats($year, $this->read_products($_POST));
        if (is_wp_error($data)) {
            $this->sql_error();
        }
        if ($limit > 0) {
            $data['rows'] = array_slice($data['rows'], 0, $limit);
        }
        wp_send_json_success($data);
    }

    /** POST : supplier_id, year, only_products */
    public function ajax_get_supplier_detail() {
        $this->guard();

        $year = $this->read_year($_POST);
        $supplier_id = isset($_POST['supplier_id']) ? intval($_POST['supplier_id']) : 0;
        if ($year === false || $supplier_id <= 0) {
            wp_send_json_error(['message' => __('Invalid year.', 'ispag-dashboard')], 400);
        }

        $data = $this->repo->get_supplier_detail($supplier_id, $year, $this->read_products($_POST));
        if (is_wp_error($data)) {
            $this->sql_error();
        }
        wp_send_json_success($data);
    }

    /** POST : only_products, supplier_id (0 = tous), limit (0 = tout) */
    public function ajax_get_late_deliveries() {
        $this->guard();

        $supplier_id = isset($_POST['supplier_id']) ? intval($_POST['supplier_id']) : 0;
        $limit       = isset($_POST['limit']) ? max(0, intval($_POST['limit'])) : 0;

        $data = $this->repo->get_late_deliveries($this->read_products($_POST), $supplier_id);
        if (is_wp_error($data)) {
            $this->sql_error();
        }
        if ($limit > 0) {
            $data['rows'] = array_slice($data['rows'], 0, $limit);
        }
        wp_send_json_success($data);
    }

    // ------------------------------------------------------------------
    // Export CSV  (GET : type = overview | detail | late, year, only_products, supplier_id, nonce)
    // ------------------------------------------------------------------

    public function ajax_export_csv() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Access denied.', 'ispag-dashboard'), '', ['response' => 403]);
        }

        $type        = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : 'overview';
        $year        = $this->read_year($_GET);
        $only        = $this->read_products($_GET);
        $supplier_id = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0;

        if ($year === false) {
            wp_die(esc_html__('Invalid year.', 'ispag-dashboard'), '', ['response' => 400]);
        }

        $s = $this->get_js_strings();

        switch ($type) {
            case 'detail':
                $data = $this->repo->get_supplier_detail($supplier_id, $year, $only);
                $this->die_on_error($data);
                $out = $this->csv_open('supplier-' . $supplier_id . '-' . $year . '.csv');
                $this->csv_detail($out, $data, $s);
                break;

            case 'late':
                $data = $this->repo->get_late_deliveries($only, $supplier_id);
                $this->die_on_error($data);
                $out = $this->csv_open('late-deliveries-' . wp_date('Y-m-d') . '.csv');
                $this->csv_late($out, $data['rows'], $s);
                break;

            default:
                $data = $this->repo->get_supplier_stats($year, $only);
                $this->die_on_error($data);
                $out = $this->csv_open('suppliers-' . $year . '.csv');
                $this->csv_overview($out, $data['rows'], $s);
                break;
        }

        fclose($out);
        exit;
    }

    protected function die_on_error($data) {
        if (is_wp_error($data)) {
            wp_die(esc_html__('SQL error while computing supplier statistics.', 'ispag-dashboard'), '', ['response' => 500]);
        }
    }

    protected function csv_open($filename) {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        echo "\xEF\xBB\xBF"; // BOM UTF-8 pour Excel
        return fopen('php://output', 'w');
    }

    /** Séparateur ';' (Excel FR/CH). Les textes commençant par = + - @ sont neutralisés (injection de formule). */
    protected function csv_row($out, array $row) {
        foreach ($row as $i => $v) {
            if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@") !== false) {
                $row[$i] = "'" . $v;
            }
        }
        fputcsv($out, $row, ';', '"', '\\');
    }

    protected function csv_overview($out, array $rows, array $s) {
        $this->csv_row($out, [
            $s['col_name'], $s['col_currency'], $s['col_amount'], $s['col_prev_amount'], $s['col_evo_amount'], $s['col_share'],
            $s['col_qty'], $s['col_prev_qty'], $s['col_evo_qty'], $s['col_orders'], $s['col_avg_basket'], $s['col_avg_unit'],
            $s['col_avg_lead'], $s['col_contractual'], $s['col_avg_delay'], $s['col_on_time'],
        ]);
        foreach ($rows as $r) {
            $this->csv_row($out, [
                $r['name'], $r['currency'], $r['amount'], $r['prev_amount'], $r['evo_amount'], $r['share'],
                $r['qty'], $r['prev_qty'], $r['evo_qty'], $r['orders'], $r['avg_basket'], $r['avg_unit_price'],
                $r['avg_lead_days'], $r['contractual_days'], $r['avg_delay_days'], $r['on_time_pct'],
            ]);
        }
    }

    protected function csv_late($out, array $rows, array $s) {
        $this->csv_row($out, [
            $s['col_name'], $s['col_currency'], $s['col_order'], $s['col_state'], $s['col_project'], $s['col_article'],
            $s['col_open_qty'], $s['col_confirmed'], $s['col_days_late'], $s['col_open_amount'],
        ]);
        foreach ($rows as $r) {
            $this->csv_row($out, [
                $r['supplier_name'], $r['currency'], $r['order_number'], $r['state'], $r['deal_id'], $r['article'],
                $r['qty_open'], wp_date('Y-m-d', $r['confirmed']), $r['days_late'], $r['amount_open'],
            ]);
        }
    }

    protected function csv_detail($out, array $d, array $s) {
        $this->csv_row($out, [$d['supplier']['name'], $d['supplier']['currency'], $d['year']]);

        $this->csv_row($out, []);
        $this->csv_row($out, [$s['sec_history']]);
        $this->csv_row($out, [$s['col_year'], $s['col_amount'], $s['col_qty'], $s['col_orders'], $s['col_avg_lead'], $s['col_avg_delay'], $s['col_on_time']]);
        foreach ($d['history'] as $h) {
            $this->csv_row($out, [$h['year'], $h['amount'], $h['qty'], $h['orders'], $h['avg_lead_days'], $h['avg_delay_days'], $h['on_time_pct']]);
        }

        $this->csv_row($out, []);
        $this->csv_row($out, [$s['sec_articles']]);
        $this->csv_row($out, [$s['col_article'], $s['col_qty'], $s['col_amount'], $s['col_avg_unit'], $s['col_prev_unit'], $s['col_price_evo']]);
        foreach ($d['articles'] as $a) {
            $this->csv_row($out, [$a['article'], $a['qty'], $a['amount'], $a['avg_unit_price'], $a['prev_unit_price'], $a['price_evo']]);
        }

        $this->csv_row($out, []);
        $this->csv_row($out, [$s['sec_projects']]);
        $this->csv_row($out, [$s['col_project'], $s['col_qty'], $s['col_amount']]);
        foreach ($d['projects'] as $p) {
            $this->csv_row($out, [$p['project_name'] . ' (#' . $p['deal_id'] . ')', $p['qty'], $p['amount']]);
        }

        $this->csv_row($out, []);
        $this->csv_row($out, [$s['sec_orders']]);
        $this->csv_row($out, [$s['col_date'], $s['col_order'], $s['col_state'], $s['col_amount'], $s['col_received'], $s['col_avg_lead'], $s['col_avg_delay']]);
        foreach ($d['orders'] as $o) {
            $this->csv_row($out, [wp_date('Y-m-d', $o['created']), $o['number'], $o['state'], $o['amount'], $o['qty_received'] . ' / ' . $o['qty'], $o['lead_days'], $o['delay_days']]);
        }

        $this->csv_row($out, []);
        $this->csv_row($out, [$s['sec_late']]);
        $this->csv_row($out, [$s['col_order'], $s['col_article'], $s['col_open_qty'], $s['col_confirmed'], $s['col_days_late'], $s['col_open_amount']]);
        foreach ($d['late'] as $l) {
            $this->csv_row($out, [$l['order_number'], $l['article'], $l['qty_open'], wp_date('Y-m-d', $l['confirmed']), $l['days_late'], $l['amount_open']]);
        }
    }
}