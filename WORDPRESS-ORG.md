# Veröffentlichung auf wordpress.org

Dieser Branch (`wporg`) ist der veröffentlichungsfertige Stand **ohne** den
GitHub-Updater. Die `main`-Branch behält den GitHub-Updater für die
Eigen-Distribution.

Diese Datei sowie `README.md` und `.gitattributes` gehören **nicht** ins
Einreich-/Deploy-Paket – sie sind reine Entwicklungs-Dateien.

## Inhalt des Plugins (das, was ausgeliefert wird)

```
chess-duell.php
readme.txt
assets/css/chess-duell.css
assets/js/app.js
assets/js/engine.js
includes/class-chess-engine.php
```

(Kein `includes/class-github-updater.php` – wurde in diesem Branch entfernt.)

## 1. Einreichen

1. Auf https://wordpress.org/plugins/developers/add/ mit dem wordpress.org-Account
   anmelden und das Plugin-ZIP (`chess-duell-wporg.zip`) hochladen.
2. `readme.txt` vorher mit dem Validator prüfen:
   https://wordpress.org/plugins/developers/readme-validator/
3. Manuelles Review abwarten und Rückfragen beantworten.
   - `Contributors:` in der readme.txt muss der/die echte wordpress.org-Benutzername(n) sein.

## 2. Nach Freigabe: Deploy via SVN

Nach Freigabe gibt es ein SVN-Repo: `https://plugins.svn.wordpress.org/chess-duell/`

```
svn co https://plugins.svn.wordpress.org/chess-duell/ chess-duell-svn
cd chess-duell-svn
# Plugin-Dateien (siehe Liste oben) nach trunk/ kopieren
svn add trunk/* --force
# Release taggen (Version muss zu "Stable tag" in readme.txt passen):
svn cp trunk tags/1.5.1
svn ci -m "Release 1.5.1"
```

`Stable tag` in `readme.txt` zeigt auf die ausgelieferte Version.

## 3. Grafiken (gehören nach /assets im SVN, NICHT ins Plugin-ZIP)

Im SVN-Ordner `assets/` ablegen:

- Icon: `icon-128x128.png` (128×128) und `icon-256x256.png` (256×256)
- Banner: `banner-772x250.png` (772×250) und `banner-1544x500.png` (1544×500)
- Screenshots: `screenshot-1.png`, `screenshot-2.png`, `screenshot-3.png`
  (Reihenfolge/Beschreibung entspricht dem Abschnitt "== Screenshots ==" in readme.txt)

```
svn add assets/*
svn ci -m "Assets"
```

## Updates künftig

Code in `trunk/` aktualisieren, `Stable tag` + Changelog in `readme.txt` erhöhen,
neuen Tag unter `tags/<version>/` anlegen, committen. Das offizielle
WordPress-Update-System übernimmt die Auslieferung (kein GitHub-Updater nötig).
