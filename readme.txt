=== Chess Duell ===
Contributors: strychni0x
Tags: chess, schach, game, multiplayer, spiel
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Zwei Menschen spielen online Schach gegeneinander – Partie einfach per Link teilen. Vollständige Regeln, serverseitig geprüft, keine KI.

== Description ==

Chess Duell stellt ein vollständiges Online-Schach für zwei menschliche Spieler bereit.
Es gibt keine KI – nur die kompletten Schachregeln. Einbinden mit dem Shortcode `[chess_duell]`.

Funktionen:

* Mensch gegen Mensch über einen geteilten Link (Hash in der URL: ?chess_game=...).
* Wer die Partie erstellt, spielt Weiß; der zweite Besucher wird automatisch Schwarz.
  Weitere Besucher sehen die Partie als Zuschauer.
* Spieler können sich einen eigenen Namen geben. Bei angemeldeten WordPress-Nutzern
  ist der Anzeigename vorausgefüllt (überschreibbar).
* Angemeldete WordPress-Nutzer (jede Rolle) dürfen das Limit gleichzeitiger Partien übergehen.
* Vollständige Schachregeln: Rochade, En passant, Bauernumwandlung,
  Schach/Schachmatt/Patt, 50-Züge-Regel, unzureichendes Material.
* Serverseitige Regelprüfung: Jeder Zug wird auf dem Server validiert (eigene PHP-Engine).
  Cheaten über einen manipulierten Browser ist nicht möglich.
* Im Backend einstellbar: Anzahl gleichzeitiger Partien und Laufzeit bis zum
  automatischen Ende (Einstellungen > Chess Duell).
* Der letzte Zug wird hervorgehoben (Start-/Zielfeld auf dem Brett und in der Zugliste).
* Optionale Schachuhr beim Erstellen der Partie. Es zählt nur die Online-Zeit des
  Spielers am Zug – ist jemand offline, pausiert seine Uhr. Bei Zeitüberschreitung
  verliert der Spieler.
* Optionale E-Mail-Benachrichtigung, wenn man am Zug ist (bei angemeldeten Nutzern
  mit hinterlegter Adresse vorausgefüllt).
* Keine externen Abhängigkeiten (keine CDN, keine Bilder, keine externen Aufrufe) –
  Unicode-Figuren.

== Installation ==

1. Plugin im Verzeichnis suchen und installieren – oder ZIP unter
   "Plugins > Installieren > Plugin hochladen" hochladen. Danach aktivieren.
2. Auf einer Seite oder in einem Beitrag den Shortcode einfügen:

   `[chess_duell]`

3. Die Seite öffnen, "Neue Partie starten" klicken und den angezeigten Link
   an den Gegner schicken.

== Frequently Asked Questions ==

= Braucht es einen Account oder eine Anmeldung? =

Nein. Jeder Besucher kann eine Partie starten und per Link teilen. Angemeldete
WordPress-Nutzer haben Komfort-Vorteile (Name/E-Mail vorausgefüllt, kein Partien-Limit).

= Wie werden Spielzüge überprüft? =

Jeder Zug wird serverseitig mit einer eigenen PHP-Schach-Engine auf Legalität
geprüft. Der Server berechnet Stellung (FEN), Notation (SAN) und das Spielende selbst
und ist damit die einzige Wahrheit. Ein manipulierter Client kann keinen illegalen
Zug durchsetzen.

= Was passiert mit der E-Mail-Adresse für Benachrichtigungen? =

Sie ist optional. Wird eine Adresse angegeben, verwendet das Plugin sie ausschließlich,
um den Spieler per WordPress-Mail (wp_mail) zu benachrichtigen, wenn er am Zug ist.
Die Adresse wird mit dem Spielende automatisch gelöscht und nicht dauerhaft gespeichert.
Es werden keine Daten an Dritte oder externe Dienste übertragen.

= Wo werden die Partien gespeichert? =

In der WordPress-Datenbank (Option des Plugins). Eine Partie endet durch Matt,
Aufgabe oder Remis oder verfällt nach der im Backend eingestellten Inaktivitätsdauer
(Standard 14 Tage).

== Screenshots ==

1. Laufende Partie mit hervorgehobenem letztem Zug, Statusleiste und Zugliste.
2. Lobby beim Erstellen einer Partie: Name, Schachuhr und optionale E-Mail-Benachrichtigung.
3. Backend-Einstellungen: Anzahl gleichzeitiger Partien und Laufzeit.

== Changelog ==

= 1.5.3 =
* Fix: polling now also works with plain permalinks (?rest_route=).

= 1.5.2 =
* Plugin website (admin) now points to the GitHub repository.

= 1.5.1 =
* Der letzte Zug wird hervorgehoben: Start-/Zielfeld auf dem Brett und der Eintrag in der Zugliste.

= 1.5.0 =
* Wartungsrelease und interne Anpassungen.

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

== Upgrade Notice ==

= 1.5.1 =
Der letzte Zug wird jetzt auf dem Brett und in der Zugliste hervorgehoben.
