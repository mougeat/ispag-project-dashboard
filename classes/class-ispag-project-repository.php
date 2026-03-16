<?php
defined('ABSPATH') or die();

class ISPAG_Project_Repository {

    public  $wpdb;
    protected $table_projects;
    protected $table_articles;
    protected static $instance = null;
    protected $table_stats; 
    protected $rplp_rate;

    public static function run() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_projects = $wpdb->prefix . 'achats_liste_commande'; 
        $this->table_articles = $wpdb->prefix . 'achats_details_commande'; 
        $this->table_stats = $wpdb->prefix . 'achats_stats';
        $this->rplp_rate = floatval(get_option('rplp', 0));
    }
    public function get_deliveries_by_month($start_timestamp, $end_timestamp) {
        $rplp_multiplier = 1 + ($this->rplp_rate / 100);
        
        $sql = $this->wpdb->prepare("
            SELECT
                t1.hubspot_deal_id,
                t1.ObjetCommande,
                SUM(t2.sales_price * t2.Qty * (1 - t2.discount / 100) * %f) AS total_a_livrer
            FROM {$this->table_projects} t1
            INNER JOIN {$this->table_articles} t2 ON t1.hubspot_deal_id = t2.hubspot_deal_id
            WHERE t1.isQotation IS NULL
            AND (t2.Livre IS NULL OR t2.Livre = 0)
            AND t2.TimestampDateDeLivraisonFin >= %d
            AND t2.TimestampDateDeLivraisonFin < %d
            GROUP BY t1.hubspot_deal_id, t1.ObjetCommande
            ORDER BY t1.TimestampDateCommande DESC
        ", $rplp_multiplier, $start_timestamp, $end_timestamp); // Ajout du marqueur %f

        return $this->wpdb->get_results($sql);
    }

    /**
     * Récupère les projets et les articles prêts à être facturés (livrés mais non facturés).
     * @return array|object[] Liste des articles.
     */
    public function get_projects_to_invoice() {
        $rplp_multiplier = 1 + ($this->rplp_rate / 100); 
        $sql = $this->wpdb->prepare("
            SELECT
                t1.hubspot_deal_id,
                t1.ObjetCommande,
                t2.Article,
                t2.sales_price,
                (t2.sales_price * %f) AS sales_price_with_rplp,
                t2.discount,
                t2.Qty,
                t2.Id AS article_id,
                t2.TimestampDateDeLivraisonFin
            FROM {$this->table_projects} t1
            INNER JOIN {$this->table_articles} t2 ON t1.hubspot_deal_id = t2.hubspot_deal_id
            WHERE t1.isQotation IS NULL
            AND (t2.Livre IS NOT NULL OR t2.Livre != 0)  /* Marqué comme livré */
            AND (t2.invoiced IS NULL OR t2.invoiced = 0) /* Non facturé */
            ORDER BY t1.TimestampDateCommande DESC
        ", $rplp_multiplier);

        return $this->wpdb->get_results($sql);
    }

    /**
     * Récupère la liste des projets et des livraisons pour une modal.
     * @param int $start_timestamp Début du mois.
     * @param int $end_timestamp Fin du mois.
     * @return array|object[] Détails des livraisons pour la modal.
     */
    public function get_deliveries_details($start_timestamp, $end_timestamp) {
        $rplp_multiplier = 1 + ($this->rplp_rate / 100); 
        $sql = $this->wpdb->prepare("
            SELECT
                t1.hubspot_deal_id,
                t1.ObjetCommande,
                t2.Article,
                t2.sales_price,
                (t2.sales_price * %f) AS sales_price_with_rplp,
                t2.discount,
                t2.Qty,
                t2.Id AS article_id,
                t2.TimestampDateDeLivraisonFin
            FROM {$this->table_projects} t1
            INNER JOIN {$this->table_articles} t2 ON t1.hubspot_deal_id = t2.hubspot_deal_id
            WHERE t1.isQotation IS NULL
            AND (t2.Livre IS NULL OR t2.Livre = 0)
            AND t2.TimestampDateDeLivraisonFin >= %d
            AND t2.TimestampDateDeLivraisonFin < %d
            ORDER BY t2.TimestampDateDeLivraisonFin ASC
        ", $rplp_multiplier, $start_timestamp, $end_timestamp);

        return $this->wpdb->get_results($sql);
    }

    /**
     * Récupère le montant total des commandes reçues par mois pour l'année en cours.
     * @param int $year Année à analyser.
     * @return array Montant des commandes agrégées par mois.
     */
    // public function get_monthly_order_intakes($year) {
        
    //     $start_timestamp = strtotime("January 1st, $year 00:00:00");
    //     $end_timestamp = strtotime("January 1st, " . ($year + 1) . " 00:00:00");
    //     $rplp_multiplier = 1 + ($this->rplp_rate / 100); // Calcul du multiplicateur

    //     $sql = $this->wpdb->prepare("
    //         SELECT
    //             MONTH(FROM_UNIXTIME(t1.TimestampDateCommande)) AS month_num,
    //             SUM(t2.sales_price * t2.Qty * (1 - t2.discount / 100) * %f) AS total_intake
    //         FROM {$this->table_projects} t1
    //         INNER JOIN {$this->table_articles} t2 ON t1.hubspot_deal_id = t2.hubspot_deal_id
    //         WHERE t1.isQotation IS NULL
    //         AND t1.TimestampDateCommande >= %d
    //         AND t1.TimestampDateCommande < %d
    //         GROUP BY month_num
    //         ORDER BY month_num ASC
    //     ", $rplp_multiplier, $start_timestamp, $end_timestamp);

    //     $results = $this->wpdb->get_results($sql, ARRAY_A);

    //     // error_log('get_monthly_order_intakes : ' . print_r($sql, true));
        
    //     // Initialiser un tableau pour les 12 mois
    //     $intakes = array_fill(1, 12, 0); 

    //     foreach ($results as $row) {
    //         $intakes[intval($row['month_num'])] = floatval($row['total_intake']);
    //     }
        
    //     return $intakes;
    // }
    public function get_monthly_order_intakes($year) {
        // 1. On récupère d'abord tous les projets de l'année
        $start_timestamp = strtotime("January 1st, $year 00:00:00");
        $end_timestamp = strtotime("January 1st, " . ($year + 1) . " 00:00:00");

        $projects = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                hubspot_deal_id, 
                MONTH(FROM_UNIXTIME(TimestampDateCommande)) AS month_num
            FROM {$this->table_projects}
            WHERE isQotation IS NULL
            AND TimestampDateCommande >= %d
            AND TimestampDateCommande < %d
        ", $start_timestamp, $end_timestamp));

        // 2. Initialiser le tableau des 12 mois
        $intakes = array_fill(1, 12, 0.0);

        // 3. Boucler sur les projets et utiliser la méthode de rentabilité
        if ($projects) {
            foreach ($projects as $project) {
                $month = intval($project->month_num);
                
                // On appelle ta méthode centralisée
                $project_repo = new ISPAG_Project_Details_Repository();
                $stats = $project_repo->get_project_profitability($project->hubspot_deal_id);
                
                // On cumule le revenu dans le bon mois
                $intakes[$month] += floatval($stats['revenu']);
            }
        }

        return $intakes;
    }
    
    /**
     * Récupère les objectifs mensuels pour un type donné.
     * @param int $year Année à analyser.
     * @param string $typ Type d'objectif ('order', 'invoice', etc.)
     * @return array Objectifs agrégés par mois.
     */
    public function get_monthly_targets($year, $typ) {
        $sql = $this->wpdb->prepare("
            SELECT 
                month, 
                target 
            FROM {$this->table_stats} 
            WHERE typ = %s 
            -- ON ASSUME ICI QUE LA COLONNE 'month' est le numéro du mois (1 à 12)
            -- Si 'month' est un timestamp, il faut changer la requête !
            -- MAIS DANS LE CAS PRESENT, CETTE REQUETE DOIT ÊTRE OK POUR L'INSTANT.
            ORDER BY month ASC
        ", $typ);

        $results = $this->wpdb->get_results($sql, ARRAY_A);
        
        $targets = array_fill(1, 12, 0); 
        
        foreach ($results as $row) {
            $targets[intval($row['month'])] = intval($row['target']);
        }
        
        return $targets;
    }

    /**
     * Récupère les détails des commandes reçues (regroupées par projet) pour un mois spécifique.
     * @param int $year Année de la commande.
     * @param int $month Mois de la commande (1-12).
     * @return array Détails des projets.
     */
    public function get_order_details_by_month($year, $month) {
        
        // Début et fin du mois ciblé
        $start_timestamp = strtotime("$year-$month-01 00:00:00");
        
        // Calculer le timestamp du premier jour du mois suivant
        $next_month = $month == 12 ? 1 : $month + 1;
        $next_year = $month == 12 ? $year + 1 : $year;
        $end_timestamp = strtotime("$next_year-$next_month-01 00:00:00");

        $rplp_multiplier = 1 + ($this->rplp_rate / 100); // Calcul du multiplicateur

        $sql = $this->wpdb->prepare("
            SELECT
                t1.hubspot_deal_id,
                t1.ObjetCommande,
                SUM(t2.sales_price * t2.Qty * (1 - t2.discount / 100) * %f) AS total_intake
            FROM {$this->table_projects} t1
            INNER JOIN {$this->table_articles} t2 ON t1.hubspot_deal_id = t2.hubspot_deal_id
            WHERE t1.isQotation IS NULL
            AND t1.TimestampDateCommande >= %d
            AND t1.TimestampDateCommande < %d
            GROUP BY t1.hubspot_deal_id, t1.ObjetCommande
            ORDER BY t1.TimestampDateCommande DESC
        ", $rplp_multiplier, $start_timestamp, $end_timestamp); // Ajout du marqueur %f

        return $this->wpdb->get_results($sql);
    }

    /**
     * Récupère les montants facturés/livrés par mois pour une année donnée,
     * ainsi que les objectifs mensuels.
     * @param int $year Année cible.
     * @return array Statistiques mensuelles et cumulatives.
     */
    public function get_invoice_stats_by_year($year) {
        $stats = $this->get_monthly_targets_for_year($year); 

        $year_start = strtotime("$year-01-01 00:00:00");
        $next_year_start = strtotime(($year + 1) . "-01-01 00:00:00");
        $rplp_multiplier = 1 + ($this->rplp_rate / 100); // Calcul du multiplicateur

        $sql = $this->wpdb->prepare("
            SELECT
                MONTH(FROM_UNIXTIME(t2.TimestampDateDeLivraisonFin)) AS month_num, 
                SUM(t2.sales_price * t2.Qty * (1 - t2.discount / 100) * %f) AS total_invoice
            FROM {$this->table_projects} t1
            INNER JOIN {$this->table_articles} t2 ON t1.hubspot_deal_id = t2.hubspot_deal_id
            WHERE t1.isQotation IS NULL
            AND t2.TimestampDateDeLivraisonFin IS NOT NULL
            AND t2.TimestampDateDeLivraisonFin >= %d
            AND t2.TimestampDateDeLivraisonFin < %d
            AND (t2.Livre IS NOT NULL AND t2.Livre != 0) 
            GROUP BY month_num
            ORDER BY month_num ASC
        ", $rplp_multiplier, $year_start, $next_year_start);


        $results = $this->wpdb->get_results($sql, ARRAY_A);

        $monthly_invoices = [];
        foreach ($results as $row) {
            $monthly_invoices[intval($row['month_num'])] = floatval($row['total_invoice']);
        }

        $cumulative_intake = 0;
        $cumulative_target = 0;

        foreach ($stats as $month => &$item) {
            $item['invoice'] = $monthly_invoices[$month] ?? 0;
            
            // Calcul du cumul
            $cumulative_intake += $item['invoice'];
            $cumulative_target += $item['target'];
            
            $item['cumulative_invoice'] = $cumulative_intake;
            $item['cumulative_target'] = $cumulative_target;

            // La ligne est 'passée' si nous sommes avant ou dans le mois courant
            $item['is_past'] = $month <= date('n') && $year <= date('Y');

            // Formatage pour le JavaScript
            $item['intake'] = $item['invoice']; // On utilise 'intake' pour réutiliser les fonctions JS
            $item['cumulative_intake'] = $item['cumulative_invoice']; // Idem pour le cumul
            $item['month'] = $month;
            unset($item['invoice'], $item['cumulative_invoice']); // Nettoyage
        }

        // Séparation des données pour les deux tableaux/graphiques JS
        $final_monthly = array_map(function($item) {
            return [
                'month' => $item['month'],
                'label' => $item['label'],
                'intake' => $item['intake'],
                'target' => $item['target'],
                'is_past' => $item['is_past']
            ];
        }, $stats);

        $final_cumulative = array_map(function($item) {
            return [
                'month' => $item['month'],
                'label' => $item['label'],
                'intake' => $item['cumulative_intake'],
                'target' => $item['cumulative_target'],
                'is_past' => $item['is_past']
            ];
        }, $stats);

        return [
            'monthly' => array_values($final_monthly),
            'cumulative' => array_values($final_cumulative)
        ];
    }

    /**
     * Récupère les objectifs pour l'année et les structure par mois avec étiquettes.
     * C'est une fonction utilitaire pour préparer le tableau de base des stats.
     * @param int $year Année cible.
     * @return array Tableau structuré des 12 mois avec leurs objectifs.
     */
    protected function get_monthly_targets_for_year($year) {
        
        // 1. Récupérer les objectifs 'invoice' depuis la DB
        $invoice_targets = $this->get_monthly_targets($year, 'invoice'); 
        // ⚠️ Si vous utilisez un type différent pour la facturation (ex: 'ca'), changez 'invoice' ici.
        // J'assume qu'il y a un champ 'typ'='invoice' dans la table achats_stats.

        $stats = [];
        // Noms des mois en français
        $month_names = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
            7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
        ];

        for ($month = 1; $month <= 12; $month++) {
            $stats[$month] = [
                'month' => $month,
                'label' => $month_names[$month],
                'target' => $invoice_targets[$month] ?? 0, // Assure qu'un objectif existe, sinon 0
            ];
        }

        return $stats;
    }

    /**
     * Récupère le nombre d'offres (isQotation) par mois pour l'année courante et l'année précédente.
     * @param int $year Année cible (ex: 2025).
     * @return array Statistiques des 12 mois avec les données de l'année et de l'année-1.
     */
    public function get_quotation_counts_by_year($year) {
        $quotation_stats = $this->get_empty_monthly_stats();

        $current_year_counts = $this->fetch_quotation_counts($year);
        $previous_year_counts = $this->fetch_quotation_counts($year - 1); // Nouvelle série

        // Fusion des données dans le tableau de base
        foreach ($quotation_stats as $month => &$item) {
            $item['current_year_quantity'] = $current_year_counts[$month] ?? 0;
            $item['previous_year_quantity'] = $previous_year_counts[$month] ?? 0;
            
            $item['is_past'] = $month <= date('n') && $year <= date('Y');
        }
        
        // Retourne un tableau simple d'objets pour le JavaScript
        return array_values($quotation_stats);
    }
    
    /**
     * Fonction utilitaire pour exécuter la requête SQL sur une seule année.
     * @param int $target_year Année à cibler.
     * @return array Nombre d'offres indexé par le numéro du mois (1 à 12).
     */
    protected function fetch_quotation_counts($target_year) {
        $year_start = strtotime("$target_year-01-01 00:00:00");
        $next_year_start = strtotime(($target_year + 1) . "-01-01 00:00:00");

        $sql = $this->wpdb->prepare("
            SELECT
                MONTH(FROM_UNIXTIME(t1.TimestampDateCommande)) AS month_num,
                COUNT(t1.hubspot_deal_id) AS total_quotations
            FROM {$this->table_projects} t1
            WHERE t1.isQotation IS NOT NULL
            AND t1.isQotation != 0
            AND t1.TimestampDateCommande >= %d
            AND t1.TimestampDateCommande < %d
            GROUP BY month_num
            ORDER BY month_num ASC
        ", $year_start, $next_year_start);

        $results = $this->wpdb->get_results($sql, ARRAY_A);

        $monthly_counts = array_fill(1, 12, 0); 
        foreach ($results as $row) {
            $monthly_counts[intval($row['month_num'])] = intval($row['total_quotations']);
        }
        
        return $monthly_counts;
    }
    
    /**
     * Fonction utilitaire pour initialiser un tableau des 12 mois.
     * (Ajouter cette méthode si elle n'existe pas encore !)
     * @return array Tableau des 12 mois avec étiquettes.
     */
    protected function get_empty_monthly_stats() {
        $stats = [];
        $month_names = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
            7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
        ];

        for ($month = 1; $month <= 12; $month++) {
            $stats[$month] = [
                'month' => $month,
                'label' => $month_names[$month],
            ];
        }
        return $stats;
    }
    /**
     * Récupère la liste des projets qui ont été facturés pour un mois et une année donnés.
     * @param int $year Année cible.
     * @param int $month Mois cible (1 à 12).
     * @return array|object[] Détails des projets facturés.
     */
    public function get_invoiced_details($year, $month) {
        $rplp_multiplier = 1 + ($this->rplp_rate / 100); 

        // Calcul des bornes temporelles pour le mois
        $start_date = new DateTime("$year-$month-01");
        $end_date = new DateTime("$year-$month-01");
        $end_date->modify('+1 month');

        $start_timestamp = $start_date->getTimestamp();
        $end_timestamp = $end_date->getTimestamp();

        // Requête pour récupérer les projets facturés dans le mois (en utilisant la date de facturation, TimestampInvoiced)
        $sql = $this->wpdb->prepare("
            SELECT
                t1.hubspot_deal_id,
                t1.ObjetCommande,
                t2.Article,
                t2.Livre,
                t2.TimestampDateDeLivraisonFin,
                t2.Id AS article_id,
                -- Le montant facturé est le prix de vente * quantité * (1 - discount) AVEC RPLP
                (t2.sales_price * t2.Qty * (1 - (t2.discount / 100)) * %f) AS invoiced_amount_with_rplp,
                -- T2.invoiced_amount contient le montant facturé si le champ est rempli, sinon on prend notre calcul
                (t2.sales_price * t2.Qty * (1 - (t2.discount / 100)) * %f) AS final_invoiced_amount
            FROM {$this->table_projects} t1
            INNER JOIN {$this->table_articles} t2 ON t1.hubspot_deal_id = t2.hubspot_deal_id
            WHERE t1.isQotation IS NULL
            AND (t2.invoiced = 1 OR t2.invoiced IS NOT NULL) /* Marqué comme facturé */
            AND t2.invoiced >= %d /* Utilise la date de facturation */
            AND t2.invoiced < %d
            ORDER BY t1.ObjetCommande ASC
        ", $rplp_multiplier, $rplp_multiplier, $start_timestamp, $end_timestamp);

        // error_log('get SQL QUERY : ' . $sql);

        return $this->wpdb->get_results($sql);
    }
}