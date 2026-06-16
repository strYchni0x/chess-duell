<?php
/**
 * Chess Duell – GitHub-Update-Mechanismus.
 *
 * Meldet Updates im WordPress-Dashboard, wenn im GitHub-Repository ein neueres
 * Release (Tag) veröffentlicht wurde, und installiert es über den normalen
 * WordPress-Updater.
 *
 * ──────────────────────────────────────────────────────────────────────────
 *  WICHTIG – Umstieg auf wordpress.org:
 *  Wird das Plugin künftig über das offizielle WordPress-Plugin-Verzeichnis
 *  verteilt, MUSS dieser Updater entfernt werden (sonst Konflikt mit dem
 *  .org-Update-System). Dazu genügt:
 *    1. diese Datei (includes/class-github-updater.php) löschen, und
 *    2. den require/new-Block in chess-duell.php (Abschnitt "GitHub-Updater")
 *       entfernen.
 *  Der Rest des Plugins funktioniert unverändert weiter.
 * ──────────────────────────────────────────────────────────────────────────
 *
 * Veröffentlichungs-Workflow für ein Update:
 *  1. Versionsnummer im Header von chess-duell.php (und readme.txt) erhöhen.
 *  2. Commit + Push.
 *  3. Auf GitHub ein Release mit Tag = Version anlegen (z. B. "v1.5.1").
 *     Der Tag-Name darf ein führendes "v" haben; er wird ignoriert.
 *  Das Dashboard zeigt das Update dann innerhalb weniger Stunden (Cache: 6 h)
 *  bzw. sofort nach "Aktualisierungen → erneut prüfen".
 */

if (!defined('ABSPATH')) {
    exit;
}

class Chess_Duell_GitHub_Updater {

    private $file;      // absoluter Pfad zur Plugin-Hauptdatei
    private $basename;  // z. B. "chess-duell/chess-duell.php"
    private $slug;      // z. B. "chess-duell"
    private $repo;      // "owner/repo"
    private $token;     // optionales GitHub-Token (für privates Repo / Rate-Limit)
    private $cache_key;
    private $cache_ttl = 21600; // 6 Stunden

    public function __construct($file, $repo, $token = '') {
        $this->file     = $file;
        $this->repo     = $repo;
        $this->token    = $token;
        $this->basename = plugin_basename($file);
        $this->slug     = dirname($this->basename);
        if ($this->slug === '.' || $this->slug === '') {
            $this->slug = basename($file, '.php');
        }
        $this->cache_key = 'chess_duell_gh_' . md5($repo);

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_source_selection', array($this, 'fix_source_dir'), 10, 4);
        add_action('upgrader_process_complete', array($this, 'clear_cache'), 10, 2);
    }

    private function current_version() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data($this->file, false, false);
        return isset($data['Version']) ? $data['Version'] : '0';
    }

    /** Neuestes Release von GitHub holen (gecacht). Gibt array oder null. */
    private function get_release() {
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return $cached ? $cached : null; // '' = "nichts gefunden" (kurz gecacht)
        }

        $url  = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
        $args = array(
            'timeout' => 15,
            'headers' => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'ChessDuell-Updater',
            ),
        );
        if ($this->token) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->token;
        }

        $res = wp_remote_get($url, $args);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            set_transient($this->cache_key, '', 15 * MINUTE_IN_SECONDS);
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($body) || empty($body['tag_name'])) {
            set_transient($this->cache_key, '', 15 * MINUTE_IN_SECONDS);
            return null;
        }

        $tag     = (string) $body['tag_name'];
        $release = array(
            'version'   => ltrim($tag, 'vV'),
            'tag'       => $tag,
            'changelog' => isset($body['body']) ? (string) $body['body'] : '',
            'html_url'  => isset($body['html_url']) ? $body['html_url'] : ('https://github.com/' . $this->repo),
            'published' => isset($body['published_at']) ? $body['published_at'] : '',
            // Quell-ZIP des Tags (öffentlich, ohne API). Der Ordner darin wird
            // in fix_source_dir() auf den Plugin-Slug umbenannt.
            'package'   => 'https://github.com/' . $this->repo . '/archive/refs/tags/' . rawurlencode($tag) . '.zip',
        );
        set_transient($this->cache_key, $release, $this->cache_ttl);
        return $release;
    }

    /** Update in den Plugin-Update-Transient eintragen, wenn neuer. */
    public function check_update($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }
        $release = $this->get_release();
        if (!$release) {
            return $transient;
        }
        $current = $this->current_version();
        $item = array(
            'id'          => 'github.com/' . $this->repo,
            'slug'        => $this->slug,
            'plugin'      => $this->basename,
            'new_version' => $release['version'],
            'url'         => $release['html_url'],
            'package'     => $release['package'],
        );
        if (version_compare($release['version'], $current, '>')) {
            $transient->response[$this->basename] = (object) $item;
        } else {
            // Sauber als "kein Update" listen (für die Update-Übersicht).
            $item['new_version'] = $current;
            $item['package']     = '';
            $transient->no_update[$this->basename] = (object) $item;
        }
        return $transient;
    }

    /** Detail-Popup ("Details anzeigen") füllen. */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }
        $release = $this->get_release();
        if (!$release) {
            return $result;
        }
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data($this->file, false, false);

        $changelog = $release['changelog'] !== ''
            ? '<pre style="white-space:pre-wrap;">' . esc_html($release['changelog']) . '</pre>'
            : 'Details siehe GitHub-Release.';

        return (object) array(
            'name'          => $data['Name'],
            'slug'          => $this->slug,
            'version'       => $release['version'],
            'author'        => $data['Author'],
            'homepage'      => $data['PluginURI'],
            'download_link' => $release['package'],
            'sections'      => array(
                'description' => $data['Description'],
                'changelog'   => $changelog,
            ),
        );
    }

    /**
     * Der GitHub-ZIP-Ordner heißt "repo-<version>". Vor der Installation auf
     * den Plugin-Slug umbenennen, damit das Plugin am gleichen Ort landet
     * (sonst entstünde ein neues Verzeichnis).
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = array()) {
        global $wp_filesystem;
        if (!$wp_filesystem) {
            return $source;
        }
        // Gehört dieses Update zu unserem Plugin? Primär über hook_extra, sonst
        // als Fallback daran erkennen, dass der entpackte Ordner unsere
        // Hauptdatei enthält (GitHub-ZIP entpackt nach "repo-<ref>").
        $is_ours = (isset($hook_extra['plugin']) && $hook_extra['plugin'] === $this->basename);
        if (!$is_ours) {
            $main_file = basename($this->basename); // z. B. "chess-duell.php"
            if (!$wp_filesystem->exists(trailingslashit($source) . $main_file)) {
                return $source;
            }
        }
        $desired = trailingslashit($remote_source) . $this->slug;
        if (untrailingslashit($source) === untrailingslashit($desired)) {
            return $source;
        }
        // Doppelten Zielordner vermeiden: vorhandenes Ziel im Arbeitsverzeichnis
        // entfernen, dann den entpackten Ordner auf den Plugin-Slug umbenennen.
        if ($wp_filesystem->exists($desired)) {
            $wp_filesystem->delete(untrailingslashit($desired), true);
        }
        if ($wp_filesystem->move(untrailingslashit($source), untrailingslashit($desired), true)) {
            return trailingslashit($desired);
        }
        return $source;
    }

    /** Nach erfolgreichem Update den Release-Cache leeren. */
    public function clear_cache($upgrader, $options) {
        if (isset($options['action'], $options['type'])
            && $options['action'] === 'update' && $options['type'] === 'plugin') {
            delete_transient($this->cache_key);
        }
    }
}
