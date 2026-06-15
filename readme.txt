=== Chess Duell ===
Contributors: willnat
Tags: chess, schach, game, multiplayer, spiel
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Zwei Menschen spielen online Schach gegeneinander – Partie einfach per Link teilen.

== Beschreibung ==

Chess Duell stellt ein vollständiges Online-Schach für zwei menschliche Spieler bereit.
Es gibt keine KI – nur die kompletten Schachregeln.

Funktionen:

* Mensch gegen Mensch über einen geteilten Link (Hash in der URL: ?chess_game=...)
* Wer die Partie erstellt, spielt Weiß; der zweite Besucher wird automatisch Schwarz.
  Weitere Besucher sehen die Partie als Zuschauer.
* Vollständige Schachregeln: Rochade, En passant, Bauernumwandlung,
  Schach/Schachmatt/Patt, 50-Züge-Regel, unzureichendes Material.
* Bis zu 10 gleichzeitige Partien.
* Eine Partie endet nur durch Matt, Aufgabe oder Remis – oder verfällt nach
  14 Tagen ohne Aktivität.
* Synchronisation über die WordPress-REST-API (Polling, alle 2 Sekunden).
* Keine externen Abhängigkeiten (keine CDN, keine Bilder) – Unicode-Figuren.

== Installation ==

1. Plugin-ZIP unter "Plugins > Installieren > Plugin hochladen" hochladen und aktivieren.
2. Auf einer Seite oder in einem Beitrag den Shortcode einfügen:

   [chess_duell]

3. Die Seite öffnen, "Neue Partie starten" klicken und den angezeigten Link
   an den Gegner schicken.

== Hinweise ==

Die Zugregeln werden im Browser (JavaScript) geprüft; der Server speichert die
Partien und sorgt dafür, dass nur die Seite zieht, die am Zug ist. Für ein
freundschaftliches Spiel ist das ausreichend. Spieler-Identitäten werden über
ein geheimes Token im Link/Local-Storage des Browsers verwaltet.

== Changelog ==

= 1.0.0 =
* Erste Version.
