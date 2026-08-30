<?php
defined('ABSPATH') or die();

class ISPAG_Project_Dashboard {

    protected static $instance = null;
    protected $ajax_handler;

    public static function run() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->ajax_handler = ISPAG_Project_Dashboard_Ajax::run();

        // Ajouter la page d'administration
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Enregistrer les scripts et styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);

        // Ajouter les widgets au dashboard admin
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widgets']);

        // Chargement AJAX des widgets
        add_action('wp_ajax_ispag_load_dashboard_widget', [$this, 'load_dashboard_widget_ajax']);
    }

    // ======================
    // NOUVELLES MÉTHODES POUR LE DASHBOARD ADMIN (OPTIMISÉES)
    // ======================

    /**
     * Ajoute les widgets au dashboard admin (version légère)
     */
    public function add_dashboard_widgets() {
        // Désactiver les widgets natifs de WordPress (optionnel)
        remove_meta_box('dashboard_right_now', 'dashboard', 'normal');
        remove_meta_box('dashboard_activity', 'dashboard', 'normal');
        remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
        remove_meta_box('dashboard_primary', 'dashboard', 'side');
        remove_meta_box('dashboard_secondary', 'dashboard', 'side');

        // Widget pour les entrées de commande (chargé en AJAX)
        // wp_add_dashboard_widget(
        //     'ispag_order_intake_widget',
        //     'Entrées de Commande (Mensuel/Cumulé)',
        //     [$this, 'render_widget_placeholder']
        // );

        // // Widget pour les projets à facturer (chargé en AJAX)
        // wp_add_dashboard_widget(
        //     'ispag_projects_to_invoice_widget',
        //     'Projets à Facturer',
        //     [$this, 'render_widget_placeholder']
        // );

        // // Widget pour les prévisions de livraison (chargé en AJAX)
        // wp_add_dashboard_widget(
        //     'ispag_delivery_forecast_widget',
        //     'Prévisions de Livraison (6 mois)',
        //     [$this, 'render_widget_placeholder']
        // );

        // // Widget pour les statistiques de facturation (chargé en AJAX)
        // wp_add_dashboard_widget(
        //     'ispag_invoice_stats_widget',
        //     'Statistiques de Facturation',
        //     [$this, 'render_widget_placeholder']
        // );
    }

    /**
     * Affiche un placeholder pour les widgets (chargés en AJAX)
     */
    public function render_widget_placeholder() {
        echo '<div class="ispag-widget-placeholder" style="text-align: center; padding: 20px;">';
        echo '<span class="spinner is-active" style="float: none; margin: 0 auto;"></span>';
        echo '<p>Chargement en cours...</p>';
        echo '</div>';
    }

    /**
     * Charge un widget via AJAX
     */
    public function load_dashboard_widget_ajax() {
        check_ajax_referer('ispag-dashboard-nonce', 'nonce');

        $widget = isset($_POST['widget']) ? sanitize_text_field($_POST['widget']) : '';
        $year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');

        $response = ['success' => false, 'html' => ''];

        switch ($widget) {
            case 'order_intake':
                $response['html'] = $this->get_order_intake_widget_html($year);
                break;
            case 'projects_to_invoice':
                $response['html'] = $this->get_projects_to_invoice_widget_html();
                break;
            case 'delivery_forecast':
                $response['html'] = $this->get_delivery_forecast_widget_html();
                break;
            case 'invoice_stats':
                $response['html'] = $this->get_invoice_stats_widget_html($year);
                break;
            default:
                $response['html'] = '<p>Widget inconnu.</p>';
        }

        $response['success'] = true;
        wp_send_json($response);
    }

    /**
     * Génère le HTML du widget "Prévisions de Livraison" (avec cache)
     */
    protected function get_delivery_forecast_widget_html() {
        $cache_key = 'ispag_delivery_forecast_widget';
        $html = get_transient($cache_key);

        if (false === $html) {
            $forecast = $this->ajax_handler->get_delivery_forecast(true);
            ob_start();
            $this->render_delivery_forecast_widget_data($forecast);
            $html = ob_get_clean();
            set_transient($cache_key, $html, 6 * HOUR_IN_SECONDS); // Cache pour 6 heures
        }

        return $html;
    }

    /**
     * Affiche les données du widget "Prévisions de Livraison"
     */
    protected function render_delivery_forecast_widget_data($forecast) {
        if (empty($forecast)) {
            echo '<p>Aucune donnée disponible.</p>';
            return;
        }

        // Limiter à 6 mois pour accélérer l'affichage
        $forecast = array_slice($forecast, -6);

        echo '<div style="height: 300px; width: 100%;">';
        echo '<canvas id="ispag_delivery_forecast_chart"></canvas>';
        echo '</div>';

        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                const ctx = document.getElementById("ispag_delivery_forecast_chart").getContext("2d");
                const labels = ' . json_encode(array_column($forecast, 'month_label')) . ';
                const data = ' . json_encode(array_column($forecast, 'total_amount')) . ';

                new Chart(ctx, {
                    type: "bar",
                    data: {
                        labels: labels,
                        datasets: [{
                            label: "Montant à livrer (CHF)",
                            data: data,
                            backgroundColor: "rgba(54, 162, 235, 0.5)",
                            borderColor: "rgba(54, 162, 235, 1)",
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: "Montant (CHF)"
                                }
                            }
                        }
                    }
                });
            });
        </script>';
    }

    /**
     * Génère le HTML du widget "Entrées de Commande" (avec cache)
     */
    protected function get_order_intake_widget_html($year) {
        $cache_key = "ispag_order_intake_widget_{$year}";
        $html = get_transient($cache_key);

        if (false === $html) {
            $data = $this->ajax_handler->get_order_intake_stats(true, $year);
            ob_start();
            $this->render_order_intake_widget_data($data);
            $html = ob_get_clean();
            set_transient($cache_key, $html, HOUR_IN_SECONDS); // Cache pour 1 heure
        }

        return $html;
    }

    /**
     * Affiche les données du widget "Entrées de Commande"
     */
    protected function render_order_intake_widget_data($data) {
        if (empty($data['monthly'])) {
            echo '<p>Aucune donnée disponible.</p>';
            return;
        }

        echo '<table class="widefat">';
        echo '<thead><tr><th>Mois</th><th>Entrées</th><th>Objectif</th><th>Écart</th></tr></thead>';
        foreach ($data['monthly'] as $item) {
            $ecart = $item['intake'] - $item['target'];
            $ecart_class = $ecart >= 0 ? 'style="color: green;"' : 'style="color: red;"';
            echo '<tr>';
            echo '<td>' . esc_html($item['label']) . '</td>';
            echo '<td>' . esc_html(number_format($item['intake'], 0, '.', ' ')) . '</td>';
            echo '<td>' . esc_html(number_format($item['target'], 0, '.', ' ')) . '</td>';
            echo '<td ' . $ecart_class . '>' . esc_html(number_format($ecart, 0, '.', ' ')) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    /**
     * Génère le HTML du widget "Projets à Facturer" (avec cache)
     */
    protected function get_projects_to_invoice_widget_html() {
        $cache_key = 'ispag_projects_to_invoice_widget';
        $html = get_transient($cache_key);

        if (false === $html) {
            $projects = $this->ajax_handler->get_projects_to_invoice(true);
            ob_start();
            $this->render_projects_to_invoice_widget_data($projects);
            $html = ob_get_clean();
            set_transient($cache_key, $html, HOUR_IN_SECONDS); // Cache pour 1 heure
        }

        return $html;
    }

    /**
     * Affiche les données du widget "Projets à Facturer"
     */
    protected function render_projects_to_invoice_widget_data($projects) {
        if (empty($projects)) {
            echo '<p>Aucun projet à facturer.</p>';
            return;
        }

        // Limiter à 10 projets pour éviter un tableau trop long
        $projects = array_slice($projects, 0, 10);

        echo '<table class="widefat">';
        echo '<thead><tr><th>Projet</th><th>Montant (CHF)</th></tr></thead>';
        foreach ($projects as $project) {
            echo '<tr>';
            echo '<td>' . esc_html($project['project_name']) . '</td>';
            echo '<td>' . esc_html($project['total_amount_formatted']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';

        if (count($projects) > 10) {
            echo '<p><em>Affichage limité aux 10 premiers projets.</em></p>';
        }
    }

    /**
     * Génère le HTML du widget "Statistiques de Facturation" (avec cache)
     */
    protected function get_invoice_stats_widget_html($year) {
        $cache_key = "ispag_invoice_stats_widget_{$year}";
        $html = get_transient($cache_key);

        if (false === $html) {
            $stats = $this->ajax_handler->get_invoice_stats(true, $year);
            ob_start();
            $this->render_invoice_stats_widget_data($stats);
            $html = ob_get_clean();
            set_transient($cache_key, $html, 6 * HOUR_IN_SECONDS); // Cache pour 6 heures
        }

        return $html;
    }

    /**
     * Affiche les données du widget "Statistiques de Facturation"
     */
    protected function render_invoice_stats_widget_data($stats) {
        if (empty($stats)) {
            echo '<p>Aucune donnée disponible.</p>';
            return;
        }

        echo '<div style="height: 300px; width: 100%;">';
        echo '<canvas id="ispag_invoice_stats_chart"></canvas>';
        echo '</div>';

        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                const ctx = document.getElementById("ispag_invoice_stats_chart").getContext("2d");
                const labels = ' . json_encode(array_keys($stats)) . ';
                const data = ' . json_encode(array_values($stats)) . ';

                new Chart(ctx, {
                    type: "line",
                    data: {
                        labels: labels,
                        datasets: [{
                            label: "Facturation (CHF)",
                            data: data,
                            borderColor: "rgb(153, 102, 255)",
                            tension: 0.1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: { beginAtZero: true }
                        }
                    }
                });
            });
        </script>';
    }

    // ======================
    // MÉTHODES EXISTANTES (INCHANGÉES)
    // ======================

    /**
     * Ajoute la page "Tableau de Bord Projets" dans le menu Admin
     */
    public function add_admin_menu() {
        add_submenu_page(
            'ispag-entreprises',
            __('Project ISPAG', 'creation-reservoir'),
            __('Project ISPAG', 'creation-reservoir'),
            'view_reports',
            'ispag-project-dashboard',
            [$this, 'render_dashboard_page']
        );

        add_submenu_page(
            'ispag-entreprises',
            __('Orders', 'creation-reservoir'),
            __('Orders', 'creation-reservoir'),
            'view_reports',
            'ispag-order-stats',
            [$this, 'render_order_stats_page']
        );

        add_submenu_page(
            'ispag-entreprises',
            __('Invoices', 'creation-reservoir'),
            __('Invoices', 'creation-reservoir'),
            'view_reports',
            'ispag-invoice-stats',
            [$this, 'render_invoice_stats_page']
        );

        add_submenu_page(
            'ispag-entreprises',
            __('Quotations', 'creation-reservoir'),
            __('Quotations', 'creation-reservoir'),
            'view_reports',
            'ispag-quotation-stats',
            [$this, 'ispag_pd_quotation_stats_page_callback']
        );

        add_submenu_page(
            'ispag-entreprises',
            __('Supplier Follow-up', 'creation-reservoir'),
            __('Supplier Follow-up', 'creation-reservoir'),
            'view_reports',
            'ispag-supplier-tracking',
            [$this, 'ispag_supplier_tracking_page_content']
        );

        add_submenu_page(
            'ispag-entreprises',
            __('Engineer/Competitor Tracking', 'creation-reservoir'),
            __('Engineer/Competitor Tracking', 'creation-reservoir'),
            'view_reports',
            'ispag-engineer-tracking',
            [$this, 'ispag_engineer_tracking_page_content']
        );
    }

    /**
     * Affiche le contenu de la page d'administration
     */
    public function render_dashboard_page() {
        if (!current_user_can('view_reports')) {
            return;
        }
        include ISPAG_PD_PATH . 'classes/admin/project-dashboard-page.php';
    }

    /**
     * Charge les scripts et styles nécessaires
     */
    public function enqueue_scripts($hook) {
        $allowed_hooks = [
            'toplevel_page_ispag-project-dashboard',
            'ispag-project-dashboard_page_ispag-order-stats',
            'ispag-project-dashboard_page_ispag-invoice-stats',
            'ispag-project-dashboard_page_ispag-quotation-stats',
            'ispag-project-dashboard_page_ispag-supplier-tracking',
            'ispag-project-dashboard_page_ispag-engineer-tracking',
            'index.php' // Dashboard admin
        ];

        if (!in_array($hook, $allowed_hooks)) {
            return;
        }

        // Styles
        wp_enqueue_style('ispag-pd-style', ISPAG_PD_URL . 'assets/css/style.css', array(), '1.0.0');

        // Bibliothèque Chart.js pour les graphiques
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true);

        // Scripts
        wp_enqueue_script(
            'ispag-pd-script',
            ISPAG_PD_URL . 'assets/js/dashboard.js',
            array('jquery', 'chart-js'),
            '1.0.0',
            true
        );

        // Passer des variables PHP au script JS
        wp_localize_script('ispag-pd-script', 'ispagDashboard', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ispag-dashboard-nonce'),
            'current_year' => date('Y'),
        ));
    }

    /**
     * Affiche le contenu de la page d'analyse des commandes
     */
    public function render_order_stats_page() {
        if (!current_user_can('view_reports')) {
            return;
        }
        include ISPAG_PD_PATH . 'classes/admin/order-stats-page.php';
    }

    /**
     * Affiche la page des statistiques de facturation (Invoice Stats)
     */
    public function render_invoice_stats_page() {
        $current_year = date('Y');
        include(ISPAG_PD_PATH . 'classes/admin/invoice-stats-page.php');
    }

    public function ispag_pd_quotation_stats_page_callback() {
        include_once(ISPAG_PD_PATH . 'classes/admin/ispag-quotation-stats-page.php');
    }

    /**
     * Suivi du volume RÉEL (Somme des Quantités) sur 5 ans.
     * Filtré par Prestation 'Product' et Etat 'Purchase'.
     */
    function ispag_supplier_tracking_page_content() {
        global $wpdb;

        $art_f_table       = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $cmd_f_table       = $wpdb->prefix . 'achats_commande_liste_fournisseurs';
        $details_p_table   = $wpdb->prefix . 'achats_details_commande';
        $fournisseur_table = $wpdb->prefix . 'achats_fournisseurs';
        $type_pres_table   = $wpdb->prefix . 'achats_type_prestations';
        $etat_cmd_table    = $wpdb->prefix . 'achats_etat_commandes_fournisseur';

        // Définition des 5 années
        $annee_n  = (int) date('Y');
        $annee_n1 = $annee_n - 1;
        $annee_n2 = $annee_n - 2;
        $annee_n3 = $annee_n - 3;
        $annee_n4 = $annee_n - 4;

        // Requête SQL étendue à 5 ans
        $sql = $wpdb->prepare("
            SELECT
                f.Fournisseur,
                SUM(CASE WHEN YEAR(FROM_UNIXTIME(c.TimestampDateCreation)) = %d THEN art_f.Qty ELSE 0 END) AS qty_n,
                SUM(CASE WHEN YEAR(FROM_UNIXTIME(c.TimestampDateCreation)) = %d THEN art_f.Qty ELSE 0 END) AS qty_n1,
                SUM(CASE WHEN YEAR(FROM_UNIXTIME(c.TimestampDateCreation)) = %d THEN art_f.Qty ELSE 0 END) AS qty_n2,
                SUM(CASE WHEN YEAR(FROM_UNIXTIME(c.TimestampDateCreation)) = %d THEN art_f.Qty ELSE 0 END) AS qty_n3,
                SUM(CASE WHEN YEAR(FROM_UNIXTIME(c.TimestampDateCreation)) = %d THEN art_f.Qty ELSE 0 END) AS qty_n4,
                SUM(art_f.Qty) AS total_qty_5_ans
            FROM
                {$art_f_table} art_f
            INNER JOIN {$cmd_f_table} c ON art_f.IdCommande = c.Id
            INNER JOIN {$details_p_table} dp ON art_f.IdCommandeClient = dp.Id
            INNER JOIN {$fournisseur_table} f ON c.IdFournisseur = f.Id
            INNER JOIN {$type_pres_table} tp ON dp.Type = tp.Id
            INNER JOIN {$etat_cmd_table} ec ON c.EtatCommande = ec.Id
            WHERE
                tp.prestation = 'Product'
                AND ec.steps = 'purchase'
                AND YEAR(FROM_UNIXTIME(c.TimestampDateCreation)) >= %d
            GROUP BY
                f.Fournisseur
            ORDER BY
                total_qty_5_ans DESC
        ", $annee_n, $annee_n1, $annee_n2, $annee_n3, $annee_n4, $annee_n4);

        $results = $wpdb->get_results($sql);

        // Initialisation des totaux
        $totals = ['n' => 0, 'n1' => 0, 'n2' => 0, 'n3' => 0, 'n4' => 0, 'global' => 0];
        ?>

        <style>
            .ispag-stats-container { margin: 20px; font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
            .ispag-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .ispag-table thead th { background: #f8f9fa; color: #2c3338; padding: 10px 5px; border-bottom: 2px solid #d32f2f; font-size: 10px; text-transform: uppercase; text-align: center; }
            .ispag-table th.col-name, .ispag-table td.col-name { text-align: left !important; padding-left: 15px; font-weight: 600; border-right: 1px solid #eee; min-width: 150px; }
            .ispag-table td { padding: 10px 5px; border-bottom: 1px solid #f0f0f0; font-size: 12px; text-align: center; }
            .ispag-table tr:hover { background-color: #fff9f9 !important; }
            .ispag-table .col-total { background: #fdfdfd; font-weight: bold; color: #d32f2f; border-left: 1px solid #eee; }
            .ispag-table tfoot tr { background: #32373c; color: #fff; font-weight: bold; }
            .empty-cell { color: #ccc; }
        </style>

        <div class="wrap ispag-stats-container">
            <h1><i class="dashicons dashicons-chart-area"></i> Analyse Quinquennale des Volumes Produits</h1>
            <p>Evolution des quantités commandées (Type: <strong>Product</strong>) sur les 5 dernières années.</p>

            <?php if (!empty($results)): ?>
                <table class="ispag-table">
                    <thead>
                        <tr>
                            <th class="col-name">Fournisseur</th>
                            <th><?php echo $annee_n; ?></th>
                            <th><?php echo $annee_n1; ?></th>
                            <th><?php echo $annee_n2; ?></th>
                            <th><?php echo $annee_n3; ?></th>
                            <th><?php echo $annee_n4; ?></th>
                            <th class="col-total">Total (5 ans)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $row):
                            $totals['n']  += $row->qty_n;
                            $totals['n1'] += $row->qty_n1;
                            $totals['n2'] += $row->qty_n2;
                            $totals['n3'] += $row->qty_n3;
                            $totals['n4'] += $row->qty_n4;
                            $totals['global'] += $row->total_qty_5_ans;
                        ?>
                            <tr>
                                <td class="col-name"><?php echo esc_html($row->Fournisseur); ?></td>
                                <td><?php echo $row->qty_n > 0 ? "<strong>".number_format($row->qty_n, 0, '.', ' ')."</strong>" : '<span class="empty-cell">-</span>'; ?></td>
                                <td><?php echo $row->qty_n1 > 0 ? number_format($row->qty_n1, 0, '.', ' ') : '<span class="empty-cell">-</span>'; ?></td>
                                <td><?php echo $row->qty_n2 > 0 ? number_format($row->qty_n2, 0, '.', ' ') : '<span class="empty-cell">-</span>'; ?></td>
                                <td><?php echo $row->qty_n3 > 0 ? number_format($row->qty_n3, 0, '.', ' ') : '<span class="empty-cell">-</span>'; ?></td>
                                <td><?php echo $row->qty_n4 > 0 ? number_format($row->qty_n4, 0, '.', ' ') : '<span class="empty-cell">-</span>'; ?></td>
                                <td class="col-total"><?php echo number_format($row->total_qty_5_ans, 0, '.', ' '); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="col-name">TOTAL GÉNÉRAL</td>
                            <td><?php echo number_format($totals['n'], 0, '.', ' '); ?></td>
                            <td><?php echo number_format($totals['n1'], 0, '.', ' '); ?></td>
                            <td><?php echo number_format($totals['n2'], 0, '.', ' '); ?></td>
                            <td><?php echo number_format($totals['n3'], 0, '.', ' '); ?></td>
                            <td><?php echo number_format($totals['n4'], 0, '.', ' '); ?></td>
                            <td class="col-total"><?php echo number_format($totals['global'], 0, '.', ' '); ?></td>
                        </tr>
                    </tfoot>
                </table>
            <?php else: ?>
                <div class="notice notice-warning"><p>Aucune donnée trouvée sur les 5 dernières années.</p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Fonction de contenu pour la page de suivi des Ingénieurs / Concurrents.
     */
    function ispag_engineer_tracking_page_content() {
        global $wpdb;

        // 1. GESTION DES PARAMÈTRES ET TABLES
        $current_year = date('Y');
        $selected_year = isset($_GET['report_year']) ? intval($_GET['report_year']) : $current_year;

        $liste_table = $wpdb->prefix . 'achats_liste_commande';
        $new_table = ISPAG_Crm_Company_Constants::TABLE_NAME;

        // 2. REQUÊTE SQL HYBRIDE
        $sql = $wpdb->prepare("
            SELECT
                COALESCE(f_new.company_name) AS IngenieurNom,
                l.EnSoumission,
                l.ingenieur_id
            FROM
                {$liste_table} l
            LEFT JOIN {$new_table} f_new
                ON (CHAR_LENGTH(CAST(l.ingenieur_id AS CHAR)) >= 5 AND f_new.viag_id = l.ingenieur_id)
            WHERE
                FROM_UNIXTIME(l.TimestampDateCommande, '%%Y') = %d
                AND l.EnSoumission IS NOT NULL
                AND l.EnSoumission != ''
            ORDER BY IngenieurNom ASC
        ", $selected_year);

        $results = $wpdb->get_results($sql);

        // 3. TRAITEMENT DES DONNÉES
        $engineer_tracking = [];
        $all_competitors = [];
        $total_submissions_count = 0;
        $non_attribue_label = 'Not assigned';

        if ($results) {
            foreach ($results as $row) {
                $engineer = trim($row->IngenieurNom);
                $eng_id = intval($row->ingenieur_id);

                if (empty($engineer) || $engineer === '0') {
                    $engineer = $non_attribue_label;
                    $eng_id = 0;
                }

                $competitors_string = str_replace([';', '|'], ',', $row->EnSoumission);
                $competitors = array_filter(array_map('trim', explode(',', $competitors_string)));

                if (!empty($competitors)) {
                    $total_submissions_count++;
                    if (!isset($engineer_tracking[$engineer])) {
                        $engineer_tracking[$engineer] = [
                            'id' => $eng_id,
                            'total_submissions' => 0,
                            'competitors' => []
                        ];
                    }
                    $engineer_tracking[$engineer]['total_submissions']++;

                    foreach ($competitors as $competitor) {
                        $competitor = ucwords(strtolower($competitor));
                        if (!isset($engineer_tracking[$engineer]['competitors'][$competitor])) {
                            $engineer_tracking[$engineer]['competitors'][$competitor] = 0;
                        }
                        $engineer_tracking[$engineer]['competitors'][$competitor]++;
                        if (!in_array($competitor, $all_competitors)) $all_competitors[] = $competitor;
                    }
                }
            }
        }
        sort($all_competitors);
        $available_years = range($current_year, $current_year - 3);

        // 4. AFFICHAGE (CSS + HTML)
        ?>

        <style>
            .ispag-stats-container { margin: 20px; font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif; }
            .ispag-stats-table { border-collapse: collapse; width: 100%; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .ispag-stats-table thead th { background: #f8f9fa; color: #2c3338; padding: 12px 10px; border-bottom: 2px solid #d32f2f; font-size: 11px; text-transform: uppercase; text-align: center; }
            .ispag-stats-table th.col-name, .ispag-stats-table td.col-name { text-align: left !important; padding-left: 15px; border-right: 1px solid #eee; }
            .ispag-stats-table td { padding: 10px; border-bottom: 1px solid #f0f0f0; font-size: 13px; text-align: center; }
            .ispag-stats-table tr:hover { background-color: #fffafa !important; }
            .ispag-stats-table .col-total { background: #fdfdfd; font-weight: bold; color: #d32f2f; width: 80px; border-right: 1px solid #eee; }
            .ispag-stats-table .col-name { font-weight: 600; color: #333; }
            .ispag-stats-table .col-name a { text-decoration: none; color: #0073aa; transition: color 0.2s; }
            .ispag-stats-table .col-name a:hover { color: #d32f2f; }
            .ispag-stats-table tfoot tr { background: #32373c; color: #fff; font-weight: bold; }
            .ispag-stats-table tfoot td { padding: 12px 10px; }
            .empty-val { color: #ccc; font-size: 11px; }
            .year-selector { margin-bottom: 15px; padding: 10px; background: #fff; border: 1px solid #ccd0d4; display: inline-block; }
        </style>

        <div class="wrap ispag-stats-container">
            <h1><i class="dashicons dashicons-chart-area"></i> Suivi Ingénieurs & Concurrents</h1>
            <p>Analyse des soumissions par ingénieur et répartition des concurrents cités. Cliquez sur un nom ou un total pour voir les détails.</p>

            <div class="year-selector">
                <form method="get">
                    <input type="hidden" name="page" value="ispag-engineer-tracking" />
                    <label>Année d'analyse : </label>
                    <select name="report_year" onchange="this.form.submit()">
                        <?php foreach ($available_years as $year): ?>
                            <option value="<?php echo $year; ?>" <?php selected($selected_year, $year); ?>><?php echo $year; ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if (!empty($engineer_tracking)): ?>
                <table class="ispag-stats-table">
                    <thead>
                        <tr>
                            <th class="col-name">Ingénieur Officiel</th>
                            <th class="col-total">Total Projets</th>
                            <?php foreach ($all_competitors as $competitor): ?>
                                <th><?php echo esc_html($competitor); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        uasort($engineer_tracking, function($a, $b) { return $b['total_submissions'] <=> $a['total_submissions']; });

                        foreach ($engineer_tracking as $engineer => $data):
                            $has_id = (!empty($data['id']) && $data['id'] !== 0);
                            $link = $has_id ? "https://app.ispag-asp.ch/liste-des-offres/?ingenieur_id=" . $data['id'] : "";
                        ?>
                            <tr>
                                <td class="col-name">
                                    <?php if ($has_id): ?>
                                        <a href="<?php echo esc_url($link); ?>" target="_blank">
                                            <span class="dashicons dashicons-external" style="font-size:14px; margin-right:5px; vertical-align:text-bottom;"></span>
                                            <?php echo esc_html($engineer); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo esc_html($engineer); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="col-total">
                                    <?php if ($has_id): ?>
                                        <a href="<?php echo esc_url($link); ?>" target="_blank">
                                            <?php echo $data['total_submissions']; ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo $data['total_submissions']; ?>
                                    <?php endif; ?>
                                </td>
                                <?php foreach ($all_competitors as $competitor):
                                    $count = $data['competitors'][$competitor] ?? 0; ?>
                                    <td>
                                        <?php echo $count > 0 ? "<strong>$count</strong>" : '<span class="empty-val">-</span>'; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="col-name">TOTAL CUMULÉ</td>
                            <td class="col-total"><?php echo $total_submissions_count; ?></td>
                            <?php foreach ($all_competitors as $competitor):
                                $col_total = 0;
                                foreach ($engineer_tracking as $data) { $col_total += $data['competitors'][$competitor] ?? 0; }
                            ?>
                                <td><?php echo $col_total; ?></td>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                </table>
            <?php else: ?>
                <div class="notice notice-warning"><p>Aucun projet trouvé pour l'année <?php echo $selected_year; ?>.</p></div>
            <?php endif; ?>
        </div>
        <?php
    }
}