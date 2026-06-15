<?php
/**
 * Plugin Name:       Chess Duell
 * Plugin URI:        https://willnat.org/
 * Description:        Zwei Menschen spielen online Schach gegeneinander – Partie einfach per Link teilen. Bis zu 10 gleichzeitige Partien. Vollständige Schachregeln, keine KI. Einbinden mit dem Shortcode [chess_duell].
 * Version:           1.0.0
 * Author:            Florian Willnat
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chess-duell
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CHESS_DUELL_VERSION', '1.0.0');
define('CHESS_DUELL_URL', plugin_dir_url(__FILE__));
define('CHESS_DUELL_PATH', plugin_dir_path(__FILE__));
define('CHESS_DUELL_OPTION', 'chess_duell_games');
define('CHESS_DUELL_MAX_GAMES', 10);             // maximal gleichzeitige Partien
define('CHESS_DUELL_TTL', 14 * DAY_IN_SECONDS);  // Partie verfällt erst nach 14 Tagen Inaktivität

define('CHESS_DUELL_START_FEN', 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1');

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
    foreach ($games as $id => $game) {
        // Eine Partie wird nur durch Matt/Aufgabe/Remis beendet oder nach
        // 14 Tagen ohne Aktivität (egal ob laufend oder beendet) entfernt.
        if ($now - intval($game['updated']) > CHESS_DUELL_TTL) {
            unset($games[$id]);
        }
    }
    return $games;
}

/** Öffentliche Sicht eines Spiels (ohne geheime Tokens). */
function chess_duell_public_state($game) {
    return array(
        'id'        => $game['id'],
        'fen'       => $game['fen'],
        'turn'      => $game['turn'],
        'moves'     => $game['moves'],
        'status'    => $game['status'],
        'result'    => $game['result'],
        'has_black' => !empty($game['black_token']),
        'updated'   => intval($game['updated']),
    );
}

/* ------------------------------------------------------------------ *
 *  Eingabe-Validierung
 * ------------------------------------------------------------------ */

function chess_duell_valid_square($s) {
    return is_string($s) && preg_match('/^[a-h][1-8]$/', $s) === 1;
}

function chess_duell_sanitize_fen($fen) {
    if (!is_string($fen) || strlen($fen) > 100) {
        return null;
    }
    if (preg_match('/^[a-h1-8KQRBNPkqrbnp\/wb \-]+$/', $fen) !== 1) {
        return null;
    }
    return $fen;
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

    $active = 0;
    foreach ($games as $g) {
        if ($g['status'] !== 'finished') {
            $active++;
        }
    }
    if ($active >= CHESS_DUELL_MAX_GAMES) {
        return new WP_Error(
            'chess_duell_full',
            'Es laufen bereits ' . CHESS_DUELL_MAX_GAMES . ' Partien gleichzeitig. Bitte später erneut versuchen.',
            array('status' => 503)
        );
    }

    try {
        $id     = substr(bin2hex(random_bytes(8)), 0, 12);
        $wtoken = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        return new WP_Error('chess_duell_rng', 'Zufallsgenerator nicht verfügbar.', array('status' => 500));
    }

    $now  = time();
    $game = array(
        'id'          => $id,
        'fen'         => CHESS_DUELL_START_FEN,
        'turn'        => 'w',
        'moves'       => array(),
        'white_token' => $wtoken,
        'black_token' => null,
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

    $game  = $games[$id];
    $body  = $req->get_json_params();
    $token = isset($body['token']) ? (string) $body['token'] : '';

    // Bekanntes Token? -> bestehende Farbe zurückgeben.
    if ($token !== '' && hash_equals($game['white_token'], $token)) {
        return array('color' => 'white', 'token' => $token, 'state' => chess_duell_public_state($game));
    }
    if ($token !== '' && !empty($game['black_token']) && hash_equals($game['black_token'], $token)) {
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

    $fen = chess_duell_sanitize_fen(isset($body['fen']) ? $body['fen'] : '');
    if ($fen === null) {
        return new WP_Error('chess_duell_bad_fen', 'Ungültige Stellung.', array('status' => 400));
    }

    $san = isset($body['san']) ? sanitize_text_field((string) $body['san']) : ($from . $to);
    if (strlen($san) > 10) {
        $san = substr($san, 0, 10);
    }

    $game['moves'][] = array(
        'from'      => $from,
        'to'        => $to,
        'promotion' => $promotion,
        'san'       => $san,
    );
    $game['fen']     = $fen;
    $game['turn']    = ($game['turn'] === 'w') ? 'b' : 'w';
    $game['updated'] = time();

    if (!empty($body['finished'])) {
        $result = isset($body['result']) ? (string) $body['result'] : null;
        if (!in_array($result, array('1-0', '0-1', '1/2-1/2'), true)) {
            $result = null;
        }
        $game['status'] = 'finished';
        $game['result'] = $result;
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
}
