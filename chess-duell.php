<?php
/**
 * Plugin Name:       Chess Duell
 * Plugin URI:        https://github.com/strYchni0x/chess-duell
 * Description:        Zwei Menschen spielen online Schach gegeneinander – Partie einfach per Link teilen. Anzahl gleichzeitiger Partien und Laufzeit im Backend einstellbar. Serverseitige Regelprüfung (kein Cheaten möglich), keine KI. Einbinden mit dem Shortcode [chess_duell].
 * Version:           1.9.0
 * Author:            Florian Willnat
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chess-duell
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CHESS_DUELL_VERSION', '1.9.0');
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

/* ------------------------------------------------------------------ *
 *  GitHub-Updater
 *  Meldet Updates aus dem GitHub-Repository im WordPress-Dashboard.
 *  → Bei Umstieg auf wordpress.org diesen Block UND die Datei
 *    includes/class-github-updater.php entfernen (siehe Hinweis dort).
 * ------------------------------------------------------------------ */
if (is_admin() || (defined('DOING_CRON') && DOING_CRON)) {
    require_once CHESS_DUELL_PATH . 'includes/class-github-updater.php';
    new Chess_Duell_GitHub_Updater(
        __FILE__,
        'strYchni0x/chess-duell',
        defined('CHESS_DUELL_GITHUB_TOKEN') ? CHESS_DUELL_GITHUB_TOKEN : ''
    );
}

/* ------------------------------------------------------------------ *
 *  Einstellungen (im Backend konfigurierbar)
 * ------------------------------------------------------------------ */

function chess_duell_get_settings() {
    $defaults = array(
        'max_games'    => CHESS_DUELL_DEFAULT_MAX_GAMES,
        'ttl_days'     => CHESS_DUELL_DEFAULT_TTL_DAYS,
        'allow_bypass' => true, // angemeldete Nutzer dürfen das Limit übergehen
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

/** Dürfen angemeldete Nutzer das Limit gleichzeitiger Partien übergehen? */
function chess_duell_allow_bypass() {
    $s = chess_duell_get_settings();
    return !empty($s['allow_bypass']);
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
        // Spieler-Zuordnung: WP-User-ID (0 = Gast) + anonyme Gast-Browser-ID
        'white_user'    => 0,
        'black_user'    => 0,
        'white_cid'     => '',
        'black_cid'     => '',
        // Chat (nur zwischen den beiden Spielern): Liste von {color,name,text,time}
        'messages'      => array(),
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

/**
 * Öffentliche Sicht eines Spiels (ohne geheime Tokens).
 * $include_chat = true nur für die beiden Spieler (Chat ist privat).
 */
function chess_duell_public_state($game, $include_chat = false) {
    $state = array(
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
    if ($include_chat) {
        $state['messages'] = array_values(isset($game['messages']) ? $game['messages'] : array());
    }
    return $state;
}

/** Welche Farbe gehört zum Token? 'w'/'b' oder null. */
function chess_duell_color_for_token($game, $token) {
    if ($token === '' || $token === null) {
        return null;
    }
    if (hash_equals($game['white_token'], (string) $token)) {
        return 'w';
    }
    if (!empty($game['black_token']) && hash_equals($game['black_token'], (string) $token)) {
        return 'b';
    }
    return null;
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

/** Anonyme Gast-Browser-ID bereinigen ([a-z0-9], max. 32 Zeichen). */
function chess_duell_sanitize_cid($cid) {
    $cid = preg_replace('/[^a-z0-9]/', '', strtolower((string) $cid));
    return substr($cid, 0, 32);
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
        ? ($game['black_name'] !== '' ? $game['black_name'] : 'Schwarz')
        : ($game['white_name'] !== '' ? $game['white_name'] : 'Weiß');
    $link    = chess_duell_game_link($game);

    // Zuletzt gezogenen Zug (SAN) für die Mail aufbereiten (leer, falls noch kein Zug).
    $lastMove = '';
    if (!empty($game['moves'])) {
        $m   = end($game['moves']);
        $san = (isset($m['san']) && $m['san'] !== '') ? $m['san'] : ($m['from'] . $m['to']);
        $ply = count($game['moves']);
        $num = (int) ceil($ply / 2);
        $notation = (($ply % 2) === 1) ? ($num . '. ' . $san) : ($num . '… ' . $san);
        $lastMove = 'Zuletzt gezogen: ' . $notation . "\n\n";
    }

    $subject = 'Schach: Du bist am Zug';
    $body    = sprintf(
        "Hallo,\n\n%s hat gezogen – du bist jetzt am Zug.\n\n%sZur Partie:\n%s\n\n" .
        "Hinweis: Deine Adresse wird nur für Benachrichtigungen dieser Partie verwendet " .
        "und mit dem Spielende automatisch gelöscht. Sie wird nicht dauerhaft gespeichert.",
        $oppName,
        $lastMove,
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

    register_rest_route($ns, '/game/(?P<id>[a-f0-9]{6,32})/chat', array(
        'methods'             => 'POST',
        'callback'            => 'chess_duell_rest_chat',
        'permission_callback' => '__return_true',
    ));

    register_rest_route($ns, '/mygames', array(
        'methods'             => 'GET',
        'callback'            => 'chess_duell_rest_mygames',
        'permission_callback' => '__return_true',
    ));
});

/**
 * Liefert die Partien des Anfragenden (über WP-User-ID und/oder Gast-Browser-ID).
 * Gibt keine Tokens zurück – nur Anzeige-Infos für den "Meine Partien"-Umschalter.
 */
function chess_duell_rest_mygames($req) {
    $games = chess_duell_prune(chess_duell_load_games());
    chess_duell_save_games($games);

    $uid = get_current_user_id();
    $cid = chess_duell_sanitize_cid((string) $req->get_param('cid'));
    $out = array();

    foreach ($games as $g) {
        $color = null;
        if ($uid > 0 && intval($g['white_user']) === $uid) {
            $color = 'white';
        } elseif ($uid > 0 && intval($g['black_user']) === $uid) {
            $color = 'black';
        } elseif ($cid !== '' && $g['white_cid'] === $cid) {
            $color = 'white';
        } elseif ($cid !== '' && $g['black_cid'] === $cid) {
            $color = 'black';
        }
        if ($color === null) {
            continue;
        }
        $opponent = ($color === 'white')
            ? (!empty($g['black_token']) ? ($g['black_name'] !== '' ? $g['black_name'] : 'Schwarz') : '')
            : ($g['white_name'] !== '' ? $g['white_name'] : 'Weiß');
        $my_code   = ($color === 'white') ? 'w' : 'b';
        $your_turn = ($g['status'] !== 'finished' && !empty($g['black_token']) && $g['turn'] === $my_code);

        $out[] = array(
            'id'        => $g['id'],
            'color'     => $color,
            'opponent'  => $opponent,
            'status'    => $g['status'],
            'result'    => $g['result'],
            'has_black' => !empty($g['black_token']),
            'your_turn' => $your_turn,
            'updated'   => intval($g['updated']),
        );
    }
    usort($out, function ($a, $b) { return $b['updated'] - $a['updated']; });
    return $out;
}

function chess_duell_rest_create($req) {
    $games = chess_duell_prune(chess_duell_load_games());

    // Angemeldete WordPress-Nutzer dürfen das Limit übergehen – sofern im Backend aktiviert.
    if (!(is_user_logged_in() && chess_duell_allow_bypass())) {
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
    }

    try {
        $id     = substr(bin2hex(random_bytes(8)), 0, 12);
        $wtoken = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        return new WP_Error('chess_duell_rng', 'Zufallsgenerator nicht verfügbar.', array('status' => 500));
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
    $cid  = chess_duell_sanitize_cid(isset($body['cid']) ? $body['cid'] : '');

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
        'white_user'    => get_current_user_id(),
        'black_user'    => 0,
        'white_cid'     => $cid,
        'black_cid'     => '',
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
        'state' => chess_duell_public_state($game, true),
    );
}

function chess_duell_rest_get($req) {
    $games = chess_duell_prune(chess_duell_load_games());
    $id    = $req['id'];
    if (!isset($games[$id])) {
        return new WP_Error('chess_duell_not_found', 'Partie nicht gefunden oder abgelaufen.', array('status' => 404));
    }

    $game = $games[$id];

    // Heartbeat: Token bestimmt die eigene Farbe -> Online-Zeit der Uhr buchen.
    $token = (string) $req->get_param('t');
    $color = chess_duell_color_for_token($game, $token);
    if (!empty($game['clock_enabled'])) {
        chess_duell_account_clock($game, $color, chess_duell_now_ms());
    }

    $games[$id] = $game;
    chess_duell_save_games($games);
    // Chat nur an die beiden Spieler ausliefern (Token vorhanden).
    return chess_duell_public_state($game, $color !== null);
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
    $hasEmail = isset($body['email']);
    $email    = $hasEmail ? chess_duell_sanitize_email($body['email']) : '';

    // Bekanntes Token (Weiß)? -> bestehende Farbe, ggf. Name/E-Mail aktualisieren.
    if ($token !== '' && hash_equals($game['white_token'], $token)) {
        $changed = false;
        if ($hasName && $name !== '' && $name !== $game['white_name']) { $game['white_name'] = $name; $changed = true; }
        if ($hasEmail && $email !== $game['white_email']) { $game['white_email'] = $email; $changed = true; }
        if ($changed) { $games[$id] = $game; chess_duell_save_games($games); }
        return array('color' => 'white', 'token' => $token, 'state' => chess_duell_public_state($game, true));
    }
    // Bekanntes Token (Schwarz)?
    if ($token !== '' && !empty($game['black_token']) && hash_equals($game['black_token'], $token)) {
        $changed = false;
        if ($hasName && $name !== '' && $name !== $game['black_name']) { $game['black_name'] = $name; $changed = true; }
        if ($hasEmail && $email !== $game['black_email']) { $game['black_email'] = $email; $changed = true; }
        if ($changed) { $games[$id] = $game; chess_duell_save_games($games); }
        return array('color' => 'black', 'token' => $token, 'state' => chess_duell_public_state($game, true));
    }

    // Noch kein Schwarzer? -> als Schwarz beitreten.
    if (empty($game['black_token'])) {
        try {
            $btoken = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            return new WP_Error('chess_duell_rng', 'Zufallsgenerator nicht verfügbar.', array('status' => 500));
        }
        $now = chess_duell_now_ms();
        $game['black_token'] = $btoken;
        $game['black_name']  = ($name !== '') ? $name : chess_duell_default_name();
        $game['black_email'] = $email;
        $game['black_user']  = get_current_user_id();
        $game['black_cid']   = chess_duell_sanitize_cid(isset($body['cid']) ? $body['cid'] : '');
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
        return array('color' => 'black', 'token' => $btoken, 'state' => chess_duell_public_state($game, true));
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

    // Schachuhr: Online-Zeit des Ziehenden bis jetzt verbuchen. Ist die Zeit
    // dabei abgelaufen, endet die Partie und der Zug wird verworfen.
    $now = chess_duell_now_ms();
    if (!empty($game['clock_enabled'])) {
        chess_duell_account_clock($game, $color, $now);
        if ($game['status'] === 'finished') {
            $games[$id] = $game;
            chess_duell_save_games($games);
            return chess_duell_public_state($game, true);
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

    return chess_duell_public_state($game, true);
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
        $game['status']      = 'finished';
        $game['result']      = ($color === 'w') ? '0-1' : '1-0';
        $game['result_type'] = 'resign';
        $game['updated']     = time();
        chess_duell_clear_emails($game);
        $games[$id]          = $game;
        chess_duell_save_games($games);
    }

    return chess_duell_public_state($game, true);
}

/** Chat-Nachricht (nur die beiden Spieler dürfen schreiben). */
function chess_duell_rest_chat($req) {
    $games = chess_duell_prune(chess_duell_load_games());
    $id    = $req['id'];
    if (!isset($games[$id])) {
        return new WP_Error('chess_duell_not_found', 'Partie nicht gefunden oder abgelaufen.', array('status' => 404));
    }
    $game  = $games[$id];
    $body  = $req->get_json_params();
    $token = isset($body['token']) ? (string) $body['token'] : '';
    $color = chess_duell_color_for_token($game, $token);
    if ($color === null) {
        return new WP_Error('chess_duell_forbidden', 'Kein gültiges Spieler-Token.', array('status' => 403));
    }

    $text = isset($body['text']) ? sanitize_text_field((string) $body['text']) : '';
    $text = trim($text);
    if ($text === '') {
        return new WP_Error('chess_duell_empty', 'Leere Nachricht.', array('status' => 400));
    }
    $text = function_exists('mb_substr') ? mb_substr($text, 0, 500) : substr($text, 0, 500);

    $name = ($color === 'w')
        ? ($game['white_name'] !== '' ? $game['white_name'] : 'Weiß')
        : ($game['black_name'] !== '' ? $game['black_name'] : 'Schwarz');

    $game['messages'][] = array('color' => $color, 'name' => $name, 'text' => $text, 'time' => time());
    if (count($game['messages']) > 300) {
        $game['messages'] = array_slice($game['messages'], -300);
    }
    $game['updated'] = time();

    $games[$id] = $game;
    chess_duell_save_games($games);
    return chess_duell_public_state($game, true);
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
            'max_games'    => CHESS_DUELL_DEFAULT_MAX_GAMES,
            'ttl_days'     => CHESS_DUELL_DEFAULT_TTL_DAYS,
            'allow_bypass' => true,
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
    // Checkbox: nicht gesetzt => deaktiviert.
    $out['allow_bypass'] = !empty($input['allow_bypass']);
    return $out;
}

/** Verwaltungs-Aktionen (Partie löschen / beendete entfernen) abarbeiten. */
function chess_duell_handle_admin_actions() {
    if (empty($_POST['chess_duell_action']) || !current_user_can('manage_options')) {
        return;
    }
    check_admin_referer('chess_duell_manage');
    $action = sanitize_text_field(wp_unslash($_POST['chess_duell_action']));
    $games  = chess_duell_load_games();

    if ($action === 'delete' && !empty($_POST['game'])) {
        $id = preg_replace('/[^a-f0-9]/', '', sanitize_text_field(wp_unslash($_POST['game'])));
        if ($id !== '' && isset($games[$id])) {
            unset($games[$id]);
            chess_duell_save_games($games);
            add_settings_error('chess_duell', 'cd_deleted', 'Partie gelöscht.', 'updated');
        }
    } elseif ($action === 'delete_finished') {
        $removed = 0;
        foreach ($games as $gid => $g) {
            if ($g['status'] === 'finished') { unset($games[$gid]); $removed++; }
        }
        chess_duell_save_games($games);
        add_settings_error('chess_duell', 'cd_deleted_fin', sprintf('%d beendete Partie(n) entfernt.', $removed), 'updated');
    }
}

function chess_duell_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    chess_duell_handle_admin_actions();
    $s     = chess_duell_get_settings();
    $games = chess_duell_prune(chess_duell_load_games());
    chess_duell_save_games($games); // abgelaufene Partien gleich aufräumen

    $active = 0;
    foreach ($games as $g) {
        if ($g['status'] !== 'finished') { $active++; }
    }
    settings_errors('chess_duell');
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
                    <th scope="row">Angemeldete Nutzer</th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(CHESS_DUELL_SETTINGS); ?>[allow_bypass]"
                                   value="1" <?php checked(!empty($s['allow_bypass'])); ?>>
                            Angemeldete WordPress-Nutzer dürfen das Limit gleichzeitiger Partien übergehen
                        </label>
                        <p class="description">Wenn deaktiviert, gilt das Limit oben für alle – auch für angemeldete Nutzer.</p>
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

        <h2>Laufende Partien</h2>
        <p>
            <strong><?php echo intval($active); ?></strong> laufende Partie(n),
            <strong><?php echo count($games); ?></strong> gespeichert insgesamt.
        </p>
        <?php chess_duell_render_games_table($games); ?>

        <p style="margin-top:1em;"><strong>Shortcode:</strong> <code>[chess_duell]</code></p>
    </div>
    <?php
}

/** Tabelle aller gespeicherten Partien mit Lösch-Aktion. */
function chess_duell_render_games_table($games) {
    if (empty($games)) {
        echo '<p>Aktuell sind keine Partien gespeichert.</p>';
        return;
    }
    $status_labels = array('waiting' => 'Wartet auf Gegner', 'active' => 'Läuft', 'finished' => 'Beendet');
    // Neueste zuerst.
    uasort($games, function ($a, $b) { return intval($b['updated']) - intval($a['updated']); });
    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Status</th>
                <th>Weiß</th>
                <th>Schwarz</th>
                <th>Züge</th>
                <th>Am Zug</th>
                <th>Uhr</th>
                <th>Letzte Aktivität</th>
                <th>Aktion</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($games as $id => $g) :
            $status = isset($status_labels[$g['status']]) ? $status_labels[$g['status']] : $g['status'];
            if ($g['status'] === 'finished' && !empty($g['result'])) {
                $status .= ' (' . $g['result'] . ')';
            }
            $white = $g['white_name'] !== '' ? $g['white_name'] : 'Weiß';
            if (!empty($g['white_user'])) {
                $wu = get_userdata(intval($g['white_user']));
                if ($wu) { $white .= ' (@' . $wu->user_login . ')'; }
            }
            $black = !empty($g['black_token']) ? ($g['black_name'] !== '' ? $g['black_name'] : 'Schwarz') : '—';
            if (!empty($g['black_user'])) {
                $bu = get_userdata(intval($g['black_user']));
                if ($bu) { $black .= ' (@' . $bu->user_login . ')'; }
            }
            $turn  = $g['status'] === 'finished' ? '—' : ($g['turn'] === 'w' ? 'Weiß' : 'Schwarz');
            $clock = !empty($g['clock_enabled']) ? (intval($g['clock_base'] / 60000) . ' Min'
                     . (intval($g['clock_inc']) > 0 ? ' +' . intval($g['clock_inc'] / 1000) . ' Sek' : '')) : '—';
            $ago   = human_time_diff(intval($g['updated']), time()) . ' her';
        ?>
            <tr>
                <td><?php echo esc_html($status); ?></td>
                <td><?php echo esc_html($white); ?></td>
                <td><?php echo esc_html($black); ?></td>
                <td><?php echo intval(count($g['moves'])); ?></td>
                <td><?php echo esc_html($turn); ?></td>
                <td><?php echo esc_html($clock); ?></td>
                <td><?php echo esc_html($ago); ?></td>
                <td>
                    <form method="post" style="margin:0;"
                          onsubmit="return confirm('Diese Partie wirklich löschen?');">
                        <?php wp_nonce_field('chess_duell_manage'); ?>
                        <input type="hidden" name="chess_duell_action" value="delete">
                        <input type="hidden" name="game" value="<?php echo esc_attr($id); ?>">
                        <button type="submit" class="button button-small button-link-delete">Löschen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <form method="post" style="margin-top:0.8em;"
          onsubmit="return confirm('Alle beendeten Partien entfernen?');">
        <?php wp_nonce_field('chess_duell_manage'); ?>
        <input type="hidden" name="chess_duell_action" value="delete_finished">
        <button type="submit" class="button">Alle beendeten Partien entfernen</button>
    </form>
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

    // Reiner Lesezugriff der Partie-ID aus der URL (keine Formularverarbeitung -> keine Nonce nötig).
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $game_id = isset($_GET['chess_game']) ? sanitize_text_field(wp_unslash($_GET['chess_game'])) : '';
    $game_id = preg_replace('/[^a-f0-9]/', '', $game_id);

    $config = array(
        'restUrl'   => esc_url_raw(rest_url('chess-duell/v1/')),
        'nonce'     => wp_create_nonce('wp_rest'),
        'gameId'    => $game_id ? $game_id : null,
        'userName'  => chess_duell_default_name(),
        'userEmail' => chess_duell_default_email(),
        'loggedIn'  => is_user_logged_in(),
    );

    // Konfiguration zusätzlich per wp_localize_script bereitstellen ...
    wp_localize_script('chess-duell-app', 'ChessDuellConfig', $config);

    // ... und – robust gegen CSP / Skript-Optimierer / Inline-Script-Stripping –
    // direkt als data-Attribut am Container. app.js liest bevorzugt von hier.
    return '<div class="chess-duell-root" data-config="' . esc_attr(wp_json_encode($config)) . '"></div>';
}
add_shortcode('chess_duell', 'chess_duell_shortcode');

/* Aufräumen bei Deinstallation. */
register_uninstall_hook(__FILE__, 'chess_duell_uninstall');
function chess_duell_uninstall() {
    delete_option(CHESS_DUELL_OPTION);
    delete_option(CHESS_DUELL_SETTINGS);
}
