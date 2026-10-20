# 11. Online-Updater

Heldenhaus läuft auf einem Hetzner-Webhosting, und die App wird außerhalb des öffentlichen Play Stores verteilt. Deshalb braucht es eigene Update-Wege: Server und App sollen sich **ohne SSH-Sitzung und ohne manuelles APK-Kopieren** aktualisieren lassen.

| Kanal | Was wird aktualisiert | Ausgelöst durch |
|---|---|---|
| **Server-Updater** | Laravel-Code, Web-Frontend, Datenbank-Migrationen (aus dem Git-Repository) | Admin-Oberfläche („Update installieren“), ausgeführt vom Scheduler |
| **App-Updater** | Android-App (APK) | Die App prüft beim Start und täglich |
| **Inhalts-Updates** | Spielinhalte (JSON), Spritesheets, Sounds | Automatisch beim Abgleich, ohne App- oder Server-Update (siehe §2.8) |
| **HA-Integration** | `heldenhaus`-Integration | HACS (GitHub-Releases des Integrations-Repos) |

---

## 11.1 Server-Updater (Selbst-Update aus dem Git-Repository)

Der Server-Updater arbeitet wie der des Elterninfoboards: Die Installation ist ein **Git-Checkout** des Server-Repositorys, und ein Update zieht neue Commits per `git pull --ff-only`. Auf dem Server müssen deshalb **Git, Composer und Node/npm** verfügbar sein (`public/build` und `vendor/` sind nicht versioniert).

**Ablauf**:
1. **Prüfen**: `php artisan updater:check` (per Scheduler alle 6 Stunden, Cron-Ausdruck `UPDATER_CHECK_CRON`) holt den Stand des Remotes und vergleicht ihn mit `HEAD`. Die Admin-Seite zeigt neue Commits, Migrationen, geänderte Abhängigkeiten/Assets, lokale Änderungen und lokale Commits.
2. **Anfordern**: Der Admin klickt „Update installieren“. Die Oberfläche merkt das Update nur vor (`storage/app/updater/state.json`) und setzt ein **Bypass-Cookie** für den Wartungsmodus.
3. **Ausführen**: Der Scheduler startet innerhalb einer Minute `updater:run --if-requested` – als CLI-Prozess mit dem Benutzer, dem die Dateien gehören, ohne die Laufzeitgrenzen des Webservers. Jeder Schritt läuft als eigener Prozess:

| Schritt | Inhalt |
|---|---|
| Voraussetzungen | richtiger Branch, `git fetch`, nur Fast-Forward, keine lokal geänderten Dateien, die überschrieben würden |
| Datenbank sichern | `mysqldump` (ohne mysqldump: PHP-Dump) bzw. SQLite-Kopie, gzip, nach `storage/app/updater/backups` (die letzten 5) |
| Wartungsmodus | `artisan down --render=errors::503 --secret=…` |
| `git pull --ff-only` | |
| `composer install --no-dev` | nur bei geänderter `composer.json`/`composer.lock` |
| `npm ci && npm run build` | nur bei geänderten Frontend-Dateien |
| `migrate --force` | |
| Zusatzbefehle | `game:content-sync` (neue Spielinhalte, idempotent) |
| Caches | `config/route/view/event:clear`, danach `optimize` |
| `queue:restart`, `heldenhaus:smoke` | Gesundheitsprüfung (DB, Cache, Spielformeln) |
| Wartungsmodus aus | |

4. **Fortschritt**: Die Admin-Seite fragt alle 3 Sekunden `admin/updates/status` ab und zeigt Schritte und Protokoll live – dank Bypass-Cookie auch im Wartungsmodus.

**Fehlerfall**: Scheitert ein Schritt, bleibt Heldenhaus im Wartungsmodus (`UPDATER_STAY_DOWN_ON_FAILURE`), damit kein halb migrierter Stand online geht. Nach Behebung lässt sich das Update mit „Update-Schritte erneut ausführen“ (`updater:run --full`) wiederholen oder der Wartungsmodus auf der Seite beenden. Ein automatischer Rollback findet nicht statt; die Datenbanksicherung liegt in `storage/app/updater/backups`. Abgestürzte Läufe erkennt der Updater an der freigegebenen Sperrdatei.

**Sicherheit**: Nur `is_admin` darf Updates prüfen und anfordern. Quelle ist ausschließlich das konfigurierte Git-Remote; das Bypass-Geheimnis taucht weder im Status noch im Protokoll auf. Abschalten mit `UPDATER_ENABLED=false`.

---

## 11.2 App-Updater (Android)

Es gibt zwei Wege, abhängig von der Verteilung (Build-Flavor):

| Verteilung | Update-Weg |
|---|---|
| **Signierte APK** (Familie + Bekannte, Standard) | Eigener Updater in der App, die APKs liefert der Heldenhaus-Server aus |
| **Geschlossener Play-Store-Test** | Google Play In-App Updates API (Paket `in_app_update`), flexibles bzw. sofortiges Update |

**APK-Updater – Ablauf**:
1. Die App ruft beim Start und einmal täglich `GET /api/v1/app/version?channel=stable&build=123` auf. Antwort: `latest` (Version und Build), `min_supported`, `changelog`, `size`, `sha256` und `download_url` (signierte URL, 30 min gültig, nur für angemeldete Geräte).
2. **Optionales Update**: Ein Hinweis mit kindgerechtem Changelog („Neu: Haustiere können jetzt schlafen!“) und den Knöpfen „Jetzt“ / „Später“. Auf Familiengeräten kann ein Elternteil festlegen, dass Updates automatisch heruntergeladen werden.
3. **Pflicht-Update**: Ist der Build kleiner als `min_supported` (z. B. bei einer API-Änderung), antwortet jede API-Anfrage mit `426 Upgrade Required`, und die App zeigt nur noch den Update-Bildschirm. Offline gesammelte Aktionen bleiben in der Warteschlange erhalten und werden nach dem Update übertragen.
4. **Download** mit Fortschrittsanzeige (dio, fortsetzbar), danach **SHA-256-Prüfung**
5. **Installation** über den Android-PackageInstaller (Berechtigung `REQUEST_INSTALL_PACKAGES`). Beim ersten Mal führt die App kindgerecht durch die Freigabe „Unbekannte Apps installieren“. Android prüft zusätzlich selbst, ob das Update mit demselben Schlüssel signiert ist.

**Bereitstellung**: GitHub Actions bauen die signierte Release-APK (Keystore als Secret) und laden sie mit Changelog über die Admin-API `POST /api/v1/admin/app-releases` hoch. Alternativ geht das per Upload im Admin-Bereich. Kanäle `stable` / `beta`: Einzelne Geräte (z. B. das eines Elternteils) können Beta-Versionen erhalten.

**Kompatibilität zwischen Server und App**: Grundsatz: Die API `/v1` bleibt abwärtskompatibel, Pflicht-Updates sind die Ausnahme.

---

## 11.3 Datenmodell & API

- Server-Updater: keine Tabelle – Status, Prüfergebnis, Protokolle und Sicherungen liegen als Dateien unter `storage/app/updater` (lesbar auch während Migrationen)
- `app_releases`: `version_name`, `version_code`, Kanal, `apk_path`, `sha256`, Größe, Changelog, `min_supported_code`, `published_at`
- `devices` (bzw. `device_tokens`) bekommt `app_build` und `channel`: Dadurch sieht man, welches Familiengerät veraltet ist.
- Admin-Web: `GET admin/updates`, `GET admin/updates/status`, `POST admin/updates/check`, `POST admin/updates/start` (`full` = alle Schritte), `POST admin/updates/cancel`, `POST admin/updates/up`
- App-API: `GET app/version`, `GET app/download/{release}` (signiert), Admin: `POST admin/app-releases`

## 11.4 Tests

- Server-Updater: nur Admins, Anfordern/Zurücknehmen, Bypass-Cookie passt zum Geheimnis, Geheimnis nicht im Status, abgestürzte Läufe gelten als fehlgeschlagen, `updater:run --if-requested` tut ohne Anforderung nichts
- Versionslogik der App: Kanäle, `min_supported` → `426`
- App: Unit-Tests für Versionsvergleich und SHA-Prüfung, Integrationstest gegen Staging mit einer Test-APK
- Probelauf auf Staging vor jedem Produktiv-Update. Das Staging-Update läuft über denselben Updater.
