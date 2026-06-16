=== Chess Duell ===
Contributors: willnat
Tags: chess, schach, game, multiplayer, spiel
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.6.1
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
* Optionale Schachuhr beim Erstellen der Partie. Es zählt nur die Online-Zeit
  des Spielers am Zug – ist jemand offline, pausiert seine Uhr (keine über Nacht
  verbrauchte Zeit). Bei Zeitüberschreitung verliert der Spieler.
* Optionale E-Mail-Benachrichtigung, wenn man am Zug ist (bei angemeldeten
  Nutzern mit hinterlegter Adresse vorausgefüllt). Die Adresse wird nur für die
  Benachrichtigung verwendet und mit dem Spielende automatisch gelöscht – nicht
  dauerhaft gespeichert (technisch im Server-Log sichtbar).
* Updates unterbrechen laufende Partien nicht (abwärtskompatible Datensätze).
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

= 1.6.1 =
* Fix GitHub-Updater: Updates ersetzen jetzt zuverlässig den vorhandenen Plugin-Ordner, statt einen zweiten anzulegen (verhindert doppelte Installationen / Aktivierungsfehler).

= 1.6.0 =
* Backend: Übersicht aller laufenden/gespeicherten Partien mit Möglichkeit, einzelne Partien zu löschen (und alle beendeten auf einmal).
* Backend: Der Limit-Bypass für angemeldete Nutzer ist jetzt optional ein-/ausschaltbar.

= 1.5.7 =
* Die "Du bist am Zug"-E-Mail enthält jetzt den zuletzt gezogenen Zug.

= 1.5.6 =
* Code-Qualität: GET-Eingabe im Shortcode abgesichert (wp_unslash + sanitize).

= 1.5.5 =
* "Tested up to" auf aktuelle WordPress-Version angehoben; kleinere Aufräumarbeiten.

= 1.5.4 =
* Fix: Konfiguration (REST-URL) wird zusätzlich als data-Attribut übergeben – funktioniert jetzt auch bei Content-Security-Policy oder Skript-Optimierern, die Inline-Skripte entfernen.

= 1.5.3 =
* Fix: Polling funktioniert jetzt auch mit einfachen (Plain-)Permalinks (?rest_route=).

= 1.5.2 =
* Plugin-Website (Admin) zeigt auf das GitHub-Repository.

= 1.5.1 =
* Letzter Zug wird hervorgehoben: Start-/Zielfeld auf dem Brett und der Eintrag in der Zugliste.

= 1.5.0 =
* Automatische Update-Benachrichtigung im Dashboard über GitHub-Releases.

= 1.4.0 =
* Optionale Schachuhr (zählt nur Online-Zeit, pausiert bei Abwesenheit).
* Optionale E-Mail-Benachrichtigung "Du bist am Zug"; Adresse wird mit Spielende gelöscht.
* Abwärtskompatibel: laufende Partien überstehen das Update unverändert.

= 1.3.0 =
* Angemeldete WordPress-Nutzer dürfen das Partien-Limit übergehen.
* Anzeigename angemeldeter Nutzer wird als Spielername vorausgefüllt (überschreibbar).

= 1.2.0 =
* Serverseitige Regelprüfung (eigene PHP-Engine) – Cheaten nicht mehr möglich.
* Backend-Einstellungen für Anzahl gleichzeitiger Partien und Laufzeit.
* Spieler können einen eigenen Namen vergeben.

= 1.0.0 =
* Erste Version.
