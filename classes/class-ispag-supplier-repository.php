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
    protected $t_states;
    protected $t_details;
    protected $t_types;

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
        $this->t_suppliers = $wpdb->prefix . 'achats_fournisseurs';
        $this->t_states    = $wpdb->prefix . 'achats_etat_commandes_fournisseur';
        $this->t_details   = $wpdb->prefix . 'achats_details_commande';
        $this->t_types     = $wpdb->prefix . 'achats_type_prestations';
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

        // Ligne reçue avec date de réception exploitable
        $received = "art.Recu > 0 AND art.TimestampDateLivraison > 0";
        // Ligne reçue ET avec une date de livraison confirmée (pour la ponctualité)
        $confirmed = "$received AND art.TimestampDateLivraisonConfirme > 0";

        $product_filter = $only_products ? "AND tp.prestation = 'Product'" : '';

        $sql = $this->wpdb->prepare("
            SELECT
                f.Id AS supplier_id,
                f.Fournisseur AS supplier_name,
                f.Monnaie AS currency,
                f.deliveryDays AS contractual_days,
                COUNT(DISTINCT c.Id) AS orders_count,
                SUM(art.Qty) AS qty,
                SUM(art.UnitPrice * art.Qty * (1 - art.discount / 100)) AS amount,
                AVG(CASE WHEN $received AND art.TimestampDateLivraison >= c.TimestampDateCreation
                         THEN (art.TimestampDateLivraison - c.TimestampDateCreation) / 86400 END) AS avg_lead_days,
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
              AND (art.RefSurMesure != 'DED' OR art.RefSurMesure IS NULL)
              $product_filter
            GROUP BY f.Id, f.Fournisseur, f.Monnaie, f.deliveryDays
            ORDER BY amount DESC
        ", $start, $end);

        // error_log('[SUPPLIER REPOSITORY] requette sql ' . $sql);
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

    protected function normalize_currency($value) {
        $cur = strtoupper(trim((string) $value));
        return $cur !== '' ? $cur : 'N/A';
    }

    public function clear_cache() {
        $this->cache = [];
    }
}