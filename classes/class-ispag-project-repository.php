<?php
defined('ABSPATH') or die();

class ISPAG_Project_Repository {

    public $wpdb;
    protected $table_projects;
    protected $table_articles;
    protected $table_stats;
    protected $rplp_rate;
    protected static $instance = null;
    protected $cache = []; // Cache pour les résultats des requêtes

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

    /**
     * Récupère le multiplicateur RPLP (1 + rplp_rate/100).
     * @return float
     */
    protected function get_rplp_multiplier() {
        return 1 + ($this->rplp_rate / 100);
    }

    /**
     * Récupère les livraisons par mois avec cache.
     * @param int $start_timestamp Timestamp de début.
     * @param int $end_timestamp Timestamp de fin.
     * @return array|WP_Error Résultats ou erreur.
     */
    public function get_deliveries_by_month($start_timestamp, $end_timestamp) {
        $cache_key = "deliveries_{$start_timestamp}_{$end_timestamp}";
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $rplp_multiplier = $this->get_rplp_multiplier();
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
        ", $rplp_multiplier, $start_timestamp, $end_timestamp);

        $results = $this->wpdb->get_results($sql);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_error', $this->wpdb->last_error);
        }

        $this->cache[$cache_key] = $results;
        return $results;
    }

    /**
     * Récupère les projets prêts à être facturés avec cache.
     * @return array|WP_Error Résultats ou erreur.
     */
    public function get_projects_to_invoice() {
        $cache_key = 'projects_to_invoice';
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $rplp_multiplier = $this->get_rplp_multiplier();
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
            AND (t2.Livre IS NOT NULL OR t2.Livre != 0)
            AND (t2.invoiced IS NULL OR t2.invoiced = 0)
            ORDER BY t1.TimestampDateCommande DESC
        ", $rplp_multiplier);

        $results = $this->wpdb->get_results($sql);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_error', $this->wpdb->last_error);
        }

        $this->cache[$cache_key] = $results;
        return $results;
    }

    /**
     * Récupère les détails des livraisons pour une période donnée.
     * @param int $start_timestamp Timestamp de début.
     * @param int $end_timestamp Timestamp de fin.
     * @return array|WP_Error Résultats ou erreur.
     */
    public function get_deliveries_details($start_timestamp, $end_timestamp) {
        $cache_key = "deliveries_details_{$start_timestamp}_{$end_timestamp}";
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $rplp_multiplier = $this->get_rplp_multiplier();
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

        $results = $this->wpdb->get_results($sql);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_error', $this->wpdb->last_error);
        }

        $this->cache[$cache_key] = $results;
        return $results;
    }

    /**
     * Récupère les entrées de commande mensuelles pour une année donnée.
     * @param int $year Année à analyser.
     * @return array Montant des commandes par mois.
     */
    public function get_monthly_order_intakes($year) {
        $cache_key = "monthly_order_intakes_{$year}";
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

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

        if ($this->wpdb->last_error) {
            return new WP_Error('db_error', $this->wpdb->last_error);
        }

        $intakes = array_fill(1, 12, 0.0);
        if ($projects) {
            foreach ($projects as $project) {
                $month = intval($project->month_num);
                $project_repo = new ISPAG_Project_Details_Repository();
                $stats = $project_repo->get_project_profitability($project->hubspot_deal_id);
                $intakes[$month] += floatval($stats['revenu']);
            }
        }

        $this->cache[$cache_key] = $intakes;
        return $intakes;
    }

    /**
     * Récupère les objectifs mensuels pour un type donné.
     * @param int $year Année à analyser.
     * @param string $typ Type d'objectif ('order', 'invoice', etc.).
     * @return array Objectifs par mois.
     */
    public function get_monthly_targets($year, $typ) {
        $cache_key = "monthly_targets_{$year}_{$typ}";
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $sql = $this->wpdb->prepare("
            SELECT
                month,
                target
            FROM {$this->table_stats}
            WHERE typ = %s
            ORDER BY month ASC
        ", $typ);

        $results = $this->wpdb->get_results($sql, ARRAY_A);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_error', $this->wpdb->last_error);
        }

        $targets = array_fill(1, 12, 0);
        foreach ($results as $row) {
            $targets[intval($row['month'])] = intval($row['target']);
        }

        $this->cache[$cache_key] = $targets;
        return $targets;
    }

    /**
     * Récupère les détails des commandes pour un mois spécifique.
     * @param int $year Année de la commande.
     * @param int $month Mois de la commande (1-12).
     * @return array|WP_Error Détails des projets ou erreur.
     */
    public function get_order_details_by_month($year, $month) {
        $cache_key = "order_details_{$year}_{$month}";
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $start_timestamp = strtotime("$year-$month-01 00:00:00");
        $next_month = $month == 12 ? 1 : $month + 1;
        $next_year = $month == 12 ? $year + 1 : $year;
        $end_timestamp = strtotime("$next_year-$next_month-01 00:00:00");

        $rplp_multiplier = $this->get_rplp_multiplier();
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
        ", $rplp_multiplier, $start_timestamp, $end_timestamp);

        $results = $this->wpdb->get_results($sql);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_error', $this->wpdb->last_error);
        }

        $this->cache[$cache_key] = $results;
        return $results;
    }

    /**
     * Récupère les statistiques de facturation pour une année donnée.
     * @param int $year Année cible.
     * @return array Statistiques mensuelles et cumulatives.
     */
    public function get_invoice_stats_by_year($year) {
        $cache_key = "invoice_stats_{$year}";
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $stats = $this->get_monthly_targets_for_year($year);
        $year_start = strtotime("$year-01-01 00:00:00");
        $next_year_start = strtotime(($year + 1) . "-01-01 00:00:00");
        $rplp_multiplier = $this->get_rplp_multiplier();

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
        if ($this->wpdb->last_error) {
            return new WP_Error('db_error', $this->wpdb->last_error);
        }

        $monthly_invoices = [];
        foreach ($results as $row) {
            $monthly_invoices[intval($row['month_num'])] = floatval($row['total_invoice']);
        }

        $cumulative_intake = 0;
        $cumulative_target = 0;

        foreach ($stats as $month => &$item) {
            $item['invoice'] = $monthly_invoices[$month] ?? 0;
            $cumulative_intake += $item['invoice'];
            $cumulative_target += $item['target'];
            $item['cumulative_invoice'] = $cumulative_intake;
            $item['cumulative_target'] = $cumulative_target;
            $item['is_past'] = $month <= date('n') && $year <= date('Y');
            $item['intake'] = $item['invoice'];
            $item['cumulative_intake'] = $item['cumulative_invoice'];
            unset($item['invoice'], $item['cumulative_invoice']);
        }

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

        $result = [
            'monthly' => array_values($final_monthly),
            'cumulative' => array_values($final_cumulative)
        ];

        $this->cache[$cache_key] = $result;
        return $result;
    }

    /**
     * Récupère les objectifs pour une année et les structure par mois.
     * @param int $year Année cible.
     * @return array Tableau structuré des 12 mois avec leurs objectifs.
     */
    protected function get_monthly_targets_for_year($year) {
        $invoice_targets = $this->get_monthly_targets($year, 'invoice');
        if (is_wp_error($invoice_targets)) {
            return new WP_Error('targets_error', 'Impossible de récupérer les objectifs.');
        }

        $stats = [];
        $month_names = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
            7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
        ];

        for ($month = 1; $month <= 12; $month++) {
            $stats[$month] = [
                'month' => $month,
                'label' => $month_names[$month],
                'target' => $invoice_targets[$month] ?? 0,
            ];
        }

        return $stats;
    }

    /**
     * Récupère le nombre d'offres par mois pour une année donnée.
     * @param int $year Année cible.
     * @return array Statistiques des 12 mois.
     */
    public function get_quotation_counts_by_year($year) {
        $cache_key = "quotation_counts_{$year}";
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $quotation_stats = $this->get_empty_monthly_stats();
        $current_year_counts = $this->fetch_quotation_counts($year);
        $previous_year_counts = $this->fetch_quotation_counts($year - 1);

        foreach ($quotation_stats as $month => &$item) {
            $item['current_year_quantity'] = $current_year_counts[$month] ?? 0;
            $item['previous_year_quantity'] = $previous_year_counts[$month] ?? 0;
            $item['is_past'] = $month <= date('n') && $year <= date('Y');
        }

        $this->cache[$cache_key] = array_values($quotation_stats);
        return $this->cache[$cache_key];
    }

    /**
     * Récupère le nombre d'offres pour une année donnée.
     * @param int $target_year Année à cibler.
     * @return array Nombre d'offres par mois.
     */
    protected function fetch_quotation_counts($target_year) {
        $cache_key = "quotation_counts_raw_{$target_year}";
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

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
        if ($this->wpdb->last_error) {
            return new WP_Error('db_error', $this->wpdb->last_error);
        }

        $monthly_counts = array_fill(1, 12, 0);
        foreach ($results as $row) {
            $monthly_counts[intval($row['month_num'])] = intval($row['total_quotations']);
        }

        $this->cache[$cache_key] = $monthly_counts;
        return $monthly_counts;
    }

    /**
     * Initialise un tableau vide pour les 12 mois.
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
     * Récupère les détails des projets facturés pour un mois donné.
     * @param int $year Année cible.
     * @param int $month Mois cible (1 à 12).
     * @return array|WP_Error Détails des projets facturés ou erreur.
     */
    public function get_invoiced_details($year, $month) {
        $cache_key = "invoiced_details_{$year}_{$month}";
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $rplp_multiplier = $this->get_rplp_multiplier();
        $start_date = new DateTime("$year-$month-01");
        $end_date = new DateTime("$year-$month-01");
        $end_date->modify('+1 month');

        $start_timestamp = $start_date->getTimestamp();
        $end_timestamp = $end_date->getTimestamp();

        $sql = $this->wpdb->prepare("
            SELECT
                t1.hubspot_deal_id,
                t1.ObjetCommande,
                t2.Article,
                t2.Livre,
                t2.TimestampDateDeLivraisonFin,
                t2.Id AS article_id,
                (t2.sales_price * t2.Qty * (1 - (t2.discount / 100)) * %f) AS invoiced_amount_with_rplp,
                (t2.sales_price * t2.Qty * (1 - (t2.discount / 100)) * %f) AS final_invoiced_amount
            FROM {$this->table_projects} t1
            INNER JOIN {$this->table_articles} t2 ON t1.hubspot_deal_id = t2.hubspot_deal_id
            WHERE t1.isQotation IS NULL
            AND (t2.invoiced = 1 OR t2.invoiced IS NOT NULL)
            AND t2.invoiced >= %d
            AND t2.invoiced < %d
            ORDER BY t1.ObjetCommande ASC
        ", $rplp_multiplier, $rplp_multiplier, $start_timestamp, $end_timestamp);

        $results = $this->wpdb->get_results($sql);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_error', $this->wpdb->last_error);
        }

        $this->cache[$cache_key] = $results;
        return $results;
    }

    /**
     * Vide le cache des résultats.
     */
    public function clear_cache() {
        $this->cache = [];
    }
}