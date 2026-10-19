# 5. Systemarchitektur

## 5.1 Auf Hetzner Webhosting zugeschnitten
| Bedarf | Lösung ohne Dauerprozesse |
|---|---|
| Scheduler | konsoleH-Cron jede Minute (bzw. kleinstes erlaubtes Intervall) → `php artisan schedule:run` |
| Queue | Treiber `database`. Der Scheduler startet `queue:work --stop-when-empty --max-time=50` (`withoutOverlapping`). |
| Cache/Sessions | `database` (Redis ist nicht verfügbar) |
| Echtzeit | Keine WebSockets. Web nutzt Inertia-Polling (30 s, nur sichtbarer Tab), die App FCM-Datennachrichten, die einen Abgleich auslösen. |
| Push | Firebase Cloud Messaging HTTP v1 (`kreait/laravel-firebase`), ausgehendes HTTPS ist erlaubt |
| Dateien | `storage/app/private`, Auslieferung über signierte URLs |
| Document Root | Domain in konsoleH auf `…/current/public` zeigen lassen |
| PHP CLI | Explizit den passenden Binary-Pfad nutzen (CLI-Version kann von der Web-Version abweichen) |
| E-Mail | Hetzner-SMTP für Einladungen und Wochenbericht |

**Deployment**: GitHub Actions bauen das Artefakt (`composer install --no-dev`, `npm run build`) und übertragen es per rsync/SSH in den Ordner `releases/<timestamp>`. Danach laufen `artisan migrate --force` und `optimize`, der Symlink `current` wird umgehängt (Zero-Downtime, z. B. mit Deployer). Composer/Node laufen **nicht** auf dem Server. Eine Staging-Subdomain mit eigener DB dient zum Testen.

**Online-Updater**: Zusätzlich zum CI-Deployment kann sich der Server über die Admin-Oberfläche selbst aktualisieren (signierte Release-Pakete, DB-Sicherung, Rollback), siehe §11.

**Ausweg bei Wachstum**: Wenn Echtzeit nötig wird, kann auf einen kleinen Hetzner-Cloud-Server (Reverb, Redis) migriert werden, ohne Code umzubauen (nur Treiberwechsel in `.env`).

## 5.2 Laravel-Struktur (Domänen-Module)
```
app/
  Domain/
    Family/         Familien, Mitglieder, Einladungen, Kopplungscodes, Bündnisse
    Tasks/          Tasks, Wiederholungsregeln, Rotation, Freigaben, Nachweise
    Gameplay/       Scoring-Engine, Cron, Stats, Level, Klassen, Fähigkeiten
    Inventory/      Items, Ausrüstung, Drops, Haustiere, Reittiere, Shop
    Quests/         Familien-Boss, Quests, Challenges
    Dungeons/       Erzeugung (Seed + Bausteine), Zug-Prüfung, Kampf, Fackeln, Nebel
    Crafting/       Materialien, Rezepte, Stationen, Herstellungsaufträge, Familienlager, Tausch
    Housing/        Familienhaus, Räume, Möbel-Platzierung, Bauprojekte, Garten, Unordnung, Gästebuch
    Rewards/        Familien-Shop, Sterne, Einlösungen, Taschengeld-Konto
    Social/         Feed, Reaktionen, Nachrichten (gefiltert nach Alter)
    Integrations/HomeAssistant/  Token, Ereignis-Zuordnung, Webhooks, Prüfprotokoll
    Notifications/  FCM, E-Mail, Ruhezeiten
  Http/Controllers/{Web,Api/V1}
```
- Geschäftslogik in **Action-Klassen** (`ScoreTask`, `RunDayCron`, `ApproveCompletion`, `RedeemReward`, `RotateChores`), damit Web, API und HA dieselbe Logik nutzen
- **Domain-Events** (`TaskScored`, `LevelUp`, `CompletionApproved`, `RewardRedeemed`) → Listener für Erfolge, Boss-Schaden, Push und HA-Webhooks
- **Policies** für Rollen und Altersgruppen, Familien-Scope über einen Global Scope (Mandantentrennung)
- Inhalts- und Super-Admin-Bereich mit **Filament** unter `/admin` (Items, Quests, Familien)

**Pakete**: Sanctum, Inertia (Vue 3, Vite, Tailwind), Filament, kreait/laravel-firebase, Pest, Larastan, Pint, spatie/laravel-activitylog (Prüfprotokoll)

## 5.3 Datenmodell (Kern-Tabellen)
- `users` (Name, optionale E-Mail, PIN-Hash, Geburtsjahr, Zeitzone, Tagesbeginn, Theme)
- `families`, `family_members` (Rolle, Altersgruppe, Rechte-JSON), `family_invites`, `pairing_codes`, `family_alliances`
- `user_stats` (hp, mp, xp, gold, stars, level, class_key, class_changed_at, attribute-Punkte, streak_shields)
- `active_buffs` (user/family, Effekt-Typ, Wert, Quelle-Fähigkeit, gültig bis, „scharf bis nächster Abschluss“-Flag), `skill_casts` (für Tageslimits und Statistik), `weekly_charges` (Zweite Chance usw.)
- `tasks` (type, owner_id, family_id null, scope `personal|family`, category, title, notes, difficulty, value, repeat-JSON, checklist-JSON, due_at, streak, is_private, approval_mode, assignment_mode `assigned|pool|rotation|team`, rotation_id, star_reward, ha_event_key)
- `task_assignments`, `rotations` (+ `rotation_members`, Reihenfolge, Intervall)
- `task_completions` (task, user, delta, xp, gold, stars, status `pending|approved|rejected|auto`, source `app|web|ha`, proof_path, reviewed_by, client_uuid **eindeutig** → Idempotenz)
- `day_logs` (Cron-Ergebnisse pro Person und Tag – Grundlage für Statistik)
- Inhalte: `items`, `quests`, `achievements` (aus JSON) · `user_items`, `user_equipment`, `user_pets`, `user_achievements`
- `family_quests` (Boss, HP, Zeitraum, Ziel) + `family_quest_contributions`
- Dungeons: `dungeons` (Familie oder Person, Vorlage, Thema, Seed, Status, Woche, `state`-JSON mit Raumgraph, Nebel, Türen, Beute), `dungeon_positions` (Mitglied, Raum, Feld), `dungeon_actions` (Zug-Log mit `client_uuid` und Aktionszähler für den deterministischen Zufall), in `user_stats` kommen `torches` und `torches_today` dazu, Inhalte `dungeon_rooms` (Tiled-Baustein, Typ, Thema), `monsters`
- Crafting: Inhalte `materials`, `recipes` (Inputs-JSON, Output, Station, Dauer, Freischaltung) · `user_materials` (Menge), `family_storage`, `user_recipes`, `craft_jobs` (Rezept, Start, `finishes_at`, Status, Qualität), `user_crafting` (Handwerksstufe, XP), `trade_offers` (Status, `needs_parent_approval`)
- Housing: `homes` (Familie, Stufe), `rooms` (Typ, owner_user_id null = Gemeinschaftsraum, Rastergröße, Stufe), `placed_items` (Raum, Item, x, y, Ebene, Drehung), `build_projects` (Ziel, Anforderungen-JSON, Fortschritt-JSON, Status, `finishes_at`), `build_contributions`, `garden_plots` (Pflanze, gesät, gegossen, Stadium), `guestbook_entries`
- `items` bekommt den Typ `furniture` mit Grundfläche, erlaubten Ebenen und Effekten sowie `quality` für hergestellte Teile
- `reward_items` (Familien-Shop, Preis in Sternen, Limit, ha_action_key) + `reward_redemptions`
- `allowance_ledger` (Taschengeld-Buchungen)
- `challenges`, `challenge_participants`
- `feed_entries`, `reactions`, `messages`
- `device_tokens` (FCM), `notification_settings` (Ruhezeiten)
- `ha_event_mappings`, `ha_webhooks` (URL, Secret), `personal_access_tokens`, `activity_log`

## 5.4 API (`/api/v1`, Sanctum-Token)
- Auth: `POST auth/login`, `auth/pair` (QR-Code + PIN), `auth/logout`
- Abgleich: `GET sync?since=` (Änderungen seit Zeitstempel), `POST sync/actions` (Batch von Offline-Aktionen mit `client_uuid`)
- Tasks: CRUD, `POST tasks/{id}/score/{up|down}`, `POST tasks/{id}/claim` (Börse), `POST completions/{id}/approve|reject`
- Spiel: `GET me/stats`, `POST cron/run`, Shop, Inventar, Ausrüsten, Füttern, Fähigkeit wirken
- Crafting: `GET recipes`, `POST craft` (startet einen Auftrag), `POST craft/{id}/collect`, `POST storage/deposit|withdraw`, `POST gifts`, `trade-offers` (CRUD + accept/approve)
- Dungeons: `GET dungeons/current`, `POST dungeons/{id}/move|interact|fight|use-item` (je mit `client_uuid`), `GET dungeons/{id}/log?since=`
- Housing: `GET home`, `GET rooms/{id}`, `PUT rooms/{id}/layout` (ganzes Layout mit Versionsnummer gegen gleichzeitiges Bearbeiten), `POST build-projects/{id}/contribute`, `POST garden/{plot}/sow|water|harvest`, Gästebuch
- Familie: Mitglieder, Einladungen, Rotationen, Belohnungen, Einlösungen, Feed, Wochenbericht
- HA: `GET ha/state`, `POST ha/events`, `POST ha/tasks`, `POST ha/tasks/{id}/complete`
- Versionierung über `/v1`, die App meldet ihre Version mit, und der Server kann ein Update erzwingen.


