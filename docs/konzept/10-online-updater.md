# 11. Online-Updater

Heldenhaus läuft auf einem Hetzner-Webhosting, und die App wird außerhalb des öffentlichen Play Stores verteilt. Deshalb braucht es eigene Update-Wege: Server und App sollen sich **ohne SSH-Sitzung und ohne manuelles APK-Kopieren** aktualisieren lassen.

| Kanal | Was wird aktualisiert | Ausgelöst durch |
|---|---|---|
| **Server-Updater** | Laravel-Code, Web-Frontend, Datenbank-Migrationen | Admin-Oberfläche („Update installieren“), optional automatisch nachts |
| **App-Updater** | Android-App (APK) | Die App prüft beim Start und täglich |
| **Inhalts-Updates** | Spielinhalte (JSON), Spritesheets, Sounds | Automatisch beim Abgleich, ohne App- oder Server-Update (siehe §2.8) |
| **HA-Integration** | `heldenhaus`-Integration | HACS (GitHub-Releases des Integrations-Repos) |

---

## 11.1 Server-Updater (Selbst-Update auf dem Webhosting)

**Release-Paket**: GitHub Actions bauen bei jedem Tag `vX.Y.Z` ein **fertiges Paket** `heldenhaus-X.Y.Z.zip`. Es enthält `vendor/` und die gebauten Assets, auf dem Server ist also weder Composer noch Node nötig. Dazu kommen:
- `manifest.json`: Version, Kanal (`stable`/`beta`), minimale PHP-Version, nötige Erweiterungen, SHA-256 des Pakets, Changelog, Hinweis „enthält Migrationen“
- `manifest.sig`: **Ed25519-Signatur** des Manifests (libsodium ist in PHP enthalten). Der öffentliche Schlüssel liegt in `config/updater.php`, der private nur als GitHub-Secret.

**Verzeichnisstruktur** (identisch mit dem GitHub-Actions-Deployment, beide Wege sind kompatibel):
```
~/heldenhaus/
  releases/1.4.0/  releases/1.5.0/      ← höchstens 3 Versionen bleiben erhalten
  shared/.env  shared/storage/          ← werden per Symlink eingebunden
  current -> releases/1.5.0             ← Document Root zeigt auf current/public
  backups/                              ← DB-Sicherungen vor jedem Update
```

**Ablauf**:
1. **Prüfen**: Ein täglicher Scheduler-Job fragt die GitHub-Releases-API ab (für ein privates Repo mit Token). Eine neue Version erscheint als Hinweis im Admin-Bereich mit Changelog, Admins bekommen optional eine E-Mail.
2. **Vorabprüfung**: PHP-Version, Erweiterungen, freier Speicherplatz, Schreibrechte, laufende Queue-Jobs.
3. **Herunterladen und prüfen**: Das Paket wird geladen, Signatur und SHA-256 werden geprüft. Stimmt etwas nicht, bricht der Vorgang ohne Änderung ab.
4. **Entpacken** nach `releases/X.Y.Z`, `.env` und `storage` werden per Symlink eingebunden.
5. **Datenbank sichern**: SQL-Dump nach `backups/` (PHP-basiert, da `mysqldump` nicht immer verfügbar ist).
6. **Wartungsmodus** (`artisan down` mit Secret-Bypass für Admins und kindgerechter Wartungsseite)
7. **Migrationen** mit `migrate --force` im neuen Release, danach `optimize`
8. **Umschalten**: Der Symlink `current` wird atomar auf das neue Release gesetzt.
9. **Gesundheitsprüfung**: `/up` sowie ein internes Smoke-Test-Kommando (DB, Cache, Queue, Spielformeln)
10. **Wartungsmodus aus**. Ein Feed-Eintrag für Admins meldet z. B. „Heldenhaus wurde auf 1.5.0 aktualisiert“.

**Fehlerfall und Rollback**: Scheitert ein Schritt ab 7, wird `current` auf das vorherige Release zurückgesetzt. Bei Fehlern in der Migration wird zusätzlich die DB-Sicherung eingespielt. Dafür gilt die **Migrationsregel**: nur vorwärtskompatible Migrationen. Spalten werden erst in einer späteren Version entfernt, sodass die Vorversion mit dem neuen Schema weiterläuft. Ein Rollback ist außerdem per Knopfdruck auf jede der aufbewahrten Versionen möglich.

**Grenzen des Webhostings**: Die PHP-Laufzeit pro Anfrage ist begrenzt. Der Updater ist deshalb ein **Zustandsautomat** (Tabelle `system_updates`). Jeder Schritt ist eine eigene, kurze Anfrage: Die Admin-Seite ruft die Schritte nacheinander auf und zeigt den Fortschritt live. Alternativ geht dasselbe per SSH oder Cron mit `php artisan heldenhaus:update [--version=] [--channel=]`.

**Automatik (optional)**: Patch-Versionen (`X.Y.*`) auf dem Kanal `stable` können sich nachts um 03:00 selbst installieren, aber nur ohne Migrationen. Größere Versionen brauchen immer einen Klick vom Admin.

**Sicherheit**: Nur `is_admin` darf Updates auslösen. Jede Aktion landet im Prüfprotokoll. Downloads gehen nur von der konfigurierten Release-Quelle aus, und ohne gültige Signatur gibt es keine Installation.

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

**Kompatibilität zwischen Server und App**: Server-Releases geben im Manifest `min_app_build` an. Der Server-Updater warnt, wenn ein Server-Update ältere Apps aussperren würde. Grundsatz: Die API `/v1` bleibt abwärtskompatibel, Pflicht-Updates sind die Ausnahme.

---

## 11.3 Datenmodell & API

- `system_updates`: Version, Kanal, Status (`pending|downloading|verifying|migrating|switching|done|failed|rolled_back`), aktueller Schritt, Log (JSON), `started_by`, `backup_path`, Zeitstempel
- `app_releases`: `version_name`, `version_code`, Kanal, `apk_path`, `sha256`, Größe, Changelog, `min_supported_code`, `published_at`
- `devices` (bzw. `device_tokens`) bekommt `app_build` und `channel`: Dadurch sieht man, welches Familiengerät veraltet ist.
- Admin-Web: `GET admin/updates`, `POST admin/updates/check`, `POST admin/updates/{version}/step`, `POST admin/updates/rollback/{version}`
- App-API: `GET app/version`, `GET app/download/{release}` (signiert), Admin: `POST admin/app-releases`

## 11.4 Tests

- Signaturprüfung: Ein manipuliertes Manifest oder Paket wird abgelehnt (Ed25519 mit Test-Schlüsselpaar).
- Zustandsautomat: Jeder Schritt ist idempotent und kann nach einem Abbruch fortgesetzt werden. Ein Fehler ab Schritt 7 löst den Rollback aus (Dateisystem mit `Storage::fake` bzw. temporären Verzeichnissen).
- Versionslogik: Kanäle, Erkennung von Patch-Versionen für die Automatik, `min_supported` → `426`
- App: Unit-Tests für Versionsvergleich und SHA-Prüfung, Integrationstest gegen Staging mit einer Test-APK
- Probelauf auf Staging vor jedem Produktiv-Update. Das Staging-Update läuft über denselben Updater.
