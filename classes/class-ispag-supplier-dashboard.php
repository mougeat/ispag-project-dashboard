<?php
defined('ABSPATH') or die();

/**
 * Dashboard "Suppliers" : page admin + widget pour l'accueil admin.
 * Classe autonome (menu, scripts, AJAX) pour ne pas toucher aux classes existantes.
 * Textes en anglais, traduisibles avec le text domain 'ispag-project-dashboard'.
 */
class ISPAG_Supplier_Dashboard {

    const MAIN_PAGE_SLUG = 'ispag-stats';            // Slug du menu principal "ISPAG Stats"
    const PAGE_SLUG      = 'ispag-supplier-stats';     // Slug du sous-menu "Suppliers"
    const NONCE_ACTION   = 'ispag-supplier-nonce';
    const CAPABILITY     = 'view_reports';

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

        add_action('admin_menu', [$this, 'add_admin_menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
        add_action('wp_ajax_ispag_pd_get_supplier_stats', [$this, 'ajax_get_supplier_stats']);
    }

    public function add_admin_menu() {
        // 1. Création du menu principal "ISPAG Stats" positionné au-dessus de CRM ISPAG
        add_menu_page(
            __('ISPAG Stats', 'ispag-project-dashboard'),
            __('ISPAG Stats', 'ispag-project-dashboard'),
            self::CAPABILITY,
            self::MAIN_PAGE_SLUG,
            [$this, 'render_main_stats_page'],
            'dashicons-chart-bar',
            5
        );

        // 2. Premier sous-menu : "Suppliers"
        add_submenu_page(
            self::MAIN_PAGE_SLUG,
            __('Suppliers Stats', 'ispag-project-dashboard'),
            __('Suppliers', 'ispag-project-dashboard'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    /**
     * Rendu de la page principale : par défaut, on redirige vers le sous-menu Suppliers 
     * pour que le clic sur le menu parent mène directement à des données utiles.
     */
    public function render_main_stats_page() {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        // Redirection automatique vers le sous-menu Suppliers
        global $submenu;
        if (isset($submenu[self::MAIN_PAGE_SLUG][0][2])) {
            $redirect_url = admin_url('admin.php?page=' . $submenu[self::MAIN_PAGE_SLUG][0][2]);
            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Rendu de la page sous-menu "Suppliers"
     */
    public function render_page() {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        include ISPAG_PD_PATH . 'classes/admin/supplier-stats-page.php';
    }

    /**
     * Widget "Top suppliers" pour la page d'accueil admin.
     */
    public function add_dashboard_widget() {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        wp_add_dashboard_widget(
            'ispag_top_suppliers_widget',
            __('Top suppliers', 'ispag-project-dashboard'),
            [$this, 'render_widget']
        );
    }

    public function render_widget() {
        echo '<div class="ispag-sup-widget" data-limit="5">';
        echo '<p>' . esc_html__('Loading…', 'ispag-project-dashboard') . '</p>';
        echo '</div>';
        echo '<p style="text-align:right;margin-bottom:0;"><a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '">'
            . esc_html__('Full supplier dashboard', 'ispag-project-dashboard') . ' &rarr;</a></p>';
    }

    /**
     * Scripts : uniquement sur la page fournisseurs et sur l'accueil admin.
     */
    public function enqueue_scripts($hook) {
        $page    = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $is_page = ($page === self::PAGE_SLUG || $page === self::MAIN_PAGE_SLUG);
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
            '1.0.1',
            true
        );

        wp_localize_script('ispag-supplier-script', 'ispagSupplier', [
            'ajax_url'     => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce(self::NONCE_ACTION),
            'current_year' => (int) date('Y'),
            'locale'       => $this->get_js_locale(),
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

    protected function get_js_strings() {
        return [
            'loading'          => __('Loading…', 'ispag-project-dashboard'),
            'no_data'          => __('No data available.', 'ispag-project-dashboard'),
            'no_orders'        => __('No supplier orders for this year.', 'ispag-project-dashboard'),
            'error_prefix'     => __('Error:', 'ispag-project-dashboard'),
            'error_ajax'       => __('AJAX connection error (supplier statistics).', 'ispag-project-dashboard'),
            'error_ajax_short' => __('AJAX connection error.', 'ispag-project-dashboard'),
            'days_short'       => __('d', 'ispag-project-dashboard'),
            'pieces'           => __('pieces', 'ispag-project-dashboard'),
            'orders_word'      => __('orders', 'ispag-project-dashboard'),
            /* translators: %s: currency code (CHF, EUR…) */
            'total_currency'   => __('Total %s', 'ispag-project-dashboard'),
            /* translators: %s: year */
            'purchases_year'   => __('Purchases %s', 'ispag-project-dashboard'),
            'chart_axis'       => __('Amount (supplier currency)', 'ispag-project-dashboard'),
            'qty_short'        => __('Qty', 'ispag-project-dashboard'),
            'lead_short'       => __('Avg. lead time', 'ispag-project-dashboard'),

            'col_name'         => __('Supplier', 'ispag-project-dashboard'),
            'col_amount'       => __('Purchases', 'ispag-project-dashboard'),
            'col_evo_amount'   => __('Purchases evol.', 'ispag-project-dashboard'),
            'col_share'        => __('Share', 'ispag-project-dashboard'),
            'col_qty'          => __('Quantity', 'ispag-project-dashboard'),
            'col_evo_qty'      => __('Qty evol.', 'ispag-project-dashboard'),
            'col_orders'       => __('Orders', 'ispag-project-dashboard'),
            'col_avg_basket'   => __('Avg. order value', 'ispag-project-dashboard'),
            'col_avg_unit'     => __('Avg. unit price', 'ispag-project-dashboard'),
            'col_avg_lead'     => __('Avg. lead time', 'ispag-project-dashboard'),
            'col_contractual'  => __('Supplier lead time', 'ispag-project-dashboard'),
            'col_avg_delay'    => __('Avg. delay', 'ispag-project-dashboard'),
            'col_on_time'      => __('On-time rate', 'ispag-project-dashboard'),

            'tip_name'         => __('Supplier name. Only suppliers with at least one order during the year are listed.', 'ispag-project-dashboard'),
            'tip_amount'       => __('Amount purchased from this supplier during the year: unit price × quantity, discount deducted, in the supplier\'s currency. The year is the one of the order date.', 'ispag-project-dashboard'),
            'tip_evo_amount'   => __('Change in purchases compared with the previous year. Empty if the supplier had no purchases last year.', 'ispag-project-dashboard'),
            'tip_share'        => __('Share of this supplier in the total purchases of the year, computed among suppliers using the same currency.', 'ispag-project-dashboard'),
            'tip_qty'          => __('Total number of pieces ordered from this supplier during the year.', 'ispag-project-dashboard'),
            'tip_evo_qty'      => __('Change in ordered quantity compared with the previous year.', 'ispag-project-dashboard'),
            'tip_orders'       => __('Number of distinct supplier orders placed during the year.', 'ispag-project-dashboard'),
            'tip_avg_basket'   => __('Purchases divided by the number of orders: average value of an order.', 'ispag-project-dashboard'),
            'tip_avg_unit'     => __('Purchases divided by quantity: average price of one piece. Useful to spot a price increase from one year to the next.', 'ispag-project-dashboard'),
            'tip_avg_lead'     => __('Average number of days between the order date and the actual receipt of the goods. Only lines already received are counted.', 'ispag-project-dashboard'),
            'tip_contractual'  => __('Delivery lead time announced in the supplier record. Used as a reference to compare with the average lead time actually observed.', 'ispag-project-dashboard'),
            'tip_avg_delay'    => __('Average gap between the actual receipt and the delivery date confirmed by the supplier. Positive (red) = late, negative (green) = early.', 'ispag-project-dashboard'),
            'tip_on_time'      => __('Share of lines received on or before the delivery date confirmed by the supplier.', 'ispag-project-dashboard'),
        ];
    }

    /**
     * AJAX : statistiques fournisseurs d'une année.
     * POST : year, only_products (1|0), limit (0 = tout)
     */
    public function ajax_get_supplier_stats() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => __('Access denied.', 'ispag-project-dashboard')], 403);
        }

        $current_year = (int) date('Y');
        $year = isset($_POST['year']) ? intval($_POST['year']) : $current_year;
        if ($year < 2000 || $year > $current_year + 1) {
            wp_send_json_error(['message' => __('Invalid year.', 'ispag-project-dashboard')], 400);
        }

        $only_products = !isset($_POST['only_products']) || $_POST['only_products'] === '1';
        $limit         = isset($_POST['limit']) ? max(0, intval($_POST['limit'])) : 0;

        $data = $this->repo->get_supplier_stats($year, $only_products);
        if (is_wp_error($data)) {
            wp_send_json_error(['message' => __('SQL error while computing supplier statistics.', 'ispag-project-dashboard')], 500);
        }

        // Correction : on ne découpe le tableau que si $limit est strictement supérieur à 0
        if ($limit > 0) {
            $data['rows'] = array_slice($data['rows'], 0, $limit);
        }

        wp_send_json_success($data);
    }
}