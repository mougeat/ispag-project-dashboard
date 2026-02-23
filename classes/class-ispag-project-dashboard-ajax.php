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
    }

    /**
     * Récupère la prévision de livraison pour la période (15 mois)
     */
    public function get_delivery_forecast() {
        check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');
        
        $forecast = [];
        $today = new DateTime();

        // Période: 3 mois précédents + mois actuel + 11 mois suivants (total 15 mois)
        for ($i = -3; $i <= 11; $i++) {
            $date = clone $today;
            $date->modify("$i month");
            
            $start_of_month = (clone $date)->modify('first day of this month 00:00:00');
            $start_timestamp = $start_of_month->getTimestamp();
            $end_of_month = (clone $date)->modify('first day of next month 00:00:00');
            $end_timestamp = $end_of_month->getTimestamp();

            // Utilisation de la fonction Repository mise à jour
            $results = $this->repo->get_deliveries_by_month($start_timestamp, $end_timestamp);
            
            $total_month = 0;
            
            // Maintenant, $results contient déjà le total par projet (t1.total_a_livrer)
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

        wp_send_json_success($forecast);
    }

    /**
     * Récupère la liste des projets prêts à être facturés
     */
    public function get_projects_to_invoice() {
        check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');
        
        $articles = $this->repo->get_projects_to_invoice();
        $projects_to_invoice = [];

        foreach ($articles as $article) {
            $deal_id = $article->hubspot_deal_id;
            
            // Calculer le montant net de l'article
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

        // Formatter le total et trier
        $final_list = array_values($projects_to_invoice);
        foreach ($final_list as &$project) {
            $project['total_amount_formatted'] = number_format($project['total_amount'], 2, '.', "'") . ' CHF';
            $project['total_amount'] = round($project['total_amount'], 2); // Garder la valeur numérique pour le tri si nécessaire
        }
        
        wp_send_json_success($final_list);
    }
    
    /**
     * Récupère les détails des livraisons pour un mois spécifique (utilisé par la modal)
     * NOTE: Cette fonction utilise la nouvelle fonction get_deliveries_by_month qui AGGREGATE par projet.
     */
    public function get_delivery_details() {
        check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');
        
        $start_timestamp = isset($_POST['start_timestamp']) ? intval($_POST['start_timestamp']) : 0;
        $end_timestamp = isset($_POST['end_timestamp']) ? intval($_POST['end_timestamp']) : 0;

        if (empty($start_timestamp) || empty($end_timestamp)) {
            wp_send_json_error(['message' => 'Paramètres de date manquants.'], 400);
        }

        // On utilise la fonction de regroupement pour obtenir les montants totaux par projet.
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

        wp_send_json_success($final_list);
    }

    /**
     * Récupère les statistiques d'entrées de commande mensuelles et cumulées
     */
    public function get_order_intake_stats() {
        check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');
        // error_log('ISPAG IN get_order_intake_stats'); 
        
        $year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');
        $current_month = date('n'); // Mois actuel (1 à 12)
        
        // 1. Données mensuelles
        $intakes = $this->repo->get_monthly_order_intakes($year);
        if (is_wp_error($intakes) || $this->repo->wpdb->last_error) {
            // Loggez l'erreur pour la trouver dans les logs du serveur ou via un plugin de débogage
// \1('ISPAG Order Intake SQL Error: ' . $this->repo->wpdb->last_error); 
            // Renvoyez l'erreur pour que vous puissiez la voir directement dans l'outil Dev Tools du navigateur
            wp_send_json_error(['message' => 'Erreur SQL lors de la récupération des commandes.', 'sql_error' => $this->repo->wpdb->last_error], 500);
            return;
        }
        $targets = $this->repo->get_monthly_targets($year, 'order');
        if (is_wp_error($targets) || $this->repo->wpdb->last_error) {
// \1('ISPAG Targets SQL Error: ' . $this->repo->wpdb->last_error); 
            wp_send_json_error(['message' => 'Erreur SQL lors de la récupération des objectifs.', 'sql_error' => $this->repo->wpdb->last_error], 500);
            return;
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
            
            // Données Mensuelles
            $monthly_data[] = [
                'month' => $month,
                'label' => $month_label,
                'intake' => round($intake, 0),
                'target' => $target,
                'is_past' => $is_past,
            ];
            
            // Données Cumulées (jusqu'au mois en cours)
            if ($month <= $current_month) {
                $cumulative_data[] = [
                    'month' => $month,
                    'label' => $month_label,
                    'intake' => round($cumulative_intake, 0),
                    'target' => $cumulative_target,
                ];
            }
        }

        wp_send_json_success([
            'monthly' => $monthly_data,
            'cumulative' => $cumulative_data,
        ]);
    }

    /**
     * Récupère les détails des commandes reçues pour un mois spécifique (utilisé par la modal)
     */
    public function get_order_details() {
        check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');
        
        $year = isset($_POST['year']) ? intval($_POST['year']) : 0;
        $month = isset($_POST['month']) ? intval($_POST['month']) : 0;

        if (empty($year) || empty($month)) {
            wp_send_json_error(['message' => 'Paramètres de date manquants.'], 400);
        }

        $projects = $this->repo->get_order_details_by_month($year, $month); 
        
        // **Débogage de l'erreur PHP fatale**
        if (is_wp_error($projects) || $this->repo->wpdb->last_error) {
            wp_send_json_error(['message' => 'Erreur SQL lors de la récupération des détails de commande.', 'sql_error' => $this->repo->wpdb->last_error], 500);
            return;
        }

        $final_list = [];

        foreach ($projects as $project) {
            $amount = floatval($project->total_intake);
            
            $final_list[] = [
                'deal_id' => $project->hubspot_deal_id,
                'project_name' => $project->ObjetCommande,
                'total_amount' => round($amount, 2),
                'total_amount_formatted' => number_format($amount, 2, '.', "'") . ' CHF',
            ];
        }

        wp_send_json_success($final_list);
    }
    /**
     * Récupère les statistiques de facturation (Invoice Stats)
     */
    public function get_invoice_stats() {
        check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');

        $year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');

        $stats = $this->repo->get_invoice_stats_by_year($year);

        if (is_wp_error($stats)) {
            wp_send_json_error(['message' => 'Erreur lors de la récupération des statistiques de facturation.'], 500);
            return;
        }

        wp_send_json_success($stats);
    }

    /**
     * Récupère les statistiques d'offres (Quotation Stats)
     */
    public function get_quotation_stats() {
        check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');

        $year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');

        $stats = $this->repo->get_quotation_counts_by_year($year);

        if (is_wp_error($stats)) {
            wp_send_json_error(['message' => 'Erreur lors de la récupération des statistiques d\'offres.'], 500);
            return;
        }

        wp_send_json_success($stats); // Retourne directement le tableau des mois
    }

   /**
     * Récupère les détails des facturations pour la modal (appel AJAX).
     */
    public function get_invoiced_details_ajax() {
        check_ajax_referer('ispag-project-dashboard-nonce', 'nonce');

        $month = isset($_POST['month']) ? intval($_POST['month']) : 0;
        $year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');

        if ($month < 1 || $month > 12) {
            wp_send_json_error(['message' => 'Mois invalide.'], 400);
            return;
        }

        $details = $this->repo->get_invoiced_details($year, $month);

        if (is_wp_error($details)) {
            wp_send_json_error(['message' => 'Erreur lors de la récupération des détails facturés.'], 500);
            return;
        }

        // Grouper par projet pour un affichage résumé dans la modal
        $projects = [];
        $total_amount = 0;

        foreach ($details as $detail) {
            $deal_id = $detail->hubspot_deal_id;
            $amount = floatval($detail->final_invoiced_amount);
            $total_amount += $amount;

            if (!isset($projects[$deal_id])) {
                $projects[$deal_id] = [
                    'deal_id' => $deal_id, // <-- AJOUTER l'ID ici
                    'project_name' => $detail->ObjetCommande, // <-- RENOMMER 'name' en 'project_name'
                    'total_amount' => 0, // <-- RENOMMER 'total' en 'total_amount'
                    'articles_count' => 0,
                    // NOTE : Je simule un statut pour que le JS ne plante pas. Vous devriez utiliser le vrai statut si disponible.
                    'invoice_status' => 'Facturé' 
                ];
            }
            $projects[$deal_id]['total_amount'] += $amount; // <-- UTILISER le nouveau nom
            $projects[$deal_id]['articles_count']++;
        }
        
// \1('get_invoiced_details_ajax : ' . print_r($projects, true));
        
        wp_send_json_success([
            'projects' => array_values($projects),
            'total_amount' => round($total_amount, 2),
            'month' => $month,
            'year' => $year
        ]);
    }
}