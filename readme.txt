=== Human2Human Chess Duell ===
Contributors: strychni0x
Tags: chess, game, multiplayer, two-player, board-game
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 1.12.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Two people play chess online against each other - just share a link. No AI, full chess rules, server-side move validation.

== Description ==

Human2Human Chess Duell provides a complete online chess game for two human players.
There is no AI - only the full chess rules.

Features:

* Human vs human via a shared link (hash in the URL: ?chess_game=...)
* Whoever creates the game plays White; the second visitor automatically becomes Black.
  Further visitors watch the game as spectators.
* Players can choose their own name. For logged-in WordPress users the display
  name is pre-filled (and can be overridden).
* Logged-in WordPress users (any role) may bypass the limit of simultaneous games.
* Full chess rules: castling, en passant, pawn promotion,
  check/checkmate/stalemate, fifty-move rule, insufficient material.
* Server-side rule validation: every move is validated on the server
  (custom PHP engine). Cheating via a manipulated browser is not possible.
* Configurable in the backend: number of simultaneous games and the lifetime
  until automatic end (Settings > Human2Human Chess Duell).
* A game ends only by checkmate, resignation or draw - or expires after the
  configured period of inactivity (default 14 days).
* Optional chess clock when creating the game. Only the online time of the
  player to move is counted - if someone is offline, their clock pauses (no time
  spent overnight). On timeout the player loses.
* Optional e-mail notification when it is your turn (pre-filled for logged-in
  users with a stored address). The address is used only for the notification
  and automatically deleted when the game ends - not stored permanently
  (technically visible in the server log).
* Updates do not interrupt running games (backward-compatible data).
* Synchronization via the WordPress REST API (polling, every 2 seconds).
* No external dependencies (no CDN, no images) - Unicode chess pieces.
* In-game private chat between the two players, a move list, an optional
  "show piece names" tooltip helper, and a personal "continue on another
  device" link (e.g. move from desktop to phone).

The plugin interface is in English and fully translatable. A complete German
translation (de_DE, incl. de_DE_formal) is bundled with the plugin.

== Installation ==

1. Upload the plugin ZIP under "Plugins > Add New > Upload Plugin" and activate it.
2. Insert the shortcode on a page or in a post:

   [chess_duell]

3. Open the page, click "Start new game" and send the displayed link to your opponent.

== Frequently Asked Questions ==

= Is an account required to play? =
No. Guests can play without logging in. The link you share identifies the game.

= Can I continue a game on another device? =
Yes. Each player can generate a personal "continue on another device" link and
send it to themselves (for example from desktop to phone) to keep playing as the
same color.

= Is cheating possible? =
No. Every move is validated server-side by a custom PHP chess engine; the server
computes the position (FEN), notation (SAN) and game end itself and is the single
source of truth. A manipulated client cannot force an illegal move.

== Changelog ==

= 1.12.0 =
* New: "your turn" e-mail notifications can now be turned off entirely in the settings.
* New: optional limit on how many notification e-mails are sent per 30 minutes (default 40, 0 = no limit) to stay within a hosting provider's outgoing-mail limit. When the limit is reached, further notifications are skipped; players still see the move when they open the game.

= 1.11.1 =
* Security: turn-notification e-mails now always link to your own site. The optional per-game return URL is restricted to the site's own host, so a crafted game can no longer point the notification link at an external (phishing) domain. Already-stored games are covered too.

= 1.11.0 =
* Internationalization: source strings are now English; a complete German translation (de_DE and de_DE_formal) is bundled.
* All user-facing strings are now translatable, including the admin settings page, REST error messages and the e-mail notification (previously hard-coded in German).

= 1.10.0 =
* New: "Continue on another device" - each player can create a personal link and send it to themselves (e.g. from desktop to phone) to keep playing as the same color. The token is adopted on opening and immediately removed from the URL.

= 1.9.1 =
* Fix: the board became too small in the new layout and the bottom row was cut off. The board now sits large at the top, with the move list and chat side by side below it; the board always stays perfectly square.
* Fix: in the narrow chat column the "Send" button wrapped and the input field was squeezed - input row corrected.

= 1.9.0 =
* The chat now sits directly to the right of the move list (instead of below it) for better visibility. On narrow screens they remain stacked.
* New option on the game page: "Show piece names" - hovering a piece with the mouse shows its name (e.g. "Queen (White)", "King (Black)"). The setting is remembered per browser.

= 1.8.0 =
* New private chat between the two players (only the two players can read and write).

= 1.7.0 =
* New "My games" switcher: players with several open games can switch within the same window without reloading (with a "your turn" marker).
* Games are associated with players: WP user ID for logged-in users and an anonymous guest browser ID. The backend overview shows the account (@login).

= 1.6.0 =
* Backend: overview of all running/stored games with the option to delete individual games (and all finished ones at once).
* Backend: the limit bypass for logged-in users is now optionally on/off.

= 1.5.7 =
* The "it's your turn" e-mail now includes the last move played.

= 1.5.1 =
* The last move is highlighted: origin/target squares on the board and the entry in the move list.

= 1.4.0 =
* Optional chess clock (counts only online time, pauses on absence).
* Optional "it's your turn" e-mail notification; the address is deleted when the game ends.
* Backward compatible: running games survive the update unchanged.

= 1.3.0 =
* Logged-in WordPress users may bypass the games limit.
* The display name of logged-in users is pre-filled as the player name (overridable).

= 1.2.0 =
* Server-side rule validation (custom PHP engine) - cheating no longer possible.
* Backend settings for the number of simultaneous games and the lifetime.
* Players can choose their own name.

= 1.0.0 =
* First version.
