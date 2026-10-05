<?php
defined('ABSPATH') or die();

/**
 * Requêtes du dashboard "Projets" : suivi des livraisons, de la facturation, des achats et de la marge par projet.
 *
 * Un projet = une ligne de achats_liste_commande qui n'est pas une offre (isQotation vide ou 0).
 * L'année est celle de la date de commande du projet (TimestampDateCommande).
 *
 * Trois agrégats, fusionnés par hubspot_deal_id :
 *  - lignes de vente (achats_details_commande) : montant vendu, livré, facturé, retards ;
 *  - lignes d'achat fournisseur (achats_articles_cmd_fournisseurs, liées par IdCommandeClient) : quantités, coût ;
 *  - marge = vendu - coût d'achat en CHF. Les achats dans une autre devise ne sont pas convertis (aucun taux
 *    de change dans les plugins) : ils sont comptés à part et la marge est alors signalée comme approximative.
 *
 * Les lignes DED (dédouanement) et TRANS (transport) sont exclues des quantités mais comptées dans le coût.
 */
class ISPAG_Project_Repository {

    protected static $instance = null;
    protected $wpdb;

    /** États "purchase" (colonne ordre) : jusqu'à 18 = en cours ; 19 = matériel reçu ; 20 = facture validée. */
    const PURCHASE_MAX_OPEN = 18;

    public static function run() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    private function t($name) {
        return $this->wpdb->prefix . $name;
    }

    /** Devise de vente (réglage du constructeur de cuves), CHF par défaut. */
    public function sales_currency() {
        $c = strtoupper(trim((string) get_option('wpcb_currency', 'CHF')));
        return $c !== '' ? $c : 'CHF';
    }

    /**
     * @param int    $year
     * @param string $status 'all' | 'active' | 'closed'
     * @return array|WP_Error ['projects' => [...], 'totals' => [...]]
     */
    public function get_project_stats($year, $status = 'all') {
        $year  = (int) $year;
        $start = strtotime("$year-01-01 00:00:00");
        $end   = strtotime(($year + 1) . '-01-01 00:00:00');

        $where_status = '';
        if ($status === 'active') {
            $where_status = ' AND p.project_status = 1';
        } elseif ($status === 'closed') {
            $where_status = ' AND p.project_status = 0';
        }

        $projects = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT p.id, p.hubspot_deal_id AS deal_id, p.NumCommande AS number, p.ObjetCommande AS subject,
                    p.project_status AS status, p.project_manager AS manager_id,
                    p.TimestampDateCommande AS ordered_at, co.company_name AS customer
             FROM {$this->t('achats_liste_commande')} p
             LEFT JOIN {$this->t('ispag_companies')} co ON co.Id = p.AssociatedCompanyID
             WHERE (p.isQotation IS NULL OR p.isQotation = 0)
               AND p.TimestampDateCommande >= %d AND p.TimestampDateCommande < %d
               $where_status
             ORDER BY p.TimestampDateCommande DESC, p.id DESC",
            $start, $end
        ), ARRAY_A);
        if ($this->wpdb->last_error) {
            return $this->db_error();
        }
        if (!$projects) {
            return ['projects' => [], 'totals' => $this->empty_totals()];
        }

        $deal_ids = array_values(array_unique(array_map('strval', array_column($projects, 'deal_id'))));
        $in = implode(',', array_map('absint', $deal_ids));

        $sales = $this->sales_by_deal($in);
        if (is_wp_error($sales)) return $sales;
        $purchases = $this->purchases_by_deal($in);
        if (is_wp_error($purchases)) return $purchases;

        $totals = $this->empty_totals();
        $out    = [];
        foreach ($projects as $p) {
            $k = (string) $p['deal_id'];
            $s = $sales[$k]     ?? [];
            $a = $purchases[$k] ?? [];

            $sales_total = (float) ($s['sales_total'] ?? 0);
            $invoiced    = (float) ($s['invoiced_amount'] ?? 0);
            $lines       = (int) ($s['line_count'] ?? 0);
            $delivered   = (int) ($s['delivered_lines'] ?? 0);
            $cost        = (float) ($a['cost'] ?? 0);
            $foreign     = (int) ($a['foreign_lines'] ?? 0);
            $margin      = $sales_total - $cost;

            $name = '';
            if (!empty($p['manager_id'])) {
                $u = get_userdata((int) $p['manager_id']);
                $name = $u ? $u->display_name : '';
            }

            $row = [
                'deal_id'         => $k,
                'number'          => (string) $p['number'],
                'subject'         => (string) $p['subject'],
                'customer'        => (string) $p['customer'],
                'manager'         => $name,
                'active'          => (int) $p['status'] === 1,
                'ordered_at'      => (int) $p['ordered_at'],
                'sales_total'     => round($sales_total, 2),
                'invoiced'        => round($invoiced, 2),
                'to_invoice'      => round(max(0, $sales_total - $invoiced), 2),
                'lines'           => $lines,
                'delivered_lines' => $delivered,
                'remaining_lines' => max(0, $lines - $delivered),
                'late_lines'      => (int) ($s['late_lines'] ?? 0),
                'next_delivery'   => (int) ($s['next_delivery'] ?? 0),
                'purchase_orders' => (int) ($a['orders'] ?? 0),
                'orders_open'     => (int) ($a['orders_open'] ?? 0),
                'orders_received' => (int) ($a['orders_received'] ?? 0),
                'qty_ordered'     => (float) ($a['qty'] ?? 0),
                'qty_received'    => (float) ($a['qty_received'] ?? 0),
                'cost'            => round($cost, 2),
                'foreign_lines'   => $foreign,
                'margin'          => round($margin, 2),
                'margin_pct'      => $sales_total > 0 ? round($margin / $sales_total * 100, 1) : null,
            ];
            $out[] = $row;

            $totals['projects']++;
            $totals['sales']      += $row['sales_total'];
            $totals['invoiced']   += $row['invoiced'];
            $totals['to_invoice'] += $row['to_invoice'];
            $totals['remaining_lines'] += $row['remaining_lines'];
            $totals['late_lines'] += $row['late_lines'];
            $totals['cost']       += $row['cost'];
            $totals['foreign_lines'] += $foreign;
            if ($row['active']) $totals['active']++;
        }
        $totals['margin']     = round($totals['sales'] - $totals['cost'], 2);
        $totals['margin_pct'] = $totals['sales'] > 0 ? round($totals['margin'] / $totals['sales'] * 100, 1) : null;
        foreach (['sales', 'invoiced', 'to_invoice', 'cost'] as $f) {
            $totals[$f] = round($totals[$f], 2);
        }

        return ['projects' => $out, 'totals' => $totals];
    }

    protected function empty_totals() {
        return ['projects' => 0, 'active' => 0, 'sales' => 0.0, 'invoiced' => 0.0, 'to_invoice' => 0.0,
                'remaining_lines' => 0, 'late_lines' => 0, 'cost' => 0.0, 'foreign_lines' => 0,
                'margin' => 0.0, 'margin_pct' => null];
    }

    protected function db_error() {
        error_log('[ISPAG Project Dashboard] ' . $this->wpdb->last_error);
        return new WP_Error('db_error', $this->wpdb->last_error);
    }

    /** Lignes de vente par projet : montant vendu, livré, facturé, retards. @param string $in liste d'ids SQL sûre */
    protected function sales_by_deal($in) {
        $now = time();
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT d.hubspot_deal_id AS deal_id,
                    COUNT(*) AS line_count,
                    SUM(d.Qty * d.sales_price * (1 - d.discount / 100)) AS sales_total,
                    SUM(CASE WHEN d.Livre = 1 THEN 1 ELSE 0 END) AS delivered_lines,
                    SUM(CASE WHEN d.invoiced IS NOT NULL AND d.invoiced > 0
                             THEN d.Qty * d.sales_price * (1 - d.discount / 100) ELSE 0 END) AS invoiced_amount,
                    SUM(CASE WHEN (d.Livre IS NULL OR d.Livre = 0)
                              AND d.TimestampDateDeLivraison > 0 AND d.TimestampDateDeLivraison < %d THEN 1 ELSE 0 END) AS late_lines,
                    MIN(CASE WHEN (d.Livre IS NULL OR d.Livre = 0) AND d.TimestampDateDeLivraison >= %d
                             THEN d.TimestampDateDeLivraison END) AS next_delivery
             FROM {$this->t('achats_details_commande')} d
             WHERE d.hubspot_deal_id IN ($in) AND d.archive = 0
             GROUP BY d.hubspot_deal_id",
            $now, $now
        ), ARRAY_A);
        if ($this->wpdb->last_error) {
            return $this->db_error();
        }
        return array_column($rows, null, 'deal_id');
    }

    /** Achats fournisseurs par projet (lignes liées à une ligne de projet via IdCommandeClient). */
    protected function purchases_by_deal($in) {
        $service = "(UPPER(TRIM(COALESCE(art.RefSurMesure, ''))) IN ('DED', 'TRANS'))";
        $meta    = "(SELECT m.meta_value FROM {$this->t('ispag_companies_meta')} m
                     WHERE m.company_id = c.IdFournisseur AND m.meta_key = 'ispag_supplier_currency' AND m.meta_value <> ''
                     ORDER BY m.meta_id DESC LIMIT 1)";
        $sales_cur = esc_sql($this->sales_currency());
        $local = "(COALESCE($meta, '$sales_cur') = '$sales_cur')";
        $rows = $this->wpdb->get_results(
            "SELECT dp.hubspot_deal_id AS deal_id,
                    COUNT(DISTINCT c.Id) AS orders,
                    COUNT(DISTINCT CASE WHEN ec.ordre <= " . (int) self::PURCHASE_MAX_OPEN . " THEN c.Id END) AS orders_open,
                    COUNT(DISTINCT CASE WHEN ec.ordre > " . (int) self::PURCHASE_MAX_OPEN . " THEN c.Id END) AS orders_received,
                    SUM(CASE WHEN NOT $service THEN art.Qty ELSE 0 END) AS qty,
                    SUM(CASE WHEN NOT $service THEN art.Recu ELSE 0 END) AS qty_received,
                    SUM(CASE WHEN $local THEN art.UnitPrice * art.Qty * (1 - art.discount / 100) ELSE 0 END) AS cost,
                    SUM(CASE WHEN $local THEN 0 ELSE 1 END) AS foreign_lines
             FROM {$this->t('achats_articles_cmd_fournisseurs')} art
             INNER JOIN {$this->t('achats_commande_liste_fournisseurs')} c ON c.Id = art.IdCommande
             INNER JOIN {$this->t('achats_etat_commandes_fournisseur')} ec ON ec.Id = c.EtatCommande
             INNER JOIN {$this->t('achats_details_commande')} dp ON dp.Id = art.IdCommandeClient
             WHERE dp.hubspot_deal_id IN ($in)
               AND ec.steps = 'purchase'
               AND (art.archive IS NULL OR art.archive = 0)
             GROUP BY dp.hubspot_deal_id",
            ARRAY_A
        );
        if ($this->wpdb->last_error) {
            return $this->db_error();
        }
        return array_column($rows, null, 'deal_id');
    }
}
