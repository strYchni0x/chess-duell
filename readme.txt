=== Chess Duell ===
Contributors: willnat
Tags: chess, schach, game, multiplayer, spiel
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.3.0
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
* Spieler können sich einen eigenen Namen geben. Bei angemeldeten WordPress-
  Nutzern ist der Anzeigename vorausgefüllt (überschreibbar).
* Angemeldete WordPress-Nutzer (jede Rolle) dürfen das Limit gleichzeitiger
  Partien übergehen.
* Vollständige Schachregeln: Rochade, En passant, Bauernumwandlung,
  Schach/Schachmatt/Patt, 50-Züge-Regel, unzureichendes Material.
* Serverseitige Regelprüfung: Jeder Zug wird auf dem Server validiert
  (eigene PHP-Engine). Cheaten über einen manipulierten Browser ist nicht möglich.
* Im Backend einstellbar: Anzahl gleichzeitiger Partien und Laufzeit bis zum
  automatischen Ende (Einstellungen > Chess Duell).
* Eine Partie endet nur durch Matt, Aufgabe oder Remis – oder verfällt nach
  der eingestellten Inaktivitätsdauer (Standard 14 Tage).
* Synchronisation über die WordPress-REST-API (Polling, alle 2 Sekunden).
* Keine externen Abhängigkeiten (keine CDN, keine Bilder) – Unicode-Figuren.

== Installation ==

1. Plugin-ZIP unter "Plugins > Installieren > Plugin hochladen" hochladen und aktivieren.
2. Auf einer Seite oder in einem Beitrag den Shortcode einfügen:

   [chess_duell]

3. Die Seite öffnen, "Neue Partie starten" klicken und den angezeigten Link
   an den Gegner schicken.

== Hinweise ==

Jeder Zug wird serverseitig mit einer eigenen PHP-Schach-Engine auf Legalität
geprüft; der Server berechnet Stellung (FEN), Notation (SAN) und Spielende selbst
und ist damit die einzige Wahrheit. Ein manipulierter Client kann keinen
illegalen Zug durchsetzen. Spieler-Identitäten werden über ein geheimes Token
im Link/Local-Storage des Browsers verwaltet.

== Changelog ==

= 1.3.0 =
* Angemeldete WordPress-Nutzer dürfen das Partien-Limit übergehen.
* Anzeigename angemeldeter Nutzer wird als Spielername vorausgefüllt (überschreibbar).

= 1.2.0 =
* Serverseitige Regelprüfung (eigene PHP-Engine) – Cheaten nicht mehr möglich.
* Backend-Einstellungen für Anzahl gleichzeitiger Partien und Laufzeit.
* Spieler können einen eigenen Namen vergeben.

= 1.0.0 =
* Erste Version.
