<?php
/**
 * Plugin Name:       Chess Duell
 * Plugin URI:        https://github.com/strYchni0x/chess-duell
 * Description:        Two people play chess online against each other – just share a link. Number of concurrent games and lifetime configurable in the backend. Server-side rule validation (no cheating), no AI. Embed with the shortcode [chess_duell].
 * Version:           1.5.3
 * Author:            Florian Willnat
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chess-duell
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CHESS_DUELL_VERSION', '1.5.3');
define('CHESS_DUELL_URL', plugin_dir_url(__FILE__));
define('CHESS_DUELL_PATH', plugin_dir_path(__FILE__));
define('CHESS_DUELL_OPTION', 'chess_duell_games');
define('CHESS_DUELL_SETTINGS', 'chess_duell_settings');

define('CHESS_DUELL_DEFAULT_MAX_GAMES', 10); // Standard: max. gleichzeitige Partien
define('CHESS_DUELL_DEFAULT_TTL_DAYS', 14);  // Standard: Tage bis eine inaktive Partie verfällt

// Schachuhr: Es zählt nur die Online-Zeit des Spielers am Zug. Ein "Heartbeat"
// (Polling) gilt als online. Lücken größer als dieses Fenster werden gekappt,
// damit Offline-Pausen (z. B. bis zum nächsten Tag) nicht als Bedenkzeit zählen.
define('CHESS_DUELL_ONLINE_WINDOW_MS', 8000); // Spieler gilt als online, wenn Ping jünger
define('CHESS_DUELL_MAX_TICK_MS', 8000);      // max. anrechenbare Zeit pro Heartbeat

define('CHESS_DUELL_START_FEN', 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1');

require_once CHESS_DUELL_PATH . 'includes/class-chess-engine.php';

/* Übersetzungen laden (Sprachdateien in /languages). */
add_action('init', function () {
    load_plugin_textdomain('chess-duell', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/**
 * Übersetzbare Texte für das Front-End (JavaScript). Schlüssel = englischer
 * Quelltext; der Wert ist die (ggf.) übersetzte Fassung. So genügt ein einziger
 * Übersetzungskatalog (.po/.mo) für PHP und JS.
 */
function chess_duell_js_i18n() {
    return array(
        'Play chess online against each other' => __('Play chess online against each other', 'chess-duell'),
        'Start a new game and share the link with your opponent. Whoever creates the link plays White.' => __('Start a new game and share the link with your opponent. Whoever creates the link plays White.', 'chess-duell'),
        'Your name' => __('Your name', 'chess-duell'),
        'Your name (optional)' => __('Your name (optional)', 'chess-duell'),
        'Chess clock' => __('Chess clock', 'chess-duell'),
        'No clock' => __('No clock', 'chess-duell'),
        '5 minutes' => __('5 minutes', 'chess-duell'),
        '10 minutes' => __('10 minutes', 'chess-duell'),
        '15 min + 10 sec/move' => __('15 min + 10 sec/move', 'chess-duell'),
        '30 minutes' => __('30 minutes', 'chess-duell'),
        'Only the online time of the player to move is counted. If someone is offline, their clock pauses.' => __('Only the online time of the player to move is counted. If someone is offline, their clock pauses.', 'chess-duell'),
        'E-mail for move notification (optional)' => __('E-mail for move notification (optional)', 'chess-duell'),
        'Used only to notify you when it is your turn, and automatically deleted when the game ends – not stored permanently. Note: like any input, the address is technically visible in the server log.' => __('Used only to notify you when it is your turn, and automatically deleted when the game ends – not stored permanently. Note: like any input, the address is technically visible in the server log.', 'chess-duell'),
        'Start new game' => __('Start new game', 'chess-duell'),
        'Creating game …' => __('Creating game …', 'chess-duell'),
        'Error: %s' => __('Error: %s', 'chess-duell'),
        'Connecting to game …' => __('Connecting to game …', 'chess-duell'),
        'Could not load game: %s' => __('Could not load game: %s', 'chess-duell'),
        'Back to lobby' => __('Back to lobby', 'chess-duell'),
        'Move list' => __('Move list', 'chess-duell'),
        'White' => __('White', 'chess-duell'),
        'Black' => __('Black', 'chess-duell'),
        'E-mail notification' => __('E-mail notification', 'chess-duell'),
        'Notification when it is your turn. The address is deleted when the game ends, not stored permanently (visible in the server log). Empty field = off.' => __('Notification when it is your turn. The address is deleted when the game ends, not stored permanently (visible in the server log). Empty field = off.', 'chess-duell'),
        'Give up' => __('Give up', 'chess-duell'),
        'New game' => __('New game', 'chess-duell'),
        'Promote to:' => __('Promote to:', 'chess-duell'),
        'Queen' => __('Queen', 'chess-duell'),
        'Rook' => __('Rook', 'chess-duell'),
        'Bishop' => __('Bishop', 'chess-duell'),
        'Knight' => __('Knight', 'chess-duell'),
        'Both players are connected.' => __('Both players are connected.', 'chess-duell'),
        'Share this link with your opponent:' => __('Share this link with your opponent:', 'chess-duell'),
        'Copy link' => __('Copy link', 'chess-duell'),
        'Copied!' => __('Copied!', 'chess-duell'),
        'You are watching as a spectator.' => __('You are watching as a spectator.', 'chess-duell'),
        'You play White' => __('You play White', 'chess-duell'),
        'You play Black' => __('You play Black', 'chess-duell'),
        'Spectator' => __('Spectator', 'chess-duell'),
        'Draw' => __('Draw', 'chess-duell'),
        ' (stalemate)' => __(' (stalemate)', 'chess-duell'),
        ' (fifty-move rule)' => __(' (fifty-move rule)', 'chess-duell'),
        ' (insufficient material)' => __(' (insufficient material)', 'chess-duell'),
        'Victory – %s wins' => __('Victory – %s wins', 'chess-duell'),
        ' (on time)' => __(' (on time)', 'chess-duell'),
        ' (by resignation)' => __(' (by resignation)', 'chess-duell'),
        ' (checkmate)' => __(' (checkmate)', 'chess-duell'),
        'Game over' => __('Game over', 'chess-duell'),
        'Checkmate – %s wins' => __('Checkmate – %s wins', 'chess-duell'),
        'Stalemate – draw' => __('Stalemate – draw', 'chess-duell'),
        'Fifty-move rule – draw' => __('Fifty-move rule – draw', 'chess-duell'),
        'Draw (insufficient material)' => __('Draw (insufficient material)', 'chess-duell'),
        'Waiting for opponent …' => __('Waiting for opponent …', 'chess-duell'),
        'On move: %s' => __('On move: %s', 'chess-duell'),
        ' (you)' => __(' (you)', 'chess-duell'),
        ' – Check!' => __(' – Check!', 'chess-duell'),
        'Really give up the game?' => __('Really give up the game?', 'chess-duell'),
    );
}

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
    if (!is_array($g)) {
        return array();
    }
    // Abwärtskompatibilität: Datensätze älterer Versionen um neue Felder
    // ergänzen, damit laufende Partien nach einem Update nicht abbrechen.
    foreach ($g as $id => $game) {
        $g[$id] = chess_duell_normalize_game($game);
    }
    return $g;
}

/** Fehlende Felder eines Spieldatensatzes mit sicheren Defaults auffüllen. */
function chess_duell_normalize_game($game) {
    $defaults = array(
        'id'            => '',
        'fen'           => CHESS_DUELL_START_FEN,
        'turn'          => 'w',
        'moves'         => array(),
        'white_token'   => '',
        'black_token'   => null,
        'white_name'    => '',
        'black_name'    => '',
        'white_email'   => '',
        'black_email'   => '',
        'status'        => 'waiting',
        'result'        => null,
        'result_type'   => null,
        'created'       => time(),
        'updated'       => time(),
        // Schachuhr (aus = klassische Partie ohne Zeitlimit, wie bisher)
        'clock_enabled' => false,
        'clock_base'    => 0,
        'clock_inc'     => 0,
        'white_ms'      => 0,
        'black_ms'      => 0,
        'clock_last'    => null,
        'seen_w'        => null,
        'seen_b'        => null,
        // Benachrichtigung
        'page'          => '',
        'last_notify_w' => 0,
        'last_notify_b' => 0,
    );
    return array_merge($defaults, (array) $game);
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
        'id'           => $game['id'],
        'fen'          => $game['fen'],
        'turn'         => $game['turn'],
        'moves'        => $game['moves'],
        'status'       => $game['status'],
        'result'       => $game['result'],
        'result_type'  => isset($game['result_type']) ? $game['result_type'] : null,
        'has_black'    => !empty($game['black_token']),
        'white_name'   => isset($game['white_name']) ? $game['white_name'] : '',
        'black_name'   => isset($game['black_name']) ? $game['black_name'] : '',
        // Adressen werden NICHT ausgegeben – nur ob eine Benachrichtigung aktiv ist.
        'white_notify' => !empty($game['white_email']),
        'black_notify' => !empty($game['black_email']),
        'clock'        => chess_duell_clock_public($game),
        'updated'      => intval($game['updated']),
    );
}

/* ------------------------------------------------------------------ *
 *  Eingabe-Validierung
 * ------------------------------------------------------------------ */

function chess_duell_valid_square($s) {
    return is_string($s) && preg_match('/^[a-h][1-8]$/', $s) === 1;
}

/** Anzeigename des angemeldeten WP-Nutzers (sonst leer). */
function chess_duell_default_name() {
    if (is_user_logged_in()) {
        return chess_duell_sanitize_name(wp_get_current_user()->display_name);
    }
    return '';
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

/** E-Mail bereinigen, leeren String bei ungültiger Adresse. */
function chess_duell_sanitize_email($email) {
    $email = sanitize_email((string) $email);
    return ($email && is_email($email)) ? $email : '';
}

/** Hinterlegte E-Mail des angemeldeten WP-Nutzers (sonst leer). */
function chess_duell_default_email() {
    if (is_user_logged_in()) {
        return chess_duell_sanitize_email(wp_get_current_user()->user_email);
    }
    return '';
}

/* ------------------------------------------------------------------ *
 *  Schachuhr – es zählt nur die Online-Zeit des Spielers am Zug
 * ------------------------------------------------------------------ */

function chess_duell_now_ms() {
    return (int) round(microtime(true) * 1000);
}

/** Adressen aus dem Datensatz entfernen (z. B. bei Spielende). */
function chess_duell_clear_emails(&$game) {
    $game['white_email'] = '';
    $game['black_email'] = '';
}

/**
 * Verbucht die Online-Bedenkzeit. Wird bei jedem "Heartbeat" (Poll/Zug/Join)
 * aufgerufen. Nur eigene Pings des Spielers am Zug zählen, und nur in kleinen
 * Schritten (gekappt), sodass Offline-Phasen die Uhr nicht belasten.
 */
function chess_duell_account_clock(&$game, $pinging_color, $now) {
    if (empty($game['clock_enabled']) || $game['status'] === 'finished' || empty($game['black_token'])) {
        return;
    }
    // Anwesenheit merken
    if ($pinging_color === 'w') {
        $game['seen_w'] = $now;
    } elseif ($pinging_color === 'b') {
        $game['seen_b'] = $now;
    }
    // Nur die eigene Online-Zeit des Spielers am Zug anrechnen.
    $turn = $game['turn'];
    if ($pinging_color !== $turn) {
        return;
    }
    if ($game['clock_last'] === null) {
        $game['clock_last'] = $now;
        return;
    }
    $delta = $now - intval($game['clock_last']);
    $game['clock_last'] = $now;
    if ($delta <= 0) {
        return;
    }
    if ($delta > CHESS_DUELL_MAX_TICK_MS) {
        $delta = CHESS_DUELL_MAX_TICK_MS; // Offline-Lücke kappen
    }
    if ($turn === 'w') {
        $game['white_ms'] = max(0, intval($game['white_ms']) - $delta);
        $rem = $game['white_ms'];
    } else {
        $game['black_ms'] = max(0, intval($game['black_ms']) - $delta);
        $rem = $game['black_ms'];
    }
    if ($rem <= 0) {
        $game['status']      = 'finished';
        $game['result']      = ($turn === 'w') ? '0-1' : '1-0';
        $game['result_type'] = 'timeout';
        $game['updated']     = time();
        chess_duell_clear_emails($game);
    }
}

/** Welche Farbe ist online am Zug (für die Anzeige der laufenden Uhr)? */
function chess_duell_clock_running($game, $now) {
    if (empty($game['clock_enabled']) || $game['status'] === 'finished' || empty($game['black_token'])) {
        return null;
    }
    $turn = $game['turn'];
    $seen = ($turn === 'w') ? $game['seen_w'] : $game['seen_b'];
    if ($seen === null || ($now - intval($seen)) > CHESS_DUELL_ONLINE_WINDOW_MS) {
        return null; // Spieler am Zug ist offline -> Uhr pausiert
    }
    return $turn;
}

/** Uhr-Teil der öffentlichen Sicht. */
function chess_duell_clock_public($game) {
    if (empty($game['clock_enabled'])) {
        return array('enabled' => false);
    }
    return array(
        'enabled'  => true,
        'base'     => intval($game['clock_base']),
        'inc'      => intval($game['clock_inc']),
        'white_ms' => intval($game['white_ms']),
        'black_ms' => intval($game['black_ms']),
        'running'  => chess_duell_clock_running($game, chess_duell_now_ms()),
    );
}

/* ------------------------------------------------------------------ *
 *  E-Mail-Benachrichtigung (Adresse wird mit Spielende gelöscht)
 * ------------------------------------------------------------------ */

/** Link zur Partie (für die Benachrichtigung). */
function chess_duell_game_link($game) {
    $base = !empty($game['page']) ? $game['page'] : home_url('/');
    return add_query_arg('chess_game', rawurlencode($game['id']), $base);
}

/** Den Spieler der Farbe $color benachrichtigen, dass er am Zug ist. */
function chess_duell_notify_turn(&$game, $color) {
    $email = ($color === 'w') ? $game['white_email'] : $game['black_email'];
    if (empty($email)) {
        return;
    }
    // Einfacher Spam-Schutz: höchstens alle 10 Sekunden eine Mail je Spieler.
    $key  = ($color === 'w') ? 'last_notify_w' : 'last_notify_b';
    $now  = time();
    if ($now - intval($game[$key]) < 10) {
        return;
    }
    $game[$key] = $now;

    $oppName = ($color === 'w')
        ? ($game['black_name'] !== '' ? $game['black_name'] : __('Black', 'chess-duell'))
        : ($game['white_name'] !== '' ? $game['white_name'] : __('White', 'chess-duell'));
    $link    = chess_duell_game_link($game);

    $subject = __('Chess: it is your turn', 'chess-duell');
    $body    = sprintf(
        /* translators: 1: opponent name, 2: link to the game */
        __("Hello,\n\n%1\$s has moved – it is your turn now.\n\nTo the game:\n%2\$s\n\nNote: your address is used only for notifications of this game and is automatically deleted when the game ends. It is not stored permanently.", 'chess-duell'),
        $oppName,
        $link
    );
    wp_mail($email, $subject, $body);
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

    // Angemeldete WordPress-Nutzer (jede Rolle) dürfen das Limit übergehen.
    if (!is_user_logged_in()) {
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
                sprintf(
                    /* translators: %d: maximum number of concurrent games */
                    __('There are already %d games in progress at the same time. Please try again later.', 'chess-duell'),
                    $max
                ),
                array('status' => 503)
            );
        }
    }

    try {
        $id     = substr(bin2hex(random_bytes(8)), 0, 12);
        $wtoken = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        return new WP_Error('chess_duell_rng', __('Random number generator not available.', 'chess-duell'), array('status' => 500));
    }

    $body = $req->get_json_params();
    $name = chess_duell_sanitize_name(isset($body['name']) ? $body['name'] : '');
    if ($name === '') {
        $name = chess_duell_default_name(); // ggf. WP-Anzeigename
    }
    $email = chess_duell_sanitize_email(isset($body['email']) ? $body['email'] : '');

    // Schachuhr: Basiszeit in Minuten + Inkrement in Sekunden (0 = ohne Uhr).
    $base_min = isset($body['clock_base']) ? intval($body['clock_base']) : 0;
    $inc_sec  = isset($body['clock_inc']) ? intval($body['clock_inc']) : 0;
    $base_min = max(0, min(600, $base_min));
    $inc_sec  = max(0, min(120, $inc_sec));
    $clock_enabled = $base_min > 0;
    $base_ms = $base_min * 60 * 1000;
    $inc_ms  = $inc_sec * 1000;

    $page = isset($body['page']) ? esc_url_raw((string) $body['page']) : '';

    $now  = time();
    $game = chess_duell_normalize_game(array(
        'id'            => $id,
        'fen'           => CHESS_DUELL_START_FEN,
        'turn'          => 'w',
        'moves'         => array(),
        'white_token'   => $wtoken,
        'black_token'   => null,
        'white_name'    => $name,
        'black_name'    => '',
        'white_email'   => $email,
        'black_email'   => '',
        'status'        => 'waiting',
        'result'        => null,
        'result_type'   => null,
        'created'       => $now,
        'updated'       => $now,
        'clock_enabled' => $clock_enabled,
        'clock_base'    => $base_ms,
        'clock_inc'     => $inc_ms,
        'white_ms'      => $base_ms,
        'black_ms'      => $base_ms,
        'clock_last'    => null,
        'seen_w'        => null,
        'seen_b'        => null,
        'page'          => $page,
    ));

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
        return new WP_Error('chess_duell_not_found', __('Game not found or expired.', 'chess-duell'), array('status' => 404));
    }

    $game = $games[$id];

    // Heartbeat: Token bestimmt die eigene Farbe -> Online-Zeit der Uhr buchen.
    $token = (string) $req->get_param('t');
    $color = null;
    if ($token !== '' && hash_equals($game['white_token'], $token)) {
        $color = 'w';
    } elseif ($token !== '' && !empty($game['black_token']) && hash_equals($game['black_token'], $token)) {
        $color = 'b';
    }
    if (!empty($game['clock_enabled'])) {
        chess_duell_account_clock($game, $color, chess_duell_now_ms());
    }

    $games[$id] = $game;
    chess_duell_save_games($games);
    return chess_duell_public_state($game);
}

function chess_duell_rest_join($req) {
    $games = chess_duell_prune(chess_duell_load_games());
    $id    = $req['id'];
    if (!isset($games[$id])) {
        return new WP_Error('chess_duell_not_found', __('Game not found or expired.', 'chess-duell'), array('status' => 404));
    }

    $game     = $games[$id];
    $body     = $req->get_json_params();
    $token    = isset($body['token']) ? (string) $body['token'] : '';
    $hasName  = isset($body['name']);
    $name     = $hasName ? chess_duell_sanitize_name($body['name']) : '';
    $hasEmail = isset($body['email']);
    $email    = $hasEmail ? chess_duell_sanitize_email($body['email']) : '';

    // Bekanntes Token (Weiß)? -> bestehende Farbe, ggf. Name/E-Mail aktualisieren.
    if ($token !== '' && hash_equals($game['white_token'], $token)) {
        $changed = false;
        if ($hasName && $name !== '' && $name !== $game['white_name']) { $game['white_name'] = $name; $changed = true; }
        if ($hasEmail && $email !== $game['white_email']) { $game['white_email'] = $email; $changed = true; }
        if ($changed) { $games[$id] = $game; chess_duell_save_games($games); }
        return array('color' => 'white', 'token' => $token, 'state' => chess_duell_public_state($game));
    }
    // Bekanntes Token (Schwarz)?
    if ($token !== '' && !empty($game['black_token']) && hash_equals($game['black_token'], $token)) {
        $changed = false;
        if ($hasName && $name !== '' && $name !== $game['black_name']) { $game['black_name'] = $name; $changed = true; }
        if ($hasEmail && $email !== $game['black_email']) { $game['black_email'] = $email; $changed = true; }
        if ($changed) { $games[$id] = $game; chess_duell_save_games($games); }
        return array('color' => 'black', 'token' => $token, 'state' => chess_duell_public_state($game));
    }

    // Noch kein Schwarzer? -> als Schwarz beitreten.
    if (empty($game['black_token'])) {
        try {
            $btoken = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            return new WP_Error('chess_duell_rng', __('Random number generator not available.', 'chess-duell'), array('status' => 500));
        }
        $now = chess_duell_now_ms();
        $game['black_token'] = $btoken;
        $game['black_name']  = ($name !== '') ? $name : chess_duell_default_name();
        $game['black_email'] = $email;
        $game['status']      = ($game['status'] === 'waiting') ? 'active' : $game['status'];
        $game['updated']     = time();
        // Schachuhr startet jetzt (Weiß am Zug); Anwesenheit initialisieren.
        if (!empty($game['clock_enabled'])) {
            $game['clock_last'] = $now;
            $game['seen_w']     = $now;
            $game['seen_b']     = $now;
        }
        $games[$id] = $game;
        chess_duell_save_games($games);
        // Weiß ist am Zug -> ggf. benachrichtigen, dass der Gegner da ist.
        chess_duell_notify_turn($game, 'w');
        $games[$id] = $game; // notify kann last_notify_w gesetzt haben
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
        return new WP_Error('chess_duell_not_found', __('Game not found or expired.', 'chess-duell'), array('status' => 404));
    }

    $game = $games[$id];
    if ($game['status'] === 'finished') {
        return new WP_Error('chess_duell_finished', __('The game has already ended.', 'chess-duell'), array('status' => 409));
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
        return new WP_Error('chess_duell_forbidden', __('No valid player token.', 'chess-duell'), array('status' => 403));
    }

    if (empty($game['black_token'])) {
        return new WP_Error('chess_duell_waiting', __('No opponent has connected yet.', 'chess-duell'), array('status' => 409));
    }

    // Ist die Seite am Zug?
    if ($color !== $game['turn']) {
        return new WP_Error('chess_duell_not_your_turn', __('It is not your turn.', 'chess-duell'), array('status' => 409));
    }

    $from = isset($body['from']) ? (string) $body['from'] : '';
    $to   = isset($body['to']) ? (string) $body['to'] : '';
    if (!chess_duell_valid_square($from) || !chess_duell_valid_square($to)) {
        return new WP_Error('chess_duell_bad_move', __('Invalid squares.', 'chess-duell'), array('status' => 400));
    }

    $promotion = isset($body['promotion']) ? strtolower((string) $body['promotion']) : null;
    if ($promotion !== null && !in_array($promotion, array('q', 'r', 'b', 'n'), true)) {
        $promotion = null;
    }

    // Schachuhr: Online-Zeit des Ziehenden bis jetzt verbuchen. Ist die Zeit
    // dabei abgelaufen, endet die Partie und der Zug wird verworfen.
    $now = chess_duell_now_ms();
    if (!empty($game['clock_enabled'])) {
        chess_duell_account_clock($game, $color, $now);
        if ($game['status'] === 'finished') {
            $games[$id] = $game;
            chess_duell_save_games($games);
            return chess_duell_public_state($game);
        }
    }

    // --- Serverseitige Regelprüfung ---------------------------------
    // Der Server ist die einzige Wahrheit: Er lädt die gespeicherte
    // Stellung, prüft die Legalität des Zuges selbst und berechnet
    // FEN/SAN/Spielende neu. Vom Client gesendete fen/san/result werden
    // bewusst ignoriert -> Cheaten ist nicht möglich.
    $engine = new Chess_Duell_Engine($game['fen']);

    // Sicherheitsnetz: Stellung am Zug muss zur Spieldatensatz-Farbe passen.
    if ($engine->turn !== $game['turn']) {
        return new WP_Error('chess_duell_state', __('Inconsistent position.', 'chess-duell'), array('status' => 409));
    }

    $applied = $engine->move($from, $to, $promotion);
    if ($applied === null) {
        return new WP_Error('chess_duell_illegal', __('Invalid move.', 'chess-duell'), array('status' => 422));
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
    $over   = !empty($status['over']);
    if ($over) {
        $game['status'] = 'finished';
        if ($status['type'] === 'checkmate') {
            $game['result'] = ($status['winner'] === 'w') ? '1-0' : '0-1';
        } else {
            $game['result'] = '1/2-1/2';
        }
        $game['result_type'] = $status['type'];
    }

    // Schachuhr: Inkrement gutschreiben und Uhr an den Gegner übergeben.
    if (!empty($game['clock_enabled']) && !$over) {
        if ($color === 'w') {
            $game['white_ms'] = intval($game['white_ms']) + intval($game['clock_inc']);
        } else {
            $game['black_ms'] = intval($game['black_ms']) + intval($game['clock_inc']);
        }
        $game['clock_last'] = $now; // Uhr des nun ziehenden Gegners startet
    }

    if ($over) {
        chess_duell_clear_emails($game);
    }

    $games[$id] = $game;
    chess_duell_save_games($games);

    // Gegner ist jetzt am Zug -> benachrichtigen (sofern Partie nicht beendet).
    if (!$over) {
        $opp = ($color === 'w') ? 'b' : 'w';
        chess_duell_notify_turn($game, $opp);
        $games[$id] = $game; // notify kann den Spam-Schutz-Zeitstempel ändern
        chess_duell_save_games($games);
    }

    return chess_duell_public_state($game);
}

function chess_duell_rest_resign($req) {
    $games = chess_duell_prune(chess_duell_load_games());
    $id    = $req['id'];
    if (!isset($games[$id])) {
        return new WP_Error('chess_duell_not_found', __('Game not found or expired.', 'chess-duell'), array('status' => 404));
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
        return new WP_Error('chess_duell_forbidden', __('No valid player token.', 'chess-duell'), array('status' => 403));
    }

    if ($game['status'] !== 'finished') {
        $game['status']      = 'finished';
        $game['result']      = ($color === 'w') ? '0-1' : '1-0';
        $game['result_type'] = 'resign';
        $game['updated']     = time();
        chess_duell_clear_emails($game);
        $games[$id]          = $game;
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
        <h1><?php echo esc_html__('Chess Duell – Settings', 'chess-duell'); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields('chess_duell_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="cd-max"><?php echo esc_html__('Maximum concurrent games', 'chess-duell'); ?></label></th>
                    <td>
                        <input name="<?php echo esc_attr(CHESS_DUELL_SETTINGS); ?>[max_games]" id="cd-max"
                               type="number" min="1" max="200" step="1"
                               value="<?php echo esc_attr($s['max_games']); ?>" class="small-text">
                        <p class="description"><?php echo esc_html__('How many games may run at the same time (1–200). Finished games do not count. Logged-in WordPress users may bypass this limit.', 'chess-duell'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cd-ttl"><?php echo esc_html__('Lifetime until auto-end (days)', 'chess-duell'); ?></label></th>
                    <td>
                        <input name="<?php echo esc_attr(CHESS_DUELL_SETTINGS); ?>[ttl_days]" id="cd-ttl"
                               type="number" min="1" max="365" step="1"
                               value="<?php echo esc_attr($s['ttl_days']); ?>" class="small-text">
                        <p class="description"><?php echo esc_html__('After this many days without activity a game is removed automatically (1–365). Otherwise a game ends only by checkmate, resignation or draw.', 'chess-duell'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <p><strong><?php echo esc_html__('Shortcode:', 'chess-duell'); ?></strong> <code>[chess_duell]</code></p>
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
        'restUrl'   => esc_url_raw(rest_url('chess-duell/v1/')),
        'nonce'     => wp_create_nonce('wp_rest'),
        'gameId'    => $game_id ? $game_id : null,
        'userName'  => chess_duell_default_name(),
        'userEmail' => chess_duell_default_email(),
        'loggedIn'  => is_user_logged_in(),
        'i18n'      => chess_duell_js_i18n(),
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
