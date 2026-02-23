<?php
defined('ABSPATH') or die();

class ISPAG_Project_Dashboard {

    protected static $instance = null;

    public static function run() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Ajouter la page d'administration
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Enregistrer les scripts et styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Ajoute la page "Tableau de Bord Projets" dans le menu Admin
     */
    public function add_admin_menu() {
        // add_menu_page(
        //     __('Project ISPAG', 'creation-reservoir'),
        //     __('Project ISPAG', 'creation-reservoir'),
        //     'manage_options', // Capacité requise
        //     'ispag-project-dashboard',
        //     [$this, 'render_dashboard_page'],
        //     'dashicons-clipboard', // Icône
        //     20 // Position dans le menu
        // );
        add_submenu_page(
            'ispag_main_menu', // Parent slug
            __('Project ISPAG', 'creation-reservoir'),
            __('Project ISPAG', 'creation-reservoir'),
            'manage_options', // Capacité requise
            'ispag-project-dashboard',
            [$this, 'render_dashboard_page'],
            
        );

        add_submenu_page(
            'ispag_main_menu', // Parent slug
            __('Orders', 'creation-reservoir'),
            __('Orders', 'creation-reservoir'),
            'manage_options',
            'ispag-order-stats', // Nouveau slug
            [$this, 'render_order_stats_page']
        );

        add_submenu_page(
            'ispag_main_menu', // Parent slug
            __('Invoices', 'creation-reservoir'),
            __('Invoices', 'creation-reservoir'),
            'manage_options',
            'ispag-invoice-stats', // Nouveau slug
            [$this, 'render_invoice_stats_page']
        );

        add_submenu_page(
            'ispag_main_menu', // Parent slug
            __('Qotations', 'creation-reservoir'),
            __('Qotations', 'creation-reservoir'),
            'manage_options',
            'ispag-quotation-stats', // Nouveau slug
            [$this, 'ispag_pd_quotation_stats_page_callback']
        );
        add_submenu_page(
            'ispag_main_menu', // Parent slug
            __('Supplier Follow-up', 'creation-reservoir'),
            __('Supplier Follow-up', 'creation-reservoir'),
            'manage_options',
            'ispag-supplier-tracking', // Nouveau slug
            [$this, 'ispag_supplier_tracking_page_content']
        );  
        
        add_submenu_page(
            'ispag_main_menu', // Parent slug
            __('Engineer/Competitor Tracking', 'creation-reservoir'),
            __('Engineer/Competitor Tracking', 'creation-reservoir'),
            'manage_options',
            'ispag-engineer-tracking', // Nouveau slug
            [$this, 'ispag_engineer_tracking_page_content']
        );  
        
    }

    /**
     * Affiche le contenu de la page d'administration
     */
    public function render_dashboard_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        include ISPAG_PD_PATH . 'classes/admin/project-dashboard-page.php';
    }
    
    /**
     * Charge les scripts et styles nécessaires (Mise à jour)
     */
    public function enqueue_scripts($hook) {
        // Charger uniquement sur les pages du tableau de bord
        // if ('toplevel_page_ispag-project-dashboard' != $hook && 'ispag-project-dashboard_page_ispag-order-stats' != $hook) {
        //     return; // Ne charge rien si ce n'est ni l'une ni l'autre de nos pages.
        // }

        // Styles
        wp_enqueue_style('ispag-pd-style', ISPAG_PD_URL . 'assets/css/style.css', array(), '1.0.0');
        
        // Bibliothèque Chart.js pour les graphiques
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true);


        // Scripts
        wp_enqueue_script(
            'ispag-pd-script',
            ISPAG_PD_URL . 'assets/js/dashboard.js',
            array('jquery', 'chart-js'), // Dépendance à Chart.js
            '1.0.0',
            true 
        );

        // Passer des variables PHP au script JS
        wp_localize_script('ispag-pd-script', 'ispagDashboard', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ispag-project-dashboard-nonce'),
            'current_time' => time(),
            'current_year' => date('Y'),
        ));
    }

    /**
     * Affiche le contenu de la page d'analyse des commandes
     */
    public function render_order_stats_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        include ISPAG_PD_PATH . 'classes/admin/order-stats-page.php';
    }

    /**
     * Affiche la page des statistiques de facturation (Invoice Stats)
     */
    public function render_invoice_stats_page() {
        // Nous allons réutiliser le même type de page que les commandes
        $current_year = date('Y');
        
        // C'est une bonne pratique d'injecter des variables dans le template si besoin
        include(ISPAG_PD_PATH . 'classes/admin/invoice-stats-page.php');
    }

    public function ispag_pd_quotation_stats_page_callback() {
        // Inclure votre template de page (ispag-quotation-stats-page.php)
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
        $old_table = $wpdb->prefix . 'achats_fournisseurs'; 
        $new_table = ISPAG_Crm_Company_Constants::TABLE_NAME; 

        // 2. REQUÊTE SQL HYBRIDE (Ancienne vs Nouvelle table)
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
        $non_attribue_label = 'Non Attribué';

        if ($results) {
            foreach ($results as $row) {
                $engineer = trim($row->IngenieurNom);
                if (empty($engineer) || $engineer === '0') $engineer = $non_attribue_label;
                
                $competitors_string = str_replace([';', '|'], ',', $row->EnSoumission);
                $competitors = array_filter(array_map('trim', explode(',', $competitors_string)));
                
                if (!empty($competitors)) {
                    $total_submissions_count++; 
                    if (!isset($engineer_tracking[$engineer])) {
                        $engineer_tracking[$engineer] = ['total_submissions' => 0, 'competitors' => []];
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
            /* Alignement gauche pour les noms */
            .ispag-stats-table th.col-name, .ispag-stats-table td.col-name { text-align: left !important; padding-left: 15px; border-right: 1px solid #eee; }
            .ispag-stats-table td { padding: 10px; border-bottom: 1px solid #f0f0f0; font-size: 13px; text-align: center; }
            .ispag-stats-table tr:hover { background-color: #fffafa !important; }
            /* Colonne Total mise en évidence */
            .ispag-stats-table .col-total { background: #fdfdfd; font-weight: bold; color: #d32f2f; width: 80px; border-right: 1px solid #eee; }
            .ispag-stats-table .col-name { font-weight: 600; color: #333; }
            .ispag-stats-table tfoot tr { background: #32373c; color: #fff; font-weight: bold; }
            .ispag-stats-table tfoot td { padding: 12px 10px; }
            .empty-val { color: #ccc; font-size: 11px; }
            .year-selector { margin-bottom: 15px; padding: 10px; background: #fff; border: 1px solid #ccd0d4; display: inline-block; }
        </style>

        <div class="wrap ispag-stats-container">
            <h1><i class="dashicons dashicons-chart-area"></i> Suivi Ingénieurs & Concurrents</h1>
            <p>Analyse des soumissions par ingénieur et répartition des concurrents cités.</p>
            
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
                        foreach ($engineer_tracking as $engineer => $data): ?>
                            <tr>
                                <td class="col-name"><?php echo esc_html($engineer); ?></td>
                                <td class="col-total"><?php echo $data['total_submissions']; ?></td>
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