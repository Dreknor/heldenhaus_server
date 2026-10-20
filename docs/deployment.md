# Deployment auf Hetzner Webhosting

Heldenhaus läuft auf einem Hetzner-Webhosting-Tarif mit **SSH und Cronjobs**. Es gibt keine Dauerprozesse: Queue und Scheduler laufen über einen minütlichen Cronjob. Die Installation ist ein Git-Checkout, Updates spielt der [Online-Updater](konzept/10-online-updater.md) aus dem Repository ein.

## 1. Voraussetzungen in der konsoleH

- **PHP 8.3 oder neuer** für die Domain (Erweiterungen: `pdo_mysql`, `zip`, `mbstring`, `intl`)
- **MariaDB/MySQL-Datenbank** anlegen und die Zugangsdaten notieren
- **SSH-Zugang** aktivieren
- **Git, Composer und Node/npm** auf der Kommandozeile (Composer notfalls als `composer.phar` im Home-Verzeichnis, Node z. B. per `nvm`)
- **E-Mail-Postfach** für den Versand (z. B. `heldenhaus@deine-domain.de`)
- Prüfen, welcher PHP-CLI-Befehl zur Web-Version passt, z. B. `php83` oder `/usr/bin/php8.3` (auf dem Webhosting gibt es oft mehrere). Er wird unten als `$PHP` bezeichnet.

## 2. Repository auschecken (einmalig per SSH)

```bash
git clone https://github.com/Dreknor/heldenhaus_server.git ~/heldenhaus
cd ~/heldenhaus
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

Bei einem privaten Repository einen Deploy-Key (nur Lesezugriff) hinterlegen und per SSH-URL klonen.

## 3. Konfiguration

`~/heldenhaus/.env` aus [`.env.production.example`](../.env.production.example) erstellen und ausfüllen, danach:

```bash
cd ~/heldenhaus
$PHP artisan key:generate
$PHP artisan migrate --force
$PHP artisan storage:link
$PHP artisan optimize
$PHP artisan heldenhaus:create-family "Familie Muster" "Mama" "mama@example.de"
```

Optional: gemalte Hintergründe für die Heldenszene. Das Paket [Fantasy Forest Backgrounds von Lornn](https://lornn.itch.io/fantasy-forest-backgrounds) darf genutzt, aber nicht weitergegeben werden und liegt deshalb nicht im Repository. Am einfachsten im Admin-Bereich unter **System-Updates → Szenen-Hintergründe** das ZIP hochladen; es wird in kleinen Stücken übertragen (passt zu jeder `upload_max_filesize`) und anschließend portionsweise entpackt. Alternativ per SSH:

```bash
$PHP artisan heldenhaus:import-backgrounds ~/Fantasy\ Forests.zip
```

Innenräume werden ausgelassen, die Bilder landen verkleinert in `storage/app/public/scenes` (dafür `storage:link`) und erscheinen automatisch unter `/credits`.

Ohne Import zeigt die Szene die mitgelieferte Fluent-Landschaft.

## 4. Domain auf `public` zeigen lassen

In der konsoleH unter *Einstellungen → Domain* das Verzeichnis auf `/heldenhaus/public` setzen. HTTPS (Let's Encrypt) aktivieren.

## 5. Cronjob

In der konsoleH unter *Cronjobs* einen Job jede Minute (bzw. das kleinste erlaubte Intervall):

```
$PHP ~/heldenhaus/artisan schedule:run
```

Der Scheduler arbeitet die Queue ab, führt ausstehende Tageswechsel aus, bestätigt abgelaufene Freigaben, sucht alle 6 Stunden nach Updates und führt angeforderte Updates aus.

## 6. Online-Updater

1. In `.env` mindestens `UPDATER_PHP_BINARY=$PHP` setzen; liegen Composer, npm oder mysqldump nicht im `PATH` des Cronjobs, auch `UPDATER_COMPOSER_BINARY`, `UPDATER_NPM_BINARY` und `UPDATER_MYSQLDUMP_BINARY` (ohne mysqldump sichert der Updater per PHP).
2. Neue Version veröffentlichen: auf den Branch pushen, von dem der Server aktualisiert (Standard: Upstream des ausgecheckten Branches, sonst `UPDATER_BRANCH`).
3. Im Admin-Bereich unter **System-Updates** auf „Jetzt prüfen“ und „Update installieren“ klicken. Der Cronjob startet das Update innerhalb einer Minute; der Fortschritt erscheint live, auch im Wartungsmodus.
4. Per SSH geht dasselbe direkt: `$PHP ~/heldenhaus/artisan updater:check` und `$PHP ~/heldenhaus/artisan updater:run`.

Bei Fehlern bleibt Heldenhaus im Wartungsmodus. Ursache im Protokoll auf der Update-Seite (bzw. `storage/app/updater/logs`) ansehen, beheben und „Update-Schritte erneut ausführen“ (`updater:run --full`) – oder den Wartungsmodus auf der Seite bzw. mit `$PHP artisan up` beenden. Die Datenbanksicherungen liegen in `storage/app/updater/backups`.

**Umstieg von der früheren Release-Struktur** (`releases/`, `shared/`, `current`): das alte Verzeichnis nach `~/heldenhaus-alt` umbenennen, das Repository wie in Abschnitt 2 auschecken, `heldenhaus-alt/shared/.env` nach `~/heldenhaus/.env` und den Inhalt von `heldenhaus-alt/shared/storage` nach `~/heldenhaus/storage` kopieren, Domain auf `/heldenhaus/public` umstellen und den Cronjob auf `~/heldenhaus/artisan` ändern.

## 7. Staging

Eine Subdomain (z. B. `staging.deine-domain.de`) mit eigenem Checkout `~/heldenhaus-staging/` und eigener Datenbank. Dort mit `UPDATER_BRANCH` z. B. einen `develop`-Branch verfolgen und Updates zuerst einspielen.
