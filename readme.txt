=== Chess Duell ===
Contributors: strychni0x
Tags: chess, game, board game, two player
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.5.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Two people play online chess against each other – just share a link. Full rules, server-side validated, no AI.

== Description ==

Chess Duell provides a complete online chess game for two human players.
There is no AI – only the complete rules of chess. Embed it with the shortcode `[chess_duell]`.

Features:

* Human vs. human via a shared link (hash in the URL: ?chess_game=...).
* Whoever creates the game plays White; the second visitor automatically becomes Black.
  Further visitors watch the game as spectators.
* Players can choose their own name. For logged-in WordPress users the display name is
  pre-filled (and can be overridden).
* Logged-in WordPress users (any role) may bypass the limit of concurrent games.
* Complete chess rules: castling, en passant, pawn promotion, check/checkmate/stalemate,
  fifty-move rule, insufficient material.
* Server-side rule validation: every move is validated on the server (own PHP engine).
  Cheating via a manipulated browser is not possible.
* Configurable in the backend: number of concurrent games and the lifetime until a game
  ends automatically (Settings > Chess Duell).
* The last move is highlighted (origin/target square on the board and in the move list).
* Optional chess clock when creating a game. Only the online time of the player to move is
  counted – if someone is offline, their clock pauses. On timeout, that player loses.
* Optional e-mail notification when it is your turn (pre-filled for logged-in users).
* No external dependencies (no CDN, no images, no external calls) – Unicode pieces.

== Installation ==

1. Search for and install the plugin in the directory – or upload the ZIP under
   "Plugins > Add New > Upload Plugin". Then activate it.
2. Insert the shortcode on a page or in a post:

   `[chess_duell]`

3. Open the page, click "Start new game" and send the displayed link to your opponent.

== Frequently Asked Questions ==

= Do players need an account or to log in? =

No. Any visitor can start a game and share it via a link. Logged-in WordPress users get
convenience benefits (name/e-mail pre-filled, no game limit).

= How are moves validated? =

Every move is validated on the server with the plugin's own PHP chess engine. The server
computes the position (FEN), notation (SAN) and the game result itself and is therefore the
single source of truth. A manipulated client cannot make an illegal move.

= What happens to the e-mail address used for notifications? =

It is optional. If an address is provided, the plugin uses it solely to notify the player
(via WordPress mail, wp_mail) when it is their turn. The address is automatically deleted
when the game ends and is not stored permanently. No data is sent to third parties or
external services.

= Where are the games stored? =

In the WordPress database (a plugin option). A game ends by checkmate, resignation or draw,
or expires after the inactivity period configured in the backend (default 14 days).

== Screenshots ==

1. A game in progress with the last move highlighted, the status bar and the move list.
2. The lobby when creating a game: name, chess clock and optional e-mail notification.
3. Backend settings: number of concurrent games and lifetime.

== Changelog ==

= 1.5.6 =
* Plugin Check polish: removed manual load_plugin_textdomain() (auto-loaded via headers) and hardened the $_GET read in the shortcode (wp_unslash + sanitize).

= 1.5.5 =
* Added translators comments for placeholder strings (i18n) and updated "Tested up to".

= 1.5.4 =
* Fix: configuration (REST URL) is also passed via a data attribute – now works even with a Content-Security-Policy or script optimizers that strip inline scripts.

= 1.5.3 =
* Fix: polling now also works with plain permalinks (?rest_route=).

= 1.5.2 =
* Plugin website (admin) now points to the GitHub repository.

= 1.5.1 =
* The last move is highlighted: origin/target square on the board and the entry in the move list.

= 1.5.0 =
* Maintenance release and internal adjustments.

= 1.4.0 =
* Optional chess clock (counts online time only, pauses when away).
* Optional "it's your turn" e-mail notification; address is deleted when the game ends.
* Backward compatible: games in progress survive the update unchanged.

= 1.3.0 =
* Logged-in WordPress users may bypass the concurrent-games limit.
* The display name of logged-in users is pre-filled as the player name (overridable).

= 1.2.0 =
* Server-side rule validation (own PHP engine) – cheating no longer possible.
* Backend settings for the number of concurrent games and lifetime.
* Players can choose their own name.

= 1.0.0 =
* First version.

== Upgrade Notice ==

= 1.5.6 =
Code-quality polish for the WordPress Plugin Check (i18n loading and input handling).
