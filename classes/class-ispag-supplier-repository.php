<?php
defined('ABSPATH') or die();

/**
 * Requêtes du dashboard "Fournisseurs".
 *
 * Périmètre (validé) :
 *  - année de référence = date de passage de la commande (TimestampDateCreation)
 *  - CA = montant ACHETÉ : UnitPrice x Qty x (1 - discount/100), dans la devise du fournisseur
 *  - ne garde que les commandes dont l'état a steps = 'purchase' (de "Purchase request" à
 *    "Validated invoice") : exclut les demandes de prix (proposal), annulées et en pause
 *  - exclut les lignes archivées
 *  - délais calculés uniquement sur les lignes reçues (Recu > 0, date de réception > 0)
 */
class ISPAG_Supplier_Repository {

    protected static $instance = null;
    protected $wpdb;
    protected $cache = [];

    protected $t_orders;
    protected $t_lines;
    protected $t_suppliers;
    protected $t_meta;
    protected $t_states;
    protected $t_details;
    protected $t_types;
    protected $t_projects;

    public static function run() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->wpdb        = $wpdb;
        $this->t_orders    = $wpdb->prefix . 'achats_commande_liste_fournisseurs';
        $this->t_lines     = $wpdb->prefix . 'achats_articles_cmd_fournisseurs';
        $this->t_suppliers = $wpdb->prefix . 'ispag_companies';
        $this->t_meta      = $wpdb->prefix . 'ispag_companies_meta';
        $this->t_states    = $wpdb->prefix . 'achats_etat_commandes_fournisseur';
        $this->t_details   = $wpdb->prefix . 'achats_details_commande';
        $this->t_types     = $wpdb->prefix . 'achats_type_prestations';
        $this->t_projects  = $wpdb->prefix . 'achats_liste_commande';
    }

    /**
     * Statistiques brutes d'une année, indexées par id fournisseur.
     *
     * @return array|WP_Error
     */
    protected function fetch_year($year, $only_products) {
        $cache_key = "supplier_year_{$year}_" . ($only_products ? 'p' : 'all');
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $start = strtotime("$year-01-01 00:00:00");
        $end   = strtotime(($year + 1) . "-01-01 00:00:00");

        // Ligne livrée (quantité reçue ou commande au stade "reçu / facturé")
        $delivered = $this->delivered_sql();
        // Date de livraison retenue : date réelle si elle existe, sinon date confirmée par le fournisseur
        $delivery_date = $this->delivery_date_sql();
        // Ligne livrée avec date réelle ET date confirmée (retard et ponctualité)
        $confirmed = $this->actual_vs_confirmed_sql();

        $product_filter = $only_products ? "AND tp.prestation = 'Product'" : '';

        $sql = $this->wpdb->prepare("
            SELECT
                f.Id AS supplier_id,
                f.company_name AS supplier_name,
                MAX({$this->supplier_meta_sql('ispag_supplier_currency')}) AS currency,
                MAX({$this->supplier_meta_sql('ispag_supplier_delivery_days')}) AS contractual_days,
                COUNT(DISTINCT c.Id) AS orders_count,
                SUM(art.Qty) AS qty,
                SUM(art.UnitPrice * art.Qty * (1 - art.discount / 100)) AS amount,
                AVG(CASE WHEN $delivered AND $delivery_date >= c.TimestampDateCreation
                         THEN ($delivery_date - c.TimestampDateCreation) / 86400 END) AS avg_lead_days,
                AVG(CASE WHEN $confirmed
                         THEN (art.TimestampDateLivraison - art.TimestampDateLivraisonConfirme) / 86400 END) AS avg_delay_days,
                SUM(CASE WHEN $confirmed THEN 1 ELSE 0 END) AS confirmed_lines,
                SUM(CASE WHEN $confirmed
                          AND art.TimestampDateLivraison <= art.TimestampDateLivraisonConfirme + 86399
                         THEN 1 ELSE 0 END) AS on_time_lines
            FROM {$this->t_orders} c
            INNER JOIN {$this->t_lines} art ON art.IdCommande = c.Id
            INNER JOIN {$this->t_suppliers} f ON f.Id = c.IdFournisseur
            INNER JOIN {$this->t_states} ec ON ec.Id = c.EtatCommande
            LEFT JOIN {$this->t_details} dp ON dp.Id = art.IdCommandeClient
            LEFT JOIN {$this->t_types} tp ON tp.Id = dp.Type
            WHERE c.TimestampDateCreation >= %d
              AND c.TimestampDateCreation < %d
              AND ec.steps = 'purchase'
              AND (art.archive IS NULL OR art.archive = 0)
              AND {$this->not_service_line_sql()}
              $product_filter
            GROUP BY f.Id, f.company_name
            ORDER BY amount DESC
        ", $start, $end);

        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_error', $this->wpdb->last_error);
        }

        $by_id = [];
        foreach ((array) $rows as $row) {
            $by_id[(int) $row['supplier_id']] = $row;
        }

        $this->cache[$cache_key] = $by_id;
        return $by_id;
    }

    /**
     * Statistiques fournisseurs d'une année, comparées à N-1.
     *
     * @return array|WP_Error ['year' => int, 'rows' => array, 'totals' => array par devise]
     */
    public function get_supplier_stats($year, $only_products = true) {
        $year = (int) $year;
        $current = $this->fetch_year($year, $only_products);
        if (is_wp_error($current)) {
            return $current;
        }
        $previous = $this->fetch_year($year - 1, $only_products);
        if (is_wp_error($previous)) {
            return $previous;
        }

        // Totaux par devise (on n'additionne jamais des devises différentes)
        $totals = [];
        foreach ($current as $r) {
            $cur = $this->normalize_currency($r['currency']);
            if (!isset($totals[$cur])) {
                $totals[$cur] = ['amount' => 0.0, 'qty' => 0, 'orders' => 0];
            }
            $totals[$cur]['amount'] += (float) $r['amount'];
            $totals[$cur]['qty']    += (int) $r['qty'];
            $totals[$cur]['orders'] += (int) $r['orders_count'];
        }

        $rows = [];
        foreach ($current as $id => $r) {
            $cur    = $this->normalize_currency($r['currency']);
            $amount = (float) $r['amount'];
            $qty    = (int) $r['qty'];
            $orders = (int) $r['orders_count'];

            $prev        = isset($previous[$id]) ? $previous[$id] : null;
            $prev_amount = $prev ? (float) $prev['amount'] : 0.0;
            $prev_qty    = $prev ? (int) $prev['qty'] : 0;

            $confirmed = (int) $r['confirmed_lines'];
            $total_cur = $totals[$cur]['amount'];

            $rows[] = [
                'supplier_id'      => (int) $id,
                'name'             => $r['supplier_name'],
                'currency'         => $cur,
                'amount'           => round($amount, 2),
                'prev_amount'      => round($prev_amount, 2),
                'evo_amount'       => $prev_amount > 0 ? round((($amount / $prev_amount) - 1) * 100, 1) : null,
                'share'            => $total_cur > 0 ? round($amount / $total_cur * 100, 1) : null,
                'qty'              => $qty,
                'prev_qty'         => $prev_qty,
                'evo_qty'          => $prev_qty > 0 ? round((($qty / $prev_qty) - 1) * 100, 1) : null,
                'orders'           => $orders,
                'avg_basket'       => $orders > 0 ? round($amount / $orders, 2) : null,
                'avg_unit_price'   => $qty > 0 ? round($amount / $qty, 2) : null,
                'avg_lead_days'    => $r['avg_lead_days'] !== null ? round((float) $r['avg_lead_days'], 1) : null,
                'contractual_days' => (int) $r['contractual_days'] > 0 ? (int) $r['contractual_days'] : null,
                'avg_delay_days'   => $r['avg_delay_days'] !== null ? round((float) $r['avg_delay_days'], 1) : null,
                'on_time_pct'      => $confirmed > 0 ? round((int) $r['on_time_lines'] / $confirmed * 100, 1) : null,
            ];
        }

        foreach ($totals as $cur => $t) {
            $totals[$cur]['amount'] = round($t['amount'], 2);
        }

        return [
            'year'   => $year,
            'rows'   => $rows,
            'totals' => $totals,
        ];
    }

    // =====================================================================
    // LOT 2 : fiche fournisseur et retards en cours
    // =====================================================================

    /**
     * Plage d'états "purchase" (colonne ordre) où le matériel n'est pas encore considéré comme reçu :
     * de 11 (Purchase request) à 18 (Order confirmed). 19 = Materials received, 20 = Validated invoice.
     */
    const LATE_MIN_ORDRE = 11;
    const LATE_MAX_ORDRE = 18;

    /** Valeur d'une meta fournisseur (ispag_companies_meta) pour la ligne f ; la plus récente non vide. */
    protected function supplier_meta_sql($meta_key) {
        return "(SELECT m.meta_value FROM {$this->t_meta} m
                 WHERE m.company_id = f.Id AND m.meta_key = '" . esc_sql($meta_key) . "' AND m.meta_value <> ''
                 ORDER BY m.meta_id DESC LIMIT 1)";
    }

    protected function base_from() {
        return "FROM {$this->t_orders} c
            INNER JOIN {$this->t_lines} art ON art.IdCommande = c.Id
            INNER JOIN {$this->t_suppliers} f ON f.Id = c.IdFournisseur
            INNER JOIN {$this->t_states} ec ON ec.Id = c.EtatCommande
            LEFT JOIN {$this->t_details} dp ON dp.Id = art.IdCommandeClient
            LEFT JOIN {$this->t_types} tp ON tp.Id = dp.Type";
    }

    /** Exclut les lignes de frais (DED = dédouanement, TRANS = transport) : ce n'est pas du matériel commandé. */
    protected function not_service_line_sql() {
        return "UPPER(TRIM(COALESCE(art.RefSurMesure, ''))) NOT IN ('DED', 'TRANS')";
    }

    protected function base_where($only_products) {
        $sql = "ec.steps = 'purchase' AND (art.archive IS NULL OR art.archive = 0) AND " . $this->not_service_line_sql();
        if ($only_products) {
            $sql .= " AND tp.prestation = 'Product'";
        }
        return $sql;
    }

    /** Ligne livrée : quantité reçue renseignée, ou commande déjà au stade "Materials received" / "Validated invoice". */
    protected function delivered_sql() {
        return '(art.Recu > 0 OR ec.ordre > ' . (int) self::LATE_MAX_ORDRE . ')';
    }

    /** Date de livraison retenue : date réelle de la ligne, sinon date confirmée (le fournisseur livre directement sur chantier). */
    protected function delivery_date_sql() {
        return 'COALESCE(NULLIF(art.TimestampDateLivraison, 0), NULLIF(art.TimestampDateLivraisonConfirme, 0))';
    }

    /** Ligne livrée avec une date réelle ET une date confirmée (retard, ponctualité). */
    protected function actual_vs_confirmed_sql() {
        return $this->delivered_sql() . ' AND art.TimestampDateLivraison > 0 AND art.TimestampDateLivraisonConfirme > 0';
    }

    protected function amount_sql() {
        return 'art.UnitPrice * art.Qty * (1 - art.discount / 100)';
    }

    /**
     * Libellé d'article : référence sur mesure, sinon article de la ligne client, sinon description, sinon id standard.
     */
    protected function article_label_sql() {
        return "LEFT(COALESCE(NULLIF(TRIM(art.RefSurMesure), ''), NULLIF(TRIM(dp.Article), ''), NULLIF(TRIM(art.DescSurMesure), ''), CONCAT('#', art.IdArticleStandard)), 150)";
    }

    /**
     * Fiche complète d'un fournisseur pour une année.
     *
     * @return array|WP_Error
     */
    public function get_supplier_detail($supplier_id, $year, $only_products = true) {
        $supplier_id = (int) $supplier_id;
        $year        = (int) $year;

        $supplier = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT f.Id, f.company_name AS Fournisseur,
                {$this->supplier_meta_sql('ispag_supplier_currency')} AS Monnaie,
                {$this->supplier_meta_sql('ispag_supplier_delivery_days')} AS deliveryDays
             FROM {$this->t_suppliers} f WHERE f.Id = %d",
            $supplier_id
        ), ARRAY_A);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_error', $this->wpdb->last_error);
        }
        if (!$supplier) {
            return new WP_Error('not_found', 'Supplier not found.');
        }

        $history = $this->get_supplier_history($supplier_id, $year, $only_products);
        if (is_wp_error($history)) {
            return $history;
        }
        $articles = $this->get_supplier_top_articles($supplier_id, $year, $only_products, 10);
        if (is_wp_error($articles)) {
            return $articles;
        }
        $projects = $this->get_supplier_projects($supplier_id, $year, $only_products, 15);
        if (is_wp_error($projects)) {
            return $projects;
        }
        $orders = $this->get_supplier_recent_orders($supplier_id, $year, $only_products, 10);
        if (is_wp_error($orders)) {
            return $orders;
        }
        $late = $this->get_late_deliveries($only_products, $supplier_id, 200);
        if (is_wp_error($late)) {
            return $late;
        }

        return [
            'year'     => $year,
            'supplier' => [
                'id'               => (int) $supplier['Id'],
                'name'             => $supplier['Fournisseur'],
                'currency'         => $this->normalize_currency($supplier['Monnaie']),
                'contractual_days' => (int) $supplier['deliveryDays'] > 0 ? (int) $supplier['deliveryDays'] : null,
            ],
            'history'  => $history,
            'articles' => $articles,
            'projects' => $projects,
            'orders'   => $orders,
            'late'     => $late['rows'],
        ];
    }

    /**
     * Historique sur 5 ans (année demandée incluse, la plus récente en premier).
     */
    protected function get_supplier_history($supplier_id, $year, $only_products) {
        $history = [];
        for ($i = 0; $i < 5; $i++) {
            $y   = $year - $i;
            $all = $this->fetch_year($y, $only_products);
            if (is_wp_error($all)) {
                return $all;
            }
            $r = isset($all[$supplier_id]) ? $all[$supplier_id] : null;
            if (!$r) {
                $history[] = [
                    'year' => $y, 'amount' => 0, 'qty' => 0, 'orders' => 0,
                    'avg_lead_days' => null, 'avg_delay_days' => null, 'on_time_pct' => null,
                ];
                continue;
            }
            $confirmed = (int) $r['confirmed_lines'];
            $history[] = [
                'year'           => $y,
                'amount'         => round((float) $r['amount'], 2),
                'qty'            => (int) $r['qty'],
                'orders'         => (int) $r['orders_count'],
                'avg_lead_days'  => $r['avg_lead_days'] !== null ? round((float) $r['avg_lead_days'], 1) : null,
                'avg_delay_days' => $r['avg_delay_days'] !== null ? round((float) $r['avg_delay_days'], 1) : null,
                'on_time_pct'    => $confirmed > 0 ? round((int) $r['on_time_lines'] / $confirmed * 100, 1) : null,
            ];
        }
        return $history;
    }

    /**
     * Top articles de l'année, avec le prix unitaire moyen de N-1 pour repérer les hausses.
     */
    protected function get_supplier_top_articles($supplier_id, $year, $only_products, $limit) {
        $start_n = strtotime("$year-01-01 00:00:00");
        $start_p = strtotime(($year - 1) . "-01-01 00:00:00");
        $end_n   = strtotime(($year + 1) . "-01-01 00:00:00");
        $label   = $this->article_label_sql();
        $amount  = $this->amount_sql();

        $sql = $this->wpdb->prepare("
            SELECT
                {$label} AS article_label,
                SUM(CASE WHEN c.TimestampDateCreation >= %d THEN art.Qty ELSE 0 END) AS qty_n,
                SUM(CASE WHEN c.TimestampDateCreation >= %d THEN {$amount} ELSE 0 END) AS amount_n,
                SUM(CASE WHEN c.TimestampDateCreation < %d THEN art.Qty ELSE 0 END) AS qty_p,
                SUM(CASE WHEN c.TimestampDateCreation < %d THEN {$amount} ELSE 0 END) AS amount_p
            {$this->base_from()}
            WHERE {$this->base_where($only_products)}
              AND c.IdFournisseur = %d
              AND c.TimestampDateCreation >= %d
              AND c.TimestampDateCreation < %d
            GROUP BY article_label
            HAVING qty_n > 0
            ORDER BY amount_n DESC
            LIMIT %d
        ", $start_n, $start_n, $start_n, $start_n, $supplier_id, $start_p, $end_n, $limit);

        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_error', $this->wpdb->last_error);
        }

        $out = [];
        foreach ((array) $rows as $r) {
            $qty_n   = (int) $r['qty_n'];
            $qty_p   = (int) $r['qty_p'];
            $unit_n  = $qty_n > 0 ? (float) $r['amount_n'] / $qty_n : null;
            $unit_p  = $qty_p > 0 ? (float) $r['amount_p'] / $qty_p : null;
            $out[] = [
                'article'         => $r['article_label'],
                'qty'             => $qty_n,
                'amount'          => round((float) $r['amount_n'], 2),
                'avg_unit_price'  => $unit_n !== null ? round($unit_n, 2) : null,
                'prev_unit_price' => $unit_p !== null ? round($unit_p, 2) : null,
                'price_evo'       => ($unit_n !== null && $unit_p !== null && $unit_p > 0)
                    ? round((($unit_n / $unit_p) - 1) * 100, 1) : null,
            ];
        }
        return $out;
    }

    /**
     * Projets clients pour lesquels des achats ont été faits chez ce fournisseur.
     */
    protected function get_supplier_projects($supplier_id, $year, $only_products, $limit) {
        $start = strtotime("$year-01-01 00:00:00");
        $end   = strtotime(($year + 1) . "-01-01 00:00:00");

        $sql = $this->wpdb->prepare("
            SELECT
                pl.hubspot_deal_id AS deal_id,
                pl.ObjetCommande AS project_name,
                SUM(art.Qty) AS qty,
                SUM({$this->amount_sql()}) AS amount
            {$this->base_from()}
            INNER JOIN {$this->t_projects} pl ON pl.hubspot_deal_id = dp.hubspot_deal_id
            WHERE {$this->base_where($only_products)}
              AND c.IdFournisseur = %d
              AND c.TimestampDateCreation >= %d
              AND c.TimestampDateCreation < %d
            GROUP BY pl.hubspot_deal_id, pl.ObjetCommande
            ORDER BY amount DESC
            LIMIT %d
        ", $supplier_id, $start, $end, $limit);

        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_error', $this->wpdb->last_error);
        }

        $out = [];
        foreach ((array) $rows as $r) {
            $out[] = [
                'deal_id'      => (string) $r['deal_id'],
                'project_name' => $r['project_name'],
                'qty'          => (int) $r['qty'],
                'amount'       => round((float) $r['amount'], 2),
            ];
        }
        return $out;
    }

    /**
     * Dernières commandes de l'année avec leur délai réel.
     */
    protected function get_supplier_recent_orders($supplier_id, $year, $only_products, $limit) {
        $start = strtotime("$year-01-01 00:00:00");
        $end   = strtotime(($year + 1) . "-01-01 00:00:00");

        $sql = $this->wpdb->prepare("
            SELECT
                c.Id AS order_id,
                c.NrCommande AS number,
                c.RefCommande AS ref,
                ec.Etat AS state,
                c.TimestampDateCreation AS created,
                SUM({$this->amount_sql()}) AS amount,
                SUM(art.Qty) AS qty,
                SUM(art.Recu) AS qty_received,
                AVG(CASE WHEN {$this->delivered_sql()} AND {$this->delivery_date_sql()} >= c.TimestampDateCreation
                         THEN ({$this->delivery_date_sql()} - c.TimestampDateCreation) / 86400 END) AS lead_days,
                AVG(CASE WHEN {$this->actual_vs_confirmed_sql()}
                         THEN (art.TimestampDateLivraison - art.TimestampDateLivraisonConfirme) / 86400 END) AS delay_days
            {$this->base_from()}
            WHERE {$this->base_where($only_products)}
              AND c.IdFournisseur = %d
              AND c.TimestampDateCreation >= %d
              AND c.TimestampDateCreation < %d
            GROUP BY c.Id, c.NrCommande, c.RefCommande, ec.Etat, c.TimestampDateCreation
            ORDER BY c.TimestampDateCreation DESC
            LIMIT %d
        ", $supplier_id, $start, $end, $limit);

        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_error', $this->wpdb->last_error);
        }

        $out = [];
        foreach ((array) $rows as $r) {
            $out[] = [
                'order_id'     => (int) $r['order_id'],
                'number'       => (string) $r['number'],
                'ref'          => (string) $r['ref'],
                'state'        => $r['state'],
                'created'      => (int) $r['created'],
                'amount'       => round((float) $r['amount'], 2),
                'qty'          => (int) $r['qty'],
                'qty_received' => (int) $r['qty_received'],
                'lead_days'    => $r['lead_days'] !== null ? round((float) $r['lead_days'], 1) : null,
                'delay_days'   => $r['delay_days'] !== null ? round((float) $r['delay_days'], 1) : null,
            ];
        }
        return $out;
    }

    /**
     * Livraisons en retard : lignes non (totalement) reçues dont la date de livraison confirmée est dépassée.
     * Les lignes sans date confirmée sont ignorées. Seules les commandes pas encore "reçues" sont concernées.
     *
     * @param int $supplier_id 0 = tous les fournisseurs
     * @return array|WP_Error ['rows' => array, 'summary' => array]
     */
    public function get_late_deliveries($only_products = true, $supplier_id = 0, $limit = 2000) {
        $now          = time();
        $limit        = max(1, (int) $limit);
        $supplier_sql = (int) $supplier_id > 0 ? ' AND c.IdFournisseur = ' . (int) $supplier_id : '';

        $sql = $this->wpdb->prepare("
            SELECT
                f.Id AS supplier_id,
                f.company_name AS supplier_name,
                {$this->supplier_meta_sql('ispag_supplier_currency')} AS currency,
                c.Id AS order_id,
                c.NrCommande AS order_number,
                ec.Etat AS state,
                COALESCE(NULLIF(CAST(dp.hubspot_deal_id AS CHAR), ''), NULLIF(CAST(c.hubspot_deal_id AS CHAR), '')) AS deal_id,
                {$this->article_label_sql()} AS article,
                (art.Qty - art.Recu) AS qty_open,
                art.TimestampDateLivraisonConfirme AS confirmed,
                FLOOR((%d - art.TimestampDateLivraisonConfirme) / 86400) AS days_late,
                art.UnitPrice * (art.Qty - art.Recu) * (1 - art.discount / 100) AS amount_open
            {$this->base_from()}
            WHERE {$this->base_where($only_products)}
              AND ec.ordre BETWEEN %d AND %d
              AND art.Qty > art.Recu
              AND (dp.Livre IS NULL OR dp.Livre = 0)
              AND art.TimestampDateLivraisonConfirme > 0
              AND art.TimestampDateLivraisonConfirme < %d
              {$supplier_sql}
            ORDER BY art.TimestampDateLivraisonConfirme ASC
            LIMIT %d
        ", $now, self::LATE_MIN_ORDRE, self::LATE_MAX_ORDRE, $now, $limit);

        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_error', $this->wpdb->last_error);
        }

        $out        = [];
        $orders     = [];
        $suppliers  = [];
        $by_cur     = [];
        $max_late   = 0;

        foreach ((array) $rows as $r) {
            $cur    = $this->normalize_currency($r['currency']);
            $amount = round((float) $r['amount_open'], 2);
            $late   = (int) $r['days_late'];

            $orders[(int) $r['order_id']]        = true;
            $suppliers[(int) $r['supplier_id']]  = true;
            $by_cur[$cur]                        = (isset($by_cur[$cur]) ? $by_cur[$cur] : 0) + $amount;
            $max_late                            = max($max_late, $late);

            $out[] = [
                'supplier_id'   => (int) $r['supplier_id'],
                'supplier_name' => $r['supplier_name'],
                'currency'      => $cur,
                'order_id'      => (int) $r['order_id'],
                'order_number'  => (string) $r['order_number'],
                'state'         => $r['state'],
                'deal_id'       => $r['deal_id'] !== null ? (string) $r['deal_id'] : '',
                'article'       => $r['article'],
                'qty_open'      => (int) $r['qty_open'],
                'confirmed'     => (int) $r['confirmed'],
                'days_late'     => $late,
                'amount_open'   => $amount,
            ];
        }

        foreach ($by_cur as $cur => $sum) {
            $by_cur[$cur] = round($sum, 2);
        }

        return [
            'rows'    => $out,
            'summary' => [
                'lines'       => count($out),
                'orders'      => count($orders),
                'suppliers'   => count($suppliers),
                'by_currency' => $by_cur,
                'max_days'    => $max_late,
            ],
        ];
    }

    protected function normalize_currency($value) {
        $cur = strtoupper(trim((string) $value));
        return $cur !== '' ? $cur : 'N/A';
    }

    public function clear_cache() {
        $this->cache = [];
    }
}