# Deployment auf Hetzner Webhosting

Heldenhaus läuft auf einem Hetzner-Webhosting-Tarif mit **SSH und Cronjobs**. Es gibt keine Dauerprozesse: Queue und Scheduler laufen über einen minütlichen Cronjob, Updates über signierte Release-Pakete (siehe [Online-Updater](konzept/10-online-updater.md)).

## 1. Voraussetzungen in der konsoleH

- **PHP 8.3 oder neuer** für die Domain (Erweiterungen: `pdo_mysql`, `sodium`, `zip`, `mbstring`, `intl`)
- **MariaDB/MySQL-Datenbank** anlegen und die Zugangsdaten notieren
- **SSH-Zugang** aktivieren
- **E-Mail-Postfach** für den Versand (z. B. `heldenhaus@deine-domain.de`)
- Prüfen, welcher PHP-CLI-Befehl zur Web-Version passt, z. B. `php83` oder `/usr/bin/php8.3` (auf dem Webhosting gibt es oft mehrere). Er wird unten als `$PHP` bezeichnet.

## 2. Verzeichnisstruktur anlegen (einmalig per SSH)

```bash
mkdir -p ~/heldenhaus/{releases,shared/storage,backups,packages}
cd ~/heldenhaus/shared/storage
mkdir -p app/public app/private framework/{cache/data,sessions,views} logs
```

Erstes Release-Paket hochladen (GitHub-Release → `heldenhaus-X.Y.Z.zip`) und entpacken:

```bash
cd ~/heldenhaus
unzip packages/heldenhaus-0.1.0.zip -d releases/0.1.0
rm -rf releases/0.1.0/storage
ln -s ~/heldenhaus/shared/storage releases/0.1.0/storage
ln -s ~/heldenhaus/shared/.env releases/0.1.0/.env
ln -sfn ~/heldenhaus/releases/0.1.0 current
```

## 3. Konfiguration

`~/heldenhaus/shared/.env` aus [`.env.production.example`](../.env.production.example) erstellen und ausfüllen, danach:

```bash
cd ~/heldenhaus/current
$PHP artisan key:generate
$PHP artisan migrate --force
$PHP artisan storage:link
$PHP artisan optimize
$PHP artisan heldenhaus:create-family "Familie Muster" "Mama" "mama@example.de"
```

## 4. Domain auf `current/public` zeigen lassen

In der konsoleH unter *Einstellungen → Domain* das Verzeichnis auf `/heldenhaus/current/public` setzen. HTTPS (Let's Encrypt) aktivieren.

## 5. Cronjob

In der konsoleH unter *Cronjobs* einen Job jede Minute (bzw. das kleinste erlaubte Intervall):

```
$PHP ~/heldenhaus/current/artisan schedule:run
```

Der Scheduler arbeitet die Queue ab, führt ausstehende Tageswechsel aus, bestätigt abgelaufene Freigaben und installiert (falls aktiviert) nachts Patch-Updates.

## 6. Online-Updater einrichten

1. Lokal ein Schlüsselpaar erzeugen: `php artisan heldenhaus:updater-keygen`
2. Den **geheimen** Schlüssel als GitHub-Secret `UPDATER_SECRET_KEY` speichern
3. Den **öffentlichen** Schlüssel in `shared/.env` als `UPDATER_PUBLIC_KEY` eintragen, dazu `UPDATER_GITHUB_REPOSITORY` (und bei privatem Repo `UPDATER_GITHUB_TOKEN` mit Leserechten auf Releases) sowie `UPDATER_PHP_BINARY=$PHP`
4. Neue Version veröffentlichen: `git tag v0.2.0 && git push --tags`. GitHub Actions testet, baut und signiert das Paket.
5. Im Admin-Bereich unter **System-Updates** auf „Nach Updates suchen“ und „Update installieren“ klicken – oder per SSH: `$PHP ~/heldenhaus/current/artisan heldenhaus:update`

Bei Problemen: im Admin-Bereich auf ein früheres Release zurückschalten. Den Wartungsmodus beendet bei Bedarf `$PHP artisan up`.

## 7. Staging

Eine Subdomain (z. B. `staging.deine-domain.de`) mit eigener Struktur `~/heldenhaus-staging/` und eigener Datenbank. Updates zuerst dort installieren (`UPDATER_CHANNEL=beta` für Vorabversionen).
