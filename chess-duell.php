<?php
/**
 * Plugin Name:       Chess Duell
 * Plugin URI:        https://willnat.org/
 * Description:        Zwei Menschen spielen online Schach gegeneinander – Partie einfach per Link teilen. Anzahl gleichzeitiger Partien und Laufzeit im Backend einstellbar. Serverseitige Regelprüfung (kein Cheaten möglich), keine KI. Einbinden mit dem Shortcode [chess_duell].
 * Version:           1.2.0
 * Author:            Florian Willnat
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chess-duell
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CHESS_DUELL_VERSION', '1.2.0');
define('CHESS_DUELL_URL', plugin_dir_url(__FILE__));
define('CHESS_DUELL_PATH', plugin_dir_path(__FILE__));
define('CHESS_DUELL_OPTION', 'chess_duell_games');
define('CHESS_DUELL_SETTINGS', 'chess_duell_settings');

define('CHESS_DUELL_DEFAULT_MAX_GAMES', 10); // Standard: max. gleichzeitige Partien
define('CHESS_DUELL_DEFAULT_TTL_DAYS', 14);  // Standard: Tage bis eine inaktive Partie verfällt

define('CHESS_DUELL_START_FEN', 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1');

require_once CHESS_DUELL_PATH . 'includes/class-chess-engine.php';

/* ------------------------------------------------------------------ *
 *  Einstellungen (im Backend konfigurierbar)
 * ------------------------------------------------------------------ */

function chess_duell_get_settings() {
    $defaults = array(
        'max_games' => CHESS_DUELL_DEFAULT_MAX_GAMES,
        'ttl_days'  => CHESS_DUELL_DEFAULT_TTL_DAYS,
    );
    $s = get_option(CHESS_DUELL_SETTINGS, array());
    if (!is_array($s)) {
        $s = array();
    }
    return wp_parse_args($s, $defaults);
}

/** Maximal erlaubte gleichzeitige (laufende) Partien. */
function chess_duell_max_games() {
    $s = chess_duell_get_settings();
    return max(1, intval($s['max_games']));
}

/** Inaktivitäts-Lebensdauer einer Partie in Sekunden. */
function chess_duell_ttl() {
    $s = chess_duell_get_settings();
    return max(1, intval($s['ttl_days'])) * DAY_IN_SECONDS;
}

/* ------------------------------------------------------------------ *
 *  Speicher-Helfer
 * ------------------------------------------------------------------ */

function chess_duell_load_games() {
    $g = get_option(CHESS_DUELL_OPTION, array());
    return is_array($g) ? $g : array();
}

function chess_duell_save_games($games) {
    update_option(CHESS_DUELL_OPTION, $games, false);
}

function chess_duell_prune($games) {
    $now = time();
    $ttl = chess_duell_ttl();
    foreach ($games as $id => $game) {
        // Eine Partie wird nur durch Matt/Aufgabe/Remis beendet oder nach
        // Ablauf der eingestellten Inaktivitätsdauer (laufend wie beendet) entfernt.
        if ($now - intval($game['updated']) > $ttl) {
            unset($games[$id]);
        }
    }
    return $games;
}

/** Öffentliche Sicht eines Spiels (ohne geheime Tokens). */
function chess_duell_public_state($game) {
    return array(
        'id'         => $game['id'],
        'fen'        => $game['fen'],
        'turn'       => $game['turn'],
        'moves'      => $game['moves'],
        'status'     => $game['status'],
        'result'     => $game['result'],
        'has_black'  => !empty($game['black_token']),
        'white_name' => isset($game['white_name']) ? $game['white_name'] : '',
        'black_name' => isset($game['black_name']) ? $game['black_name'] : '',
        'updated'    => intval($game['updated']),
    );
}

/* ------------------------------------------------------------------ *
 *  Eingabe-Validierung
 * ------------------------------------------------------------------ */

function chess_duell_valid_square($s) {
    return is_string($s) && preg_match('/^[a-h][1-8]$/', $s) === 1;
}

/** Spielernamen bereinigen und auf 24 Zeichen begrenzen. */
function chess_duell_sanitize_name($name) {
    $name = sanitize_text_field((string) $name);
    $name = trim($name);
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 24);
    } else {
        $name = substr($name, 0, 24);
    }
    return $name;
}

/* ------------------------------------------------------------------ *
 *  REST-API
 * ------------------------------------------------------------------ */

add_action('rest_api_init', function () {
    $ns = 'chess-duell/v1';

    register_rest_route($ns, '/game', array(
        'methods'             => 'POST',
        'callback'            => 'chess_duell_rest_create',
        'permission_callback' => '__return_true',
    ));

    register_rest_route($ns, '/game/(?P<id>[a-f0-9]{6,32})', array(
        'methods'             => 'GET',
        'callback'            => 'chess_duell_rest_get',
        'permission_callback' => '__return_true',
    ));

    register_rest_route($ns, '/game/(?P<id>[a-f0-9]{6,32})/join', array(
        'methods'             => 'POST',
        'callback'            => 'chess_duell_rest_join',
        'permission_callback' => '__return_true',
    ));

    register_rest_route($ns, '/game/(?P<id>[a-f0-9]{6,32})/move', array(
        'methods'             => 'POST',
        'callback'            => 'chess_duell_rest_move',
        'permission_callback' => '__return_true',
    ));

    register_rest_route($ns, '/game/(?P<id>[a-f0-9]{6,32})/resign', array(
        'methods'             => 'POST',
        'callback'            => 'chess_duell_rest_resign',
        'permission_callback' => '__return_true',
    ));
});

function chess_duell_rest_create($req) {
    $games = chess_duell_prune(chess_duell_load_games());

    $max = chess_duell_max_games();
    $active = 0;
    foreach ($games as $g) {
        if ($g['status'] !== 'finished') {
            $active++;
        }
    }
    if ($active >= $max) {
        return new WP_Error(
            'chess_duell_full',
            'Es laufen bereits ' . $max . ' Partien gleichzeitig. Bitte später erneut versuchen.',
            array('status' => 503)
        );
    }

    try {
        $id     = substr(bin2hex(random_bytes(8)), 0, 12);
        $wtoken = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        return new WP_Error('chess_duell_rng', 'Zufallsgenerator nicht verfügbar.', array('status' => 500));
    }

    $body = $req->get_json_params();
    $name = chess_duell_sanitize_name(isset($body['name']) ? $body['name'] : '');

    $now  = time();
    $game = array(
        'id'          => $id,
        'fen'         => CHESS_DUELL_START_FEN,
        'turn'        => 'w',
        'moves'       => array(),
        'white_token' => $wtoken,
        'black_token' => null,
        'white_name'  => $name,
        'black_name'  => '',
        'status'      => 'waiting',
        'result'      => null,
        'created'     => $now,
        'updated'     => $now,
    );

    $games[$id] = $game;
    chess_duell_save_games($games);

    return array(
        'id'    => $id,
        'token' => $wtoken,
        'color' => 'white',
        'state' => chess_duell_public_state($game),
    );
}

function chess_duell_rest_get($req) {
    $games = chess_duell_prune(chess_duell_load_games());
    $id    = $req['id'];
    if (!isset($games[$id])) {
        return new WP_Error('chess_duell_not_found', 'Partie nicht gefunden oder abgelaufen.', array('status' => 404));
    }
    // Pruning kann etwas entfernt haben -> speichern.
    chess_duell_save_games($games);
    return chess_duell_public_state($games[$id]);
}

function chess_duell_rest_join($req) {
    $games = chess_duell_prune(chess_duell_load_games());
    $id    = $req['id'];
    if (!isset($games[$id])) {
        return new WP_Error('chess_duell_not_found', 'Partie nicht gefunden oder abgelaufen.', array('status' => 404));
    }

    $game     = $games[$id];
    $body     = $req->get_json_params();
    $token    = isset($body['token']) ? (string) $body['token'] : '';
    $hasName  = isset($body['name']);
    $name     = $hasName ? chess_duell_sanitize_name($body['name']) : '';

    // Bekanntes Token (Weiß)? -> bestehende Farbe, ggf. Namen aktualisieren.
    if ($token !== '' && hash_equals($game['white_token'], $token)) {
        if ($hasName && $name !== '' && $name !== (isset($game['white_name']) ? $game['white_name'] : '')) {
            $game['white_name'] = $name;
            $games[$id] = $game;
            chess_duell_save_games($games);
        }
        return array('color' => 'white', 'token' => $token, 'state' => chess_duell_public_state($game));
    }
    // Bekanntes Token (Schwarz)?
    if ($token !== '' && !empty($game['black_token']) && hash_equals($game['black_token'], $token)) {
        if ($hasName && $name !== '' && $name !== (isset($game['black_name']) ? $game['black_name'] : '')) {
            $game['black_name'] = $name;
            $games[$id] = $game;
            chess_duell_save_games($games);
        }
        return array('color' => 'black', 'token' => $token, 'state' => chess_duell_public_state($game));
    }

    // Noch kein Schwarzer? -> als Schwarz beitreten.
    if (empty($game['black_token'])) {
        try {
            $btoken = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            return new WP_Error('chess_duell_rng', 'Zufallsgenerator nicht verfügbar.', array('status' => 500));
        }
        $game['black_token'] = $btoken;
        $game['black_name']  = $name;
        $game['status']      = ($game['status'] === 'waiting') ? 'active' : $game['status'];
        $game['updated']     = time();
        $games[$id]          = $game;
        chess_duell_save_games($games);
        return array('color' => 'black', 'token' => $btoken, 'state' => chess_duell_public_state($game));
    }

    // Partie voll -> Zuschauer.
    return array('color' => 'spectator', 'token' => null, 'state' => chess_duell_public_state($game));
}

function chess_duell_rest_move($req) {
    $games = chess_duell_prune(chess_duell_load_games());
    $id    = $req['id'];
    if (!isset($games[$id])) {
        return new WP_Error('chess_duell_not_found', 'Partie nicht gefunden oder abgelaufen.', array('status' => 404));
    }

    $game = $games[$id];
    if ($game['status'] === 'finished') {
        return new WP_Error('chess_duell_finished', 'Die Partie ist bereits beendet.', array('status' => 409));
    }

    $body  = $req->get_json_params();
    $token = isset($body['token']) ? (string) $body['token'] : '';

    // Welche Farbe gehört zum Token?
    $color = null;
    if ($token !== '' && hash_equals($game['white_token'], $token)) {
        $color = 'w';
    } elseif ($token !== '' && !empty($game['black_token']) && hash_equals($game['black_token'], $token)) {
        $color = 'b';
    }
    if ($color === null) {
        return new WP_Error('chess_duell_forbidden', 'Kein gültiges Spieler-Token.', array('status' => 403));
    }

    if (empty($game['black_token'])) {
        return new WP_Error('chess_duell_waiting', 'Es ist noch kein Gegner verbunden.', array('status' => 409));
    }

    // Ist die Seite am Zug?
    if ($color !== $game['turn']) {
        return new WP_Error('chess_duell_not_your_turn', 'Du bist nicht am Zug.', array('status' => 409));
    }

    $from = isset($body['from']) ? (string) $body['from'] : '';
    $to   = isset($body['to']) ? (string) $body['to'] : '';
    if (!chess_duell_valid_square($from) || !chess_duell_valid_square($to)) {
        return new WP_Error('chess_duell_bad_move', 'Ungültige Felder.', array('status' => 400));
    }

    $promotion = isset($body['promotion']) ? strtolower((string) $body['promotion']) : null;
    if ($promotion !== null && !in_array($promotion, array('q', 'r', 'b', 'n'), true)) {
        $promotion = null;
    }

    // --- Serverseitige Regelprüfung ---------------------------------
    // Der Server ist die einzige Wahrheit: Er lädt die gespeicherte
    // Stellung, prüft die Legalität des Zuges selbst und berechnet
    // FEN/SAN/Spielende neu. Vom Client gesendete fen/san/result werden
    // bewusst ignoriert -> Cheaten ist nicht möglich.
    $engine = new Chess_Duell_Engine($game['fen']);

    // Sicherheitsnetz: Stellung am Zug muss zur Spieldatensatz-Farbe passen.
    if ($engine->turn !== $game['turn']) {
        return new WP_Error('chess_duell_state', 'Stellung inkonsistent.', array('status' => 409));
    }

    $applied = $engine->move($from, $to, $promotion);
    if ($applied === null) {
        return new WP_Error('chess_duell_illegal', 'Ungültiger Zug.', array('status' => 422));
    }

    $game['moves'][] = array(
        'from'      => $from,
        'to'        => $to,
        'promotion' => $applied['promotion'],
        'san'       => $applied['san'],
    );
    $game['fen']     = $engine->fen();
    $game['turn']    = $engine->turn;
    $game['updated'] = time();

    $status = $engine->gameStatus();
    if (!empty($status['over'])) {
        $game['status'] = 'finished';
        if ($status['type'] === 'checkmate') {
            $game['result'] = ($status['winner'] === 'w') ? '1-0' : '0-1';
        } else {
            $game['result'] = '1/2-1/2';
        }
    }

    $games[$id] = $game;
    chess_duell_save_games($games);

    return chess_duell_public_state($game);
}

function chess_duell_rest_resign($req) {
    $games = chess_duell_prune(chess_duell_load_games());
    $id    = $req['id'];
    if (!isset($games[$id])) {
        return new WP_Error('chess_duell_not_found', 'Partie nicht gefunden oder abgelaufen.', array('status' => 404));
    }

    $game  = $games[$id];
    $body  = $req->get_json_params();
    $token = isset($body['token']) ? (string) $body['token'] : '';

    $color = null;
    if ($token !== '' && hash_equals($game['white_token'], $token)) {
        $color = 'w';
    } elseif ($token !== '' && !empty($game['black_token']) && hash_equals($game['black_token'], $token)) {
        $color = 'b';
    }
    if ($color === null) {
        return new WP_Error('chess_duell_forbidden', 'Kein gültiges Spieler-Token.', array('status' => 403));
    }

    if ($game['status'] !== 'finished') {
        $game['status']  = 'finished';
        $game['result']  = ($color === 'w') ? '0-1' : '1-0';
        $game['updated'] = time();
        $games[$id]      = $game;
        chess_duell_save_games($games);
    }

    return chess_duell_public_state($game);
}

/* ------------------------------------------------------------------ *
 *  Backend-Einstellungen
 * ------------------------------------------------------------------ */

add_action('admin_menu', function () {
    add_options_page(
        'Chess Duell',
        'Chess Duell',
        'manage_options',
        'chess-duell',
        'chess_duell_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('chess_duell_group', CHESS_DUELL_SETTINGS, array(
        'type'              => 'array',
        'sanitize_callback' => 'chess_duell_sanitize_settings',
        'default'           => array(
            'max_games' => CHESS_DUELL_DEFAULT_MAX_GAMES,
            'ttl_days'  => CHESS_DUELL_DEFAULT_TTL_DAYS,
        ),
    ));
});

function chess_duell_sanitize_settings($input) {
    $out = array();
    $out['max_games'] = isset($input['max_games']) ? intval($input['max_games']) : CHESS_DUELL_DEFAULT_MAX_GAMES;
    $out['ttl_days']  = isset($input['ttl_days']) ? intval($input['ttl_days']) : CHESS_DUELL_DEFAULT_TTL_DAYS;
    if ($out['max_games'] < 1)   { $out['max_games'] = 1; }
    if ($out['max_games'] > 200) { $out['max_games'] = 200; }
    if ($out['ttl_days'] < 1)    { $out['ttl_days'] = 1; }
    if ($out['ttl_days'] > 365)  { $out['ttl_days'] = 365; }
    return $out;
}

function chess_duell_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $s = chess_duell_get_settings();
    ?>
    <div class="wrap">
        <h1>Chess Duell – Einstellungen</h1>
        <form method="post" action="options.php">
            <?php settings_fields('chess_duell_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="cd-max">Maximale gleichzeitige Partien</label></th>
                    <td>
                        <input name="<?php echo esc_attr(CHESS_DUELL_SETTINGS); ?>[max_games]" id="cd-max"
                               type="number" min="1" max="200" step="1"
                               value="<?php echo esc_attr($s['max_games']); ?>" class="small-text">
                        <p class="description">Wie viele laufende Partien gleichzeitig erlaubt sind (1–200). Beendete Partien zählen nicht mit.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cd-ttl">Laufzeit bis Auto-Ende (Tage)</label></th>
                    <td>
                        <input name="<?php echo esc_attr(CHESS_DUELL_SETTINGS); ?>[ttl_days]" id="cd-ttl"
                               type="number" min="1" max="365" step="1"
                               value="<?php echo esc_attr($s['ttl_days']); ?>" class="small-text">
                        <p class="description">Nach so vielen Tagen ohne Aktivität wird eine Partie automatisch entfernt (1–365). Ansonsten endet eine Partie nur durch Matt, Aufgabe oder Remis.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <p><strong>Shortcode:</strong> <code>[chess_duell]</code></p>
    </div>
    <?php
}

/* ------------------------------------------------------------------ *
 *  Assets + Shortcode
 * ------------------------------------------------------------------ */

add_action('wp_enqueue_scripts', function () {
    wp_register_style('chess-duell', CHESS_DUELL_URL . 'assets/css/chess-duell.css', array(), CHESS_DUELL_VERSION);
    wp_register_script('chess-duell-engine', CHESS_DUELL_URL . 'assets/js/engine.js', array(), CHESS_DUELL_VERSION, true);
    wp_register_script('chess-duell-app', CHESS_DUELL_URL . 'assets/js/app.js', array('chess-duell-engine'), CHESS_DUELL_VERSION, true);
});

function chess_duell_shortcode($atts) {
    wp_enqueue_style('chess-duell');
    wp_enqueue_script('chess-duell-engine');
    wp_enqueue_script('chess-duell-app');

    $game_id = isset($_GET['chess_game']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['chess_game']) : '';

    wp_localize_script('chess-duell-app', 'ChessDuellConfig', array(
        'restUrl' => esc_url_raw(rest_url('chess-duell/v1/')),
        'nonce'   => wp_create_nonce('wp_rest'),
        'gameId'  => $game_id ? $game_id : null,
    ));

    return '<div class="chess-duell-root"></div>';
}
add_shortcode('chess_duell', 'chess_duell_shortcode');

/* Aufräumen bei Deinstallation. */
register_uninstall_hook(__FILE__, 'chess_duell_uninstall');
function chess_duell_uninstall() {
    delete_option(CHESS_DUELL_OPTION);
    delete_option(CHESS_DUELL_SETTINGS);
}
