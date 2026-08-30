<?php
defined('ABSPATH') or die();

class ISPAG_Project_Dashboard_Ajax {

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

        // Hooks AJAX pour les utilisateurs connectés
        add_action('wp_ajax_ispag_pd_get_delivery_forecast', [$this, 'get_delivery_forecast']);
        add_action('wp_ajax_ispag_pd_get_projects_to_invoice', [$this, 'get_projects_to_invoice']);
        add_action('wp_ajax_ispag_pd_get_delivery_details', [$this, 'get_delivery_details']);
        add_action('wp_ajax_ispag_pd_get_order_intake_stats', [$this, 'get_order_intake_stats']);
        add_action('wp_ajax_ispag_pd_get_order_details', [$this, 'get_order_details']);
        add_action('wp_ajax_ispag_pd_get_invoice_stats', [$this, 'get_invoice_stats']);
        add_action('wp_ajax_ispag_pd_get_quotation_stats', [$this, 'get_quotation_stats']);
        add_action('wp_ajax_ispag_pd_get_invoiced_details', [$this, 'get_invoiced_details_ajax']);

        // Ajout des widgets au dashboard admin
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widgets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_dashboard_scripts']);
    }

    // ======================
    // NOUVELLES MÉTHODES POUR LE DASHBOARD ADMIN
    // ======================

    /**
     * Ajoute les widgets au dashboard admin
     */
    public function add_dashboard_widgets() {
        // wp_add_dashboard_widget(
        //     'ispag_delivery_forecast_widget',
        //     'Prévisions de Livraison (15 mois)',
        //     [$this, 'render_delivery_forecast_widget']
        // );

        // wp_add_dashboard_widget(
        //     'ispag_order_intake_widget',
        //     'Entrées de Commande (Mensuel/Cumulé)',
        //     [$this, 'render_order_intake_widget']
        // );

        // wp_add_dashboard_widget(
        //     'ispag_invoice_stats_widget',
        //     'Statistiques de Facturation',
        //     [$this, 'render_invoice_stats_widget']
        // );

        // wp_add_dashboard_widget(
        //     'ispag_quotation_stats_widget',
        //     'Statistiques d\'Offres',
        //     [$this, 'render_quotation_stats_widget']
        // );
    }

    /**
     * Charge les scripts nécessaires pour le dashboard (Chart.js)
     */
    public function enqueue_dashboard_scripts($hook) {
        if ($hook !== 'index.php') {
            return;
        }

        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );
    }

    /**
     * Affiche le widget des prévisions de livraison
     */
    public function render_delivery_forecast_widget() {
        $forecast = $this->get_delivery_forecast(true);

        if (empty($forecast)) {
            echo '<p>Aucune donnée disponible.</p>';
            return;
        }

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
     * Affiche le widget des entrées de commande
     */
    public function render_order_intake_widget() {
        $year = date('Y');
        $data = $this->get_order_intake_stats(true, $year);

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
            echo '<td>' . esc_html($item['intake']) . '</td>';
            echo '<td>' . esc_html($item['target']) . '</td>';
            echo '<td ' . $ecart_class . '>' . esc_html($ecart) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    /**
     * Affiche le widget des statistiques de facturation
     */
    public function render_invoice_stats_widget() {
        $year = date('Y');
        $stats = $this->get_invoice_stats(true, $year);

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

    /**
     * Affiche le widget des statistiques d'offres
     */
    public function render_quotation_stats_widget() {
        $year = date('Y');
        $stats = $this->get_quotation_stats(true, $year);

        if (empty($stats)) {
            echo '<p>Aucune donnée disponible.</p>';
            return;
        }

        echo '<table class="widefat">';
        echo '<thead><tr><th>Mois</th><th>Nombre d\'offres</th></tr></thead>';
        foreach ($stats as $month => $count) {
            echo '<tr>';
            echo '<td>' . esc_html($month) . '</td>';
            echo '<td>' . esc_html($count) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    // ======================
    // MÉTHODES EXISTANTES (ADAPTÉES POUR LE DASHBOARD)
    // ======================

    /**
     * Récupère la prévision de livraison pour la période (15 mois)
     * @param bool $return_data Si vrai, retourne les données au lieu d'envoyer une réponse JSON.
     */
    public function get_delivery_forecast($return_data = false) {
        if (!$return_data) {
            check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');
        }

        $forecast = [];
        $today = new DateTime();

        for ($i = -3; $i <= 11; $i++) {
            $date = clone $today;
            $date->modify("$i month");

            $start_of_month = (clone $date)->modify('first day of this month 00:00:00');
            $start_timestamp = $start_of_month->getTimestamp();
            $end_of_month = (clone $date)->modify('first day of next month 00:00:00');
            $end_timestamp = $end_of_month->getTimestamp();

            $results = $this->repo->get_deliveries_by_month($start_timestamp, $end_timestamp);
            $total_month = 0;

            foreach ($results as $item) {
                $total_month += floatval($item->total_a_livrer);
            }

            $forecast[] = [
                'month_label' => $start_of_month->format('M Y'),
                'start_timestamp' => $start_timestamp,
                'end_timestamp' => $end_timestamp,
                'total_amount' => round($total_month, 2),
            ];
        }

        if ($return_data) {
            return $forecast;
        } else {
            wp_send_json_success($forecast);
        }
    }

    /**
     * Récupère la liste des projets prêts à être facturés
     * @param bool $return_data Si vrai, retourne les données au lieu d'envoyer une réponse JSON.
     */
    public function get_projects_to_invoice($return_data = false) {
        if (!$return_data) {
            check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');
        }

        $articles = $this->repo->get_projects_to_invoice();
        $projects_to_invoice = [];

        foreach ($articles as $article) {
            $deal_id = $article->hubspot_deal_id;
            $amount = floatval($article->sales_price) * floatval($article->Qty) * (1 - floatval($article->discount) / 100);

            if (!isset($projects_to_invoice[$deal_id])) {
                $projects_to_invoice[$deal_id] = [
                    'deal_id' => $deal_id,
                    'project_name' => $article->ObjetCommande,
                    'total_amount' => 0,
                    'articles' => [],
                ];
            }

            $projects_to_invoice[$deal_id]['total_amount'] += $amount;
            $projects_to_invoice[$deal_id]['articles'][] = [
                'article_id' => $article->article_id,
                'name' => $article->Article,
                'qty' => floatval($article->Qty),
                'amount' => round($amount, 2),
                'delivery_date' => date('d.m.Y', $article->TimestampDateDeLivraisonFin)
            ];
        }

        $final_list = array_values($projects_to_invoice);
        foreach ($final_list as &$project) {
            $project['total_amount_formatted'] = number_format($project['total_amount'], 2, '.', "'") . ' CHF';
            $project['total_amount'] = round($project['total_amount'], 2);
        }

        if ($return_data) {
            return $final_list;
        } else {
            wp_send_json_success($final_list);
        }
    }

    /**
     * Récupère les détails des livraisons pour un mois spécifique
     * @param bool $return_data Si vrai, retourne les données au lieu d'envoyer une réponse JSON.
     */
    public function get_delivery_details($return_data = false) {
        if (!$return_data) {
            check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');
            $start_timestamp = isset($_POST['start_timestamp']) ? intval($_POST['start_timestamp']) : 0;
            $end_timestamp = isset($_POST['end_timestamp']) ? intval($_POST['end_timestamp']) : 0;
        } else {
            $start_timestamp = 0;
            $end_timestamp = 0;
        }

        if (empty($start_timestamp) || empty($end_timestamp)) {
            if ($return_data) {
                return [];
            } else {
                wp_send_json_error(['message' => 'Paramètres de date manquants.'], 400);
            }
        }

        $projects = $this->repo->get_deliveries_by_month($start_timestamp, $end_timestamp);
        $final_list = [];

        foreach ($projects as $project) {
            $amount = floatval($project->total_a_livrer);
            $final_list[] = [
                'deal_id' => $project->hubspot_deal_id,
                'project_name' => $project->ObjetCommande,
                'total_amount' => round($amount, 2),
                'total_amount_formatted' => number_format($amount, 2, '.', "'") . ' CHF',
            ];
        }

        if ($return_data) {
            return $final_list;
        } else {
            wp_send_json_success($final_list);
        }
    }

    /**
     * Récupère les statistiques d'entrées de commande mensuelles et cumulées
     * @param bool $return_data Si vrai, retourne les données au lieu d'envoyer une réponse JSON.
     * @param int $year Année à utiliser (par défaut, l'année actuelle).
     */
    public function get_order_intake_stats($return_data = false, $year = null) {
        if (!$return_data) {
            check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');
            $year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');
        } else {
            $year = $year ?? date('Y');
        }

        $current_month = date('n');

        $intakes = $this->repo->get_monthly_order_intakes($year);
        if (is_wp_error($intakes) || $this->repo->wpdb->last_error) {
            if ($return_data) {
                return [];
            } else {
                wp_send_json_error(['message' => 'Erreur SQL lors de la récupération des commandes.', 'sql_error' => $this->repo->wpdb->last_error], 500);
                return;
            }
        }

        $targets = $this->repo->get_monthly_targets($year, 'order');
        if (is_wp_error($targets) || $this->repo->wpdb->last_error) {
            if ($return_data) {
                return [];
            } else {
                wp_send_json_error(['message' => 'Erreur SQL lors de la récupération des objectifs.', 'sql_error' => $this->repo->wpdb->last_error], 500);
                return;
            }
        }

        $monthly_data = [];
        $cumulative_intake = 0;
        $cumulative_target = 0;
        $cumulative_data = [];

        for ($month = 1; $month <= 12; $month++) {
            $month_label = date('M', mktime(0, 0, 0, $month, 1, $year));
            $intake = $intakes[$month];
            $target = $targets[$month];

            $is_past = ($month <= $current_month);
            $cumulative_intake += $intake;
            $cumulative_target += $target;

            $monthly_data[] = [
                'month' => $month,
                'label' => $month_label,
                'intake' => round($intake, 0),
                'target' => $target,
                'is_past' => $is_past,
            ];

            if ($month <= $current_month) {
                $cumulative_data[] = [
                    'month' => $month,
                    'label' => $month_label,
                    'intake' => round($cumulative_intake, 0),
                    'target' => $cumulative_target,
                ];
            }
        }

        if ($return_data) {
            return [
                'monthly' => $monthly_data,
                'cumulative' => $cumulative_data,
            ];
        } else {
            wp_send_json_success([
                'monthly' => $monthly_data,
                'cumulative' => $cumulative_data,
            ]);
        }
    }

    /**
     * Récupère les détails des commandes reçues pour un mois spécifique
     * @param bool $return_data Si vrai, retourne les données au lieu d'envoyer une réponse JSON.
     */
    public function get_order_details($return_data = false) {
        if (!$return_data) {
            check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');
            $year = isset($_POST['year']) ? intval($_POST['year']) : 0;
            $month = isset($_POST['month']) ? intval($_POST['month']) : 0;
        } else {
            $year = 0;
            $month = 0;
        }

        if (empty($year) || empty($month)) {
            if ($return_data) {
                return [];
            } else {
                wp_send_json_error(['message' => 'Paramètres de date manquants.'], 400);
            }
        }

        $projects = $this->repo->get_order_details_by_month($year, $month);
        if (is_wp_error($projects) || $this->repo->wpdb->last_error) {
            if ($return_data) {
                return [];
            } else {
                wp_send_json_error(['message' => 'Erreur SQL.', 'sql_error' => $this->repo->wpdb->last_error], 500);
                return;
            }
        }

        $final_list = [];
        foreach ($projects as $project) {
            $project_repo = new ISPAG_Project_Details_Repository();
            $stats = $project_repo->get_project_profitability($project->hubspot_deal_id);

            $final_list[] = [
                'deal_id' => $project->hubspot_deal_id,
                'project_name' => $project->ObjetCommande,
                'total_amount' => round($stats['revenu'], 2),
                'total_amount_formatted' => number_format($stats['revenu'], 2, '.', "'") . ' CHF',
                'margin_percent' => round($stats['marge'], 1),
                'margin_status' => $stats['status']
            ];
        }

        if ($return_data) {
            return $final_list;
        } else {
            wp_send_json_success($final_list);
        }
    }

    /**
     * Récupère les statistiques de facturation
     * @param bool $return_data Si vrai, retourne les données au lieu d'envoyer une réponse JSON.
     * @param int $year Année à utiliser (par défaut, l'année actuelle).
     */
    public function get_invoice_stats($return_data = false, $year = null) {
        if (!$return_data) {
            check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');
            $year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');
        } else {
            $year = $year ?? date('Y');
        }

        $stats = $this->repo->get_invoice_stats_by_year($year);
        if (is_wp_error($stats)) {
            if ($return_data) {
                return [];
            } else {
                wp_send_json_error(['message' => 'Erreur lors de la récupération des statistiques de facturation.'], 500);
                return;
            }
        }

        if ($return_data) {
            return $stats;
        } else {
            wp_send_json_success($stats);
        }
    }

    /**
     * Récupère les statistiques d'offres
     * @param bool $return_data Si vrai, retourne les données au lieu d'envoyer une réponse JSON.
     * @param int $year Année à utiliser (par défaut, l'année actuelle).
     */
    public function get_quotation_stats($return_data = false, $year = null) {
        if (!$return_data) {
            check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');
            $year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');
        } else {
            $year = $year ?? date('Y');
        }

        $stats = $this->repo->get_quotation_counts_by_year($year);
        if (is_wp_error($stats)) {
            if ($return_data) {
                return [];
            } else {
                wp_send_json_error(['message' => 'Erreur lors de la récupération des statistiques d\'offres.'], 500);
                return;
            }
        }

        if ($return_data) {
            return $stats;
        } else {
            wp_send_json_success($stats);
        }
    }

    /**
     * Récupère les détails des facturations pour la modal
     * @param bool $return_data Si vrai, retourne les données au lieu d'envoyer une réponse JSON.
     */
    public function get_invoiced_details_ajax($return_data = false) {
        if (!$return_data) {
            check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');
            $month = isset($_POST['month']) ? intval($_POST['month']) : 0;
            $year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');
        } else {
            $month = 0;
            $year = date('Y');
        }

        if ($month < 1 || $month > 12) {
            if ($return_data) {
                return [];
            } else {
                wp_send_json_error(['message' => 'Mois invalide.'], 400);
                return;
            }
        }

        $details = $this->repo->get_invoiced_details($year, $month);
        if (is_wp_error($details)) {
            if ($return_data) {
                return [];
            } else {
                wp_send_json_error(['message' => 'Erreur lors de la récupération des détails facturés.'], 500);
                return;
            }
        }

        $projects = [];
        $total_amount = 0;

        foreach ($details as $detail) {
            $deal_id = $detail->hubspot_deal_id;
            $amount = floatval($detail->final_invoiced_amount);
            $total_amount += $amount;

            if (!isset($projects[$deal_id])) {
                $projects[$deal_id] = [
                    'deal_id' => $deal_id,
                    'project_name' => $detail->ObjetCommande,
                    'total_amount' => 0,
                    'articles_count' => 0,
                    'invoice_status' => 'Facturé'
                ];
            }
            $projects[$deal_id]['total_amount'] += $amount;
            $projects[$deal_id]['articles_count']++;
        }

        if ($return_data) {
            return [
                'projects' => array_values($projects),
                'total_amount' => round($total_amount, 2),
                'month' => $month,
                'year' => $year
            ];
        } else {
            wp_send_json_success([
                'projects' => array_values($projects),
                'total_amount' => round($total_amount, 2),
                'month' => $month,
                'year' => $year
            ]);
        }
    }
}