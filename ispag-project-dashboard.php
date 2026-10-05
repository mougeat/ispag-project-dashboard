<?php
/**
 * Plugin Name: ISPAG - Project Dashboard
 * Description: Provides a dashboard to track projects, deliveries (remaining to deliver) and billing.
 * Version: 1.0.0
 * Author: Cyril Barthel
 * Author URI: #
 * Text Domain: ispag-project-dashboard
 * Domain Path: /languages
 */

defined('ABSPATH') or die();

// Définir la constante de chemin pour la clarté
if (!defined('ISPAG_PD_PATH')) {
    define('ISPAG_PD_PATH', plugin_dir_path(__FILE__));
}
// Définir la constante d'URL
if (!defined('ISPAG_PD_URL')) {
    define('ISPAG_PD_URL', plugin_dir_url(__FILE__));
}

/**
 * Chargement des traductions du plugin.
 */
function ispag_pd_load_textdomain() {
    load_plugin_textdomain(
        'ispag-project-dashboard', 
        false, 
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );
}
add_action('plugins_loaded', 'ispag_pd_load_textdomain');

// Mise à jour depuis GitHub (Outils → Updates ISPAG), comme les autres plugins ISPAG
require_once ISPAG_PD_PATH . 'classes/class-ispag-github-updater.php';
ISPAG_GitHub_Updater::plugin(__FILE__, 'mougeat/ispag-project-dashboard');

require_once ISPAG_PD_PATH . 'classes/class-ispag-supplier-repository.php';
require_once ISPAG_PD_PATH . 'classes/class-ispag-supplier-dashboard.php';
require_once ISPAG_PD_PATH . 'classes/class-ispag-project-repository.php';
require_once ISPAG_PD_PATH . 'classes/class-ispag-project-dashboard.php';
require_once ISPAG_PD_PATH . 'classes/class-ispag-monthly-report.php';

// Initialisation de la classe
ISPAG_Supplier_Dashboard::run();
ISPAG_Project_Dashboard::run();
ISPAG_Monthly_Report::run();