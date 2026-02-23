<?php
/**
 * Plugin Name: ISPAG - Tableau de Bord des Projets
 * Description: Fournit un tableau de bord pour suivre les projets, les livraisons (reste à livrer) et la facturation.
 * Version: 1.0.0
 * Author: Cyril Barthel (via AI Assistant)
 * Author URI: #
 * Text Domain: ispag-project-dashboard
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

// Inclure la classe principale
require_once ISPAG_PD_PATH . 'classes/class-ispag-project-dashboard.php';
require_once ISPAG_PD_PATH . 'classes/class-ispag-project-dashboard-ajax.php';
require_once ISPAG_PD_PATH . 'classes/class-ispag-project-repository.php'; // Nouvelle classe pour les requêtes spécifiques aux projets

/**
 * Fonction d'initialisation du plugin
 */
function ispag_project_dashboard_init() {
    // Initialiser la classe principale pour les actions et filtres WordPress
    ISPAG_Project_Dashboard::run();

    // Initialiser la classe AJAX
    ISPAG_Project_Dashboard_Ajax::run();

    // Initialiser le Repository (qui fera les requêtes DB)
    ISPAG_Project_Repository::run();
}

add_action('plugins_loaded', 'ispag_project_dashboard_init');

/**
 * Activation du plugin
 */
function ispag_project_dashboard_activate() {
    // Rien de spécial ici pour l'instant, pas de tables à créer.
}
register_activation_hook(__FILE__, 'ispag_project_dashboard_activate');