<?php
defined('ABSPATH') || exit;

if (!class_exists('ISPAG_GitHub_Updater', false)) {

/**
 * Mise à jour automatique d'un plugin ou d'un thème ISPAG depuis une branche GitHub.
 *
 * INACTIF tant qu'aucun jeton GitHub n'est configuré. Deux façons de le configurer :
 *
 *  1. wp-config.php (site de test) :
 *       define('ISPAG_GITHUB_TOKEN', 'github_pat_xxx');            // jeton en lecture seule (Contents: Read) sur les dépôts
 *       define('ISPAG_UPDATE_BRANCH', 'claude/eager-galileo-tcm8l8'); // branche suivie
 *       define('ISPAG_GITHUB_AUTO_UPDATE', false);                  // (optionnel) true par défaut : installe sans confirmation
 *
 *  2. Écran « Outils → Updates ISPAG » (site de production, sans toucher à wp-config.php) :
 *       jeton + branche (« main » par défaut) enregistrés en base. Les constantes, si elles existent, restent prioritaires.
 *       Dans ce mode, rien n'est installé d'office : la page Extensions propose, pour chaque plugin et le thème,
 *       le réglage natif « Activer les mises à jour automatiques ».
 *
 * Fonctionnement :
 *  - une mise à jour est proposée quand le SHA du dernier commit de la branche diffère du SHA installé
 *    (mémorisé en option après chaque installation) ;
 *  - le ZIP est téléchargé via l'API GitHub, épinglé sur ce SHA, avec le jeton ;
 *  - le dossier du ZIP (« mougeat-repo-<sha> ») est renommé au bon nom de plugin/thème ;
 *  - le fichier .env (absent de Git) est sauvegardé puis restauré, car WordPress vide le dossier lors d'une mise à jour ;
 *  - un cron toutes les 15 minutes détecte les nouveaux commits et, si l'auto-update est actif, les installe.
 */
class ISPAG_GitHub_Updater {

    const CACHE_TTL      = 300;   // secondes : durée de mémorisation du SHA distant
    const POLL_INTERVAL  = 900;   // secondes : fréquence du cron de détection
    const CRON_HOOK      = 'ispag_github_updater_poll';
    const PRESERVE       = ['.env'];

    /** @var self[] toutes les instances (un plugin/thème chacune), pour le cron partagé */
    private static $instances = [];
    private static $cron_ready = false;

    private $type;      // 'plugin' | 'theme'
    private $slug;      // nom du dossier
    private $basename;  // plugin : 'dossier/fichier.php' — thème : 'dossier'
    private $repo;      // 'mougeat/ispag-achats'
    private $pending_sha = ''; // SHA du ZIP en cours de téléchargement

    /** Base de l'API GitHub (modifiable par ISPAG_GITHUB_API_BASE : tests ou GitHub Enterprise). */
    public static function api_base() {
        return defined('ISPAG_GITHUB_API_BASE') && ISPAG_GITHUB_API_BASE ? rtrim(ISPAG_GITHUB_API_BASE, '/') : 'https://api.github.com';
    }

    const OPT_TOKEN  = 'ispag_gh_token';
    const OPT_BRANCH = 'ispag_gh_branch';
    const PAGE       = 'ispag-updates';

    /** Jeton : constante wp-config.php en priorité, sinon réglage enregistré en base. */
    public static function token() {
        if (defined('ISPAG_GITHUB_TOKEN') && ISPAG_GITHUB_TOKEN !== '') return (string) ISPAG_GITHUB_TOKEN;
        return (string) get_option(self::OPT_TOKEN, '');
    }

    /** Branche suivie : constante, sinon réglage, sinon « main ». */
    public static function branch_name() {
        if (defined('ISPAG_UPDATE_BRANCH') && ISPAG_UPDATE_BRANCH !== '') return (string) ISPAG_UPDATE_BRANCH;
        $b = trim((string) get_option(self::OPT_BRANCH, ''));
        return $b !== '' ? $b : 'main';
    }

    /** true si le jeton ET la branche viennent de wp-config.php (site de test : installation automatique d'office). */
    public static function uses_constants() {
        return defined('ISPAG_GITHUB_TOKEN') && ISPAG_GITHUB_TOKEN !== '' && defined('ISPAG_UPDATE_BRANCH') && ISPAG_UPDATE_BRANCH !== '';
    }

    public static function is_configured() {
        return self::token() !== '';
    }

    public static function plugin_action_links($links) {
        array_unshift($links, '<a href="' . esc_url(admin_url('tools.php?page=' . self::PAGE)) . '">Updates</a>');
        return $links;
    }

    /** @param string $main_file __FILE__ du fichier principal du plugin */
    public static function plugin($main_file, $repo) {
        $basename = plugin_basename($main_file);
        // Lien « Updates » dans la liste des extensions, même tant que le jeton n'est pas saisi
        add_filter('plugin_action_links_' . $basename, [self::class, 'plugin_action_links']);
        if (!self::is_configured()) return null;
        return new self('plugin', dirname($basename), $basename, $repo);
    }

    /** @param string $stylesheet nom du dossier du thème (get_stylesheet()) */
    public static function theme($stylesheet, $repo) {
        if (!self::is_configured()) return null;
        return new self('theme', $stylesheet, $stylesheet, $repo);
    }

    private function __construct($type, $slug, $basename, $repo) {
        $this->type = $type; $this->slug = $slug; $this->basename = $basename; $this->repo = $repo;
        self::$instances[] = $this;

        if ($type === 'plugin') {
            add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
            add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
            add_filter('auto_update_plugin', [$this, 'maybe_auto_update'], 10, 2);
        } else {
            add_filter('pre_set_site_transient_update_themes', [$this, 'inject_update']);
            add_filter('auto_update_theme', [$this, 'maybe_auto_update'], 10, 2);
        }
        add_filter('upgrader_source_selection', [$this, 'fix_source_dir'], 10, 4);
        add_filter('upgrader_pre_install', [$this, 'backup_preserved'], 10, 2);
        add_filter('upgrader_post_install', [$this, 'restore_preserved'], 10, 3);
        add_filter('http_request_args', [$this, 'authorize_download'], 10, 2);
        add_filter('upgrader_pre_download', [$this, 'note_package'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'remember_installed'], 10, 2);

        if (!self::$cron_ready) {
            self::$cron_ready = true;
            add_filter('cron_schedules', function ($s) {
                $s['ispag_15min'] = ['interval' => self::POLL_INTERVAL, 'display' => 'Toutes les 15 minutes (ISPAG)'];
                return $s;
            });
            add_action(self::CRON_HOOK, [self::class, 'poll']);
            add_action('init', function () {
                if (!wp_next_scheduled(self::CRON_HOOK)) {
                    wp_schedule_event(time() + 60, 'ispag_15min', self::CRON_HOOK);
                }
            });
        }
    }

    // ------------------------------------------------------------------ détection

    private function branch() { return self::branch_name(); }
    private function sha_option() { return 'ispag_gh_sha_' . $this->slug; }
    private function installed_sha() { return (string) get_option($this->sha_option(), ''); }
    private function cache_key() { return 'ispag_gh_head_' . md5($this->repo . '|' . $this->branch()); }

    private function api_headers($accept = 'application/vnd.github+json') {
        return [
            'Authorization'        => 'Bearer ' . self::token(),
            'Accept'               => $accept,
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent'           => 'ISPAG-Updater',
        ];
    }

    /** Interroge GitHub : dernier commit de la branche. @return array ['sha'=>string, 'code'=>int, 'error'=>string] */
    private function fetch_head() {
        $path = '/repos/' . $this->repo . '/commits/' . implode('/', array_map('rawurlencode', explode('/', $this->branch())));
        $res  = wp_remote_get(self::api_base() . $path, ['timeout' => 10, 'headers' => $this->api_headers('application/vnd.github.sha')]);
        if (is_wp_error($res)) {
            return ['sha' => '', 'code' => 0, 'error' => $res->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = trim(wp_remote_retrieve_body($res));
        if ($code === 200 && preg_match('/^[0-9a-f]{40}$/', $body)) {
            return ['sha' => $body, 'code' => 200, 'error' => ''];
        }
        $msg = '';
        $json = json_decode($body, true);
        if (is_array($json) && !empty($json['message'])) $msg = (string) $json['message'];
        return ['sha' => '', 'code' => $code, 'error' => $msg !== '' ? $msg : 'réponse inattendue'];
    }

    /** SHA du dernier commit de la branche (mémorisé 5 min ; 'force-check' de WP contourne le cache). */
    private function remote_sha($force = false) {
        if (!$force && !empty($_GET['force-check'])) $force = true;
        if (!$force) {
            $cached = get_transient($this->cache_key());
            if ($cached !== false) return $cached === 'ERR' ? '' : $cached;
        }
        $head = $this->fetch_head();
        if ($head['sha'] !== '') {
            set_transient($this->cache_key(), $head['sha'], self::CACHE_TTL);
            return $head['sha'];
        }
        error_log(sprintf('[ISPAG Updater] %s@%s : réponse GitHub inattendue (HTTP %s) %s', $this->repo, $this->branch(), $head['code'], $head['error']));
        set_transient($this->cache_key(), 'ERR', self::CACHE_TTL); // évite de marteler l'API en cas d'erreur
        return '';
    }

    /** Pour l'écran de diagnostic : vérification immédiate (sans cache) + état installé. */
    public function diagnose() {
        $head = $this->fetch_head();
        delete_transient($this->cache_key());
        if ($head['sha'] !== '') set_transient($this->cache_key(), $head['sha'], self::CACHE_TTL);
        return $head + [
            'type' => $this->type, 'slug' => $this->slug, 'repo' => $this->repo, 'branch' => $this->branch(),
            'installed' => $this->installed_sha(),
        ];
    }

    public function describe() {
        return ['type' => $this->type, 'slug' => $this->slug, 'repo' => $this->repo, 'basename' => $this->basename, 'installed' => $this->installed_sha()];
    }

    private function current_version() {
        if ($this->type === 'plugin') {
            if (!function_exists('get_plugin_data')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $d = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->basename, false, false);
            return $d['Version'] ?: '0';
        }
        return wp_get_theme($this->slug)->get('Version') ?: '0';
    }

    public function has_update($force = false) {
        $sha = $this->remote_sha($force);
        return $sha !== '' && $sha !== $this->installed_sha();
    }

    public function inject_update($transient) {
        if (!is_object($transient)) return $transient;
        $sha = $this->remote_sha();
        if ($sha === '') return $transient; // GitHub injoignable : on ne touche à rien

        $version = $this->current_version();
        $item = [
            'slug'        => $this->slug,
            'new_version' => $version . '+' . substr($sha, 0, 7),
            'url'         => 'https://github.com/' . $this->repo . '/tree/' . $this->branch(),
            'package'     => self::api_base() . '/repos/' . $this->repo . '/zipball/' . $sha,
        ];
        if ($this->type === 'plugin') {
            $item['plugin'] = $this->basename;
            $item = (object) $item;
        } else {
            $item['theme'] = $this->basename;
        }

        if ($sha !== $this->installed_sha()) {
            $transient->response[$this->basename] = $item;
            if (isset($transient->no_update[$this->basename])) unset($transient->no_update[$this->basename]);
        } else {
            if (isset($transient->response[$this->basename])) unset($transient->response[$this->basename]);
            $transient->no_update[$this->basename] = $item;
        }
        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->slug) return $result;
        $sha = $this->remote_sha();
        return (object) [
            'name'          => $this->slug,
            'slug'          => $this->slug,
            'version'       => $this->current_version() . '+' . substr($sha, 0, 7),
            'homepage'      => 'https://github.com/' . $this->repo,
            'download_link' => $sha ? self::api_base() . '/repos/' . $this->repo . '/zipball/' . $sha : '',
            'sections'      => ['description' => 'Branche suivie : ' . esc_html($this->branch()) . ' (' . esc_html($this->repo) . ').'],
        ];
    }

    public function maybe_auto_update($update, $item) {
        $id = ($this->type === 'plugin') ? ($item->plugin ?? '') : ($item->theme ?? '');
        if ($id !== $this->basename) return $update;
        // Réglage enregistré en base (production) : on laisse le choix natif de WordPress (Extensions → mises à jour automatiques)
        if (!self::uses_constants()) return $update;
        if (defined('ISPAG_GITHUB_AUTO_UPDATE') && !ISPAG_GITHUB_AUTO_UPDATE) return $update;
        return true;
    }

    // ------------------------------------------------------------------ cron

    /** Détecte un nouveau commit et déclenche le circuit normal de WordPress (qui installe si l'auto-update est actif). */
    public static function poll() {
        update_option('ispag_gh_last_poll', time(), false);
        $needs = false;
        foreach (self::$instances as $i) {
            // pas de break : chaque élément doit rafraîchir son commit distant, sinon les suivants partent sur un commit périmé
            if ($i->has_update(true)) $needs = true;
        }
        if (!$needs) return;
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        if (function_exists('wp_update_plugins')) wp_update_plugins();
        if (function_exists('wp_update_themes'))  wp_update_themes();
        if (function_exists('wp_maybe_auto_update')) wp_maybe_auto_update();
    }

    // ------------------------------------------------------------------ installation

    /** Le ZIP GitHub contient un dossier « mougeat-repo-<sha> » : on le renomme au nom attendu. */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = []) {
        $target = ($this->type === 'plugin') ? ($hook_extra['plugin'] ?? null) : ($hook_extra['theme'] ?? null);
        if ($target !== $this->basename) return $source;

        global $wp_filesystem;
        $from = untrailingslashit($source);
        $to   = untrailingslashit($remote_source) . '/' . $this->slug;
        if ($from === $to) return $source;
        if (!$wp_filesystem || !$wp_filesystem->move($from, $to, true)) {
            return new WP_Error('ispag_gh_rename', 'ISPAG Updater : impossible de renommer le dossier téléchargé en ' . $this->slug);
        }
        return trailingslashit($to);
    }

    private function install_dir() {
        return ($this->type === 'plugin') ? WP_PLUGIN_DIR . '/' . $this->slug : get_theme_root($this->slug) . '/' . $this->slug;
    }
    private function backup_dir() {
        $u = wp_upload_dir();
        return untrailingslashit($u['basedir']) . '/ispag-updater-backup/' . $this->slug;
    }
    private function is_ours($hook_extra) {
        $t = ($this->type === 'plugin') ? ($hook_extra['plugin'] ?? null) : ($hook_extra['theme'] ?? null);
        return $t === $this->basename;
    }

    /** WordPress supprime le dossier avant d'installer : on met .env à l'abri. */
    public function backup_preserved($response, $hook_extra) {
        if (!$this->is_ours((array) $hook_extra)) return $response;
        $dst = $this->backup_dir();
        foreach (self::PRESERVE as $f) {
            $src = $this->install_dir() . '/' . $f;
            if (is_readable($src)) {
                if (!is_dir($dst)) wp_mkdir_p($dst);
                copy($src, $dst . '/' . $f);
            }
        }
        return $response;
    }

    public function restore_preserved($response, $hook_extra, $result) {
        if (!$this->is_ours((array) $hook_extra) || !is_array($result) || empty($result['destination'])) return $response;
        $src = $this->backup_dir();
        foreach (self::PRESERVE as $f) {
            if (is_readable($src . '/' . $f)) {
                copy($src . '/' . $f, untrailingslashit($result['destination']) . '/' . $f);
                @unlink($src . '/' . $f);
            }
        }
        return $response;
    }

    /** Ajoute le jeton uniquement pour le téléchargement du ZIP de CE dépôt. */
    public function authorize_download($args, $url) {
        if (strpos($url, self::api_base() . '/repos/' . $this->repo . '/zipball/') === 0) {
            $args['headers'] = array_merge($args['headers'] ?? [], $this->api_headers());
        }
        return $args;
    }

    /** Relève le SHA épinglé dans l'URL du ZIP : c'est lui qui sera installé, même si la branche avance entre-temps. */
    public function note_package($reply, $package, $upgrader = null, $hook_extra = []) {
        $prefix = self::api_base() . '/repos/' . $this->repo . '/zipball/';
        if (is_string($package) && strpos($package, $prefix) === 0) {
            $sha = substr($package, strlen($prefix));
            if (preg_match('/^[0-9a-f]{40}$/', $sha)) $this->pending_sha = $sha;
        }
        return $reply;
    }

    /** Mémorise le SHA réellement installé (celui du ZIP, épinglé dans l'URL du package). */
    public function remember_installed($upgrader, $options) {
        if (($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== $this->type) return;
        // Selon le circuit (mise à jour manuelle/groupée ou installation automatique), WordPress transmet une liste
        // ('plugins' / 'themes') ou un seul élément ('plugin' / 'theme') : on accepte les deux.
        $key  = $this->type === 'plugin' ? 'plugin' : 'theme';
        $list = (array) ($options[$key . 's'] ?? []);
        if (!empty($options[$key]) && is_string($options[$key])) $list[] = $options[$key];
        if (!in_array($this->basename, $list, true)) return;

        if ($this->pending_sha !== '') {
            update_option($this->sha_option(), $this->pending_sha, false);
            $this->pending_sha = '';
        }
        delete_transient($this->cache_key());
    }
    // ------------------------------------------------------------------ diagnostic (Outils → Updates ISPAG)

    public static function admin_menu() {
        add_management_page('Updates ISPAG', 'Updates ISPAG', 'manage_options', self::PAGE, [self::class, 'render_admin']);
    }

    /** Enregistrement du jeton / de la branche (écran « Updates ISPAG »), avant tout affichage pour pouvoir rediriger. */
    public static function handle_settings_save() {
        if (empty($_POST['ispag_save_updater']) || !current_user_can('manage_options')) return;
        check_admin_referer('ispag_save_updater');

        if (!empty($_POST['ispag_gh_remove_token'])) {
            delete_option(self::OPT_TOKEN);
        } elseif (isset($_POST['ispag_gh_token']) && trim((string) wp_unslash($_POST['ispag_gh_token'])) !== '') {
            update_option(self::OPT_TOKEN, trim(sanitize_text_field(wp_unslash($_POST['ispag_gh_token']))), false);
        }
        $branch = isset($_POST['ispag_gh_branch']) ? trim(sanitize_text_field(wp_unslash($_POST['ispag_gh_branch']))) : '';
        if (preg_match('#^[A-Za-z0-9._/\-]+$#', $branch)) {
            update_option(self::OPT_BRANCH, $branch, false);
        }
        // Les mémorisations de commits distants dépendent du jeton/de la branche
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_ispag\_gh\_head\_%' OR option_name LIKE '\_transient\_timeout\_ispag\_gh\_head\_%'");
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        wp_safe_redirect(add_query_arg('saved', 1, admin_url('tools.php?page=' . self::PAGE)));
        exit;
    }

    private static function hint($code, $error) {
        if ($code === 0)   return 'Le serveur ne peut pas joindre GitHub (' . $error . '). Vérifiez que l\'hébergement autorise les connexions sortantes HTTPS.';
        if ($code === 401) return 'Jeton refusé par GitHub : il est invalide, expiré ou mal copié.';
        if ($code === 403) return 'Access denied (ou limite de requêtes atteinte) : le jeton n\'a pas le droit « Contents : lecture » sur ce dépôt.';
        if ($code === 404) return 'Dépôt ou branche introuvable : le jeton n\'a pas accès à ce dépôt, ou la branche « ' . self::branch_name() . ' » n\'existe pas.';
        return 'Réponse inattendue de GitHub.';
    }

    public static function render_admin() {
        if (!current_user_can('manage_options')) return;
        $yes = '<span style="color:#1a7f37;font-weight:600;">oui</span>';
        $no  = '<span style="color:#b42318;font-weight:600;">non</span>';
        $configured = self::is_configured();

        echo '<div class="wrap"><h1>Updates ISPAG</h1>';
        echo '<p>Mise à jour automatique des plugins et du thème ISPAG depuis une branche GitHub. Cet écran indique ce qui fonctionne et ce qui bloque.</p>';

        // --- 1. configuration
        if (!empty($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Réglages enregistrés.</p></div>';

        echo '<h2>1. Configuration</h2>';
        $by_const = self::uses_constants();
        $token    = self::token();
        $stored   = (string) get_option(self::OPT_TOKEN, '');
        if ($by_const) {
            echo '<p class="description">Le jeton et la branche sont définis dans <code>wp-config.php</code> (ISPAG_GITHUB_TOKEN, ISPAG_UPDATE_BRANCH) : ils sont prioritaires sur les réglages ci-dessous.</p>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('tools.php?page=' . self::PAGE)) . '">';
            wp_nonce_field('ispag_save_updater');
            echo '<table class="form-table" style="max-width:900px"><tbody>';
            printf('<tr><th><label for="ispag_gh_token">Jeton GitHub</label></th><td><input type="password" id="ispag_gh_token" name="ispag_gh_token" class="regular-text" autocomplete="new-password" placeholder="%s"> <p class="description">Jeton en lecture seule (<em>Contents : Read</em>) sur les dépôts ISPAG. Laissez vide pour conserver le jeton enregistré.%s</p>%s</td></tr>',
                $stored !== '' ? '•••••••• (enregistré)' : 'github_pat_…',
                defined('ISPAG_GITHUB_TOKEN') && ISPAG_GITHUB_TOKEN !== '' ? ' <strong>Le jeton de wp-config.php est utilisé.</strong>' : '',
                $stored !== '' ? '<label><input type="checkbox" name="ispag_gh_remove_token" value="1"> Supprimer le jeton enregistré</label>' : '');
            printf('<tr><th><label for="ispag_gh_branch">Branche suivie</label></th><td><input type="text" id="ispag_gh_branch" name="ispag_gh_branch" class="regular-text" value="%s"> <p class="description">« main » en production.</p></td></tr>', esc_attr(self::branch_name()));
            echo '</tbody></table><p><input type="submit" name="ispag_save_updater" class="button button-primary" value="Enregistrer"></p></form>';
        }

        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        printf('<tr><td>Jeton GitHub</td><td>%s%s</td></tr>', $token !== '' ? $yes : $no, $token !== '' ? ' — ' . esc_html(substr($token, 0, 11)) . '… (' . strlen($token) . ' caractères, ' . ($by_const || (defined('ISPAG_GITHUB_TOKEN') && ISPAG_GITHUB_TOKEN !== '') ? 'wp-config.php' : 'réglage enregistré') . ')' : '');
        printf('<tr><td>Branche suivie</td><td>%s</td></tr>', esc_html(self::branch_name()));
        if ($by_const) {
            printf('<tr><td>Installation automatique</td><td>%s</td></tr>', defined('ISPAG_GITHUB_AUTO_UPDATE') && !ISPAG_GITHUB_AUTO_UPDATE ? 'désactivée (ISPAG_GITHUB_AUTO_UPDATE = false) : les mises à jour sont proposées mais à valider à la main' : 'activée');
        } else {
            echo '<tr><td>Installation automatique</td><td>au choix, plugin par plugin : <a href="' . esc_url(admin_url('plugins.php')) . '">Extensions</a> → « Activer les mises à jour automatiques »</td></tr>';
        }
        $cron_off = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        printf('<tr><td>Tâches planifiées WordPress (WP-Cron) actives</td><td>%s%s</td></tr>', $cron_off ? $no : $yes, $cron_off ? ' — DISABLE_WP_CRON est à true : la vérification automatique ne se déclenche pas, seul le bouton ci-dessous fonctionne' : '');
        $updater_off = defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED;
        printf('<tr><td>Updates automatiques de WordPress autorisées</td><td>%s%s</td></tr>', $updater_off ? $no : $yes, $updater_off ? ' — AUTOMATIC_UPDATER_DISABLED est à true : rien ne s\'installera automatiquement' : '');
        $next = wp_next_scheduled(self::CRON_HOOK); $last = (int) get_option('ispag_gh_last_poll', 0);
        printf('<tr><td>Prochaine vérification planifiée</td><td>%s</td></tr>', $next ? esc_html(wp_date('d.m.Y H:i:s', $next)) . ' (toutes les 15 min, si le site est visité)' : ($configured ? 'pas encore planifiée (elle le sera à la prochaine visite du site)' : '—'));
        printf('<tr><td>Dernière vérification automatique</td><td>%s</td></tr>', $last ? esc_html(wp_date('d.m.Y H:i:s', $last)) : 'jamais');
        echo '</tbody></table>';

        if (!$configured) {
            echo '<div class="notice notice-warning inline" style="max-width:900px"><p><strong>L\'updater est inactif</strong> : saisissez un jeton GitHub ci-dessus (ou définissez <code>ISPAG_GITHUB_TOKEN</code> dans wp-config.php).</p></div></div>';
            return;
        }

        // --- 2. vérification
        $results = null;
        if (!empty($_POST['ispag_check_now']) && check_admin_referer('ispag_check_now')) {
            $results = [];
            foreach (self::$instances as $i) $results[] = $i->diagnose();
            // Rafraîchit aussi les tableaux de mises à jour de WordPress (c'est ce qui fait apparaître l'option d'auto-update)
            delete_site_transient('update_plugins'); delete_site_transient('update_themes');
            if (function_exists('wp_update_plugins')) wp_update_plugins();
            if (function_exists('wp_update_themes'))  wp_update_themes();
        }

        echo '<h2>2. Vérification GitHub</h2>';
        echo '<form method="post">'; wp_nonce_field('ispag_check_now');
        echo '<p><input type="submit" name="ispag_check_now" class="button button-primary" value="Vérifier maintenant"> <span class="description">Interroge GitHub pour chaque plugin et le thème, et rafraîchit la page Updates de WordPress.</span></p></form>';

        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Élément</th><th>Dépôt</th><th>Commit sur GitHub</th><th>Commit installé</th><th>État</th></tr></thead><tbody>';
        $rows = $results !== null ? $results : array_map(function ($i) { return $i->describe() + ['sha' => null]; }, self::$instances);
        foreach ($rows as $r) {
            $inst = $r['installed'] !== '' ? substr($r['installed'], 0, 7) : '—';
            if ($r['sha'] === null)      { $state = 'Cliquez sur « Vérifier maintenant »'; $remote = '—'; }
            elseif ($r['sha'] === '')    { $state = '<strong style="color:#b42318">Error ' . (int) $r['code'] . '</strong> — ' . esc_html(self::hint($r['code'], $r['error'])) . '<br><small>GitHub : ' . esc_html($r['error']) . '</small>'; $remote = '—'; }
            elseif ($r['sha'] === $r['installed']) { $state = '<span style="color:#1a7f37;font-weight:600">À jour</span>'; $remote = substr($r['sha'], 0, 7); }
            else { $state = '<span style="color:#b26200;font-weight:600">Mise à jour disponible</span>' . ($r['installed'] === '' ? ' <small>(premier passage : le commit installé n\'est pas encore connu)</small>' : ''); $remote = substr($r['sha'], 0, 7); }
            printf('<tr><td>%s <code>%s</code></td><td>%s</td><td><code>%s</code></td><td><code>%s</code></td><td>%s</td></tr>',
                $r['type'] === 'theme' ? 'Thème' : 'Plugin', esc_html($r['slug']), esc_html($r['repo']), esc_html($remote), esc_html($inst), $state);
        }
        echo '</tbody></table>';
        if ($results !== null) {
            echo '<p style="margin-top:14px">Next step : <a href="' . esc_url(admin_url('update-core.php')) . '">Tableau de bord → Updates</a> pour installer, ou <a href="' . esc_url(admin_url('plugins.php')) . '">la page Extensions</a> pour voir « Updates automatiques activées ».</p>';
        }
        echo '</div>';
    }

}


add_action('admin_menu', ['ISPAG_GitHub_Updater', 'admin_menu']);
add_action('admin_init', ['ISPAG_GitHub_Updater', 'handle_settings_save']);

}
