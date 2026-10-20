# 9. Umsetzungsplan (Phasen)

| Phase | Inhalt | Ergebnis |
|---|---|---|
| **0 – Fundament** | Laravel-Projekt, Inertia/Vue/Tailwind, Pest, CI, Deployment auf Hetzner (Staging + Prod, Git-Checkout für den Online-Updater), Cron/Queue-Setup, Auth, Familien + Einladungen + Kopplung | Einloggen, Familie anlegen, live auf Hetzner |
| **1 – Spielkern (MVP)** | **Server-Updater** (git-basiert, vom Scheduler ausgeführt, DB-Sicherung), 4 Aufgabentypen, Scoring-Engine, Tageswechsel, Stats/Level, Familienaufgaben (zugewiesen/Börse), Freigaben, Sterne + Familien-Belohnungsshop, Web-Oberfläche | Familie kann im Browser spielen |
| **2 – Flutter-App** | **App-Updater** (APK-Kanäle, Pflicht-Update per 426, Play In-App Updates für den Store-Flavor), API v1, Kopplung per QR, Offline-Abgleich, Aufgaben/Stats/Freigaben, FCM-Push, Widget | App im geschlossenen Test |
| **3 – Spieltiefe** | **Asset-Pipeline** (Palette, Blender-Render-Skript, `credits.json` + CI-Check, Credits-Bildschirm), Avatar + Ausrüstung + Shop, Drops/Haustiere/Reittiere, Erfolge, 6 Klassen (Krieger, Magier, Schurke, Heiler, Tüftler, Barde) mit Modifikator-Kette für Passiv- und Aktiv-Fähigkeiten, Inhalte als JSON | „Spielgefühl“ |
| **4 – Familie+** | Ämterplan-Rotation, Teamaufgaben, Familien-Boss, Feed/Reaktionen, Wochenbericht, Familienbündnisse, Challenges, Taschengeld-Konto | Vollständige Familienfunktionen |
| **5 – Home Assistant** | HA-API + Ereignis-Zuordnung + YAML-Vorlagen → HACS-Integration (Sensoren, todo, Dienste) → ausgehende Webhooks + Belohnungsaktionen | Smart-Home-Kopplung |
| **6 – Crafting & Housing** | Materialien als Drops (Kategorie bei Aufgaben gibt es schon ab Phase 1), Rezepte, Stationen, Herstellungsaufträge, Familienlager/Tausch; Familienhaus + eigene Zimmer, Editor (Flame/Web), Bauprojekte, Garten, Unordnung-Mechanik, Besuche/Gästebuch | Sammeln, Bauen, Gestalten |
| **7 – Dungeon-Quests** | Tiled-Bausteine + Dungeon-Erzeugung, Fackel-Energie, Flame-Isometrie-Client, rundenbasierte Kämpfe, Klassen-Interaktionen, Familien-Dungeon mit Endboss, Solo-Gewölbe, Web-Kartenansicht, HA-Mechanismen | Quests als Mini-Dungeons |
| **G – Grafik (parallel)** | Läuft neben allen Phasen, siehe §2.9: G1 Avatar-, Monster- und Haustier-Baukasten + Effekte, G2 einheitliche Item-Icons und Szenen, G3 isometrische Sprites für Housing/Dungeons, G4 Sound | Es sieht aus wie ein Spiel |
| **8 – Feinschliff** | Themes für Teens, saisonale Events (saisonale Rezepte/Möbel), Statistiken, Barrierefreiheit, Leistung, iOS-Build (optional) | Version 1.0 |

Die REST-Endpunkte für HA (`ha/state`, `ha/events`) entstehen bereits in Phase 1/2, damit früh experimentiert werden kann.

## Repositories

| Repo | Inhalt | GitHub |
|---|---|---|
| `D:\www\habitica` | Laravel-Server (Web, API, Admin), Konzept unter `docs/` | [Dreknor/heldenhaus_server](https://github.com/Dreknor/heldenhaus_server) |
| `D:\www\habitica_app` | Flutter-App (Android) | [Dreknor/heldenhaus_app](https://github.com/Dreknor/heldenhaus_app) |
| `D:\www\heldenhaus-homeassistant` | Home-Assistant-Integration für HACS | noch nicht auf GitHub |

## Umsetzungsstand (20.10.2026)

Legende: ✅ fertig · 🟡 teilweise · ⬜ offen

| Phase | Stand | Fertig | Offen |
|---|---|---|---|
| 0 – Fundament | ✅ | Laravel 12 + Inertia/Vue, Pest, Auth, Familien, Einladungscodes, Kinder-Login per Familiencode + PIN, Hetzner-Deployment als Git-Checkout, Scheduler/Queue ohne Dauerprozesse, Deployment-Anleitung, GitHub-Workflows | Live-Deployment auf Hetzner noch nicht erprobt; GitHub-Workflows laufen erstmals |
| 1 – Spielkern | ✅ | 4 Aufgabentypen, Scoring wie im Original, Tageswechsel mit „Gestern noch erledigt?“, Level/HP/Gold/Sterne, Familienaufgaben (zugewiesen, Börse), Freigaben mit Auto-Bestätigung, Belohnungsshop, **Server-Updater** (git-basiert nach dem Vorbild ElternInfoBoard: git pull, composer, npm-Build, Migrationen, DB-Sicherung, vom Scheduler ausgeführt) | Git/Composer/npm auf dem Hetzner-Webhosting prüfen |
| 2 – Flutter-App | 🟡 | Login (E-Mail, Familiencode, QR, PIN), Heldenpanel mit Offline-Sync, Quests, Inventar/Shop/Stall, Familie, Belohnungsshop, Freigaben, **App-Updater**, alle Spielgrafiken | FCM-Push, Home-Screen-Widget, Avatar-Editor und Klassen-/Held-Ansicht in der App, Test auf echtem Gerät, signierter Release-Build |
| 3 – Spieltiefe | 🟡 | Admin-Backend „Items & Drops“ (Items + Fund-Wahrscheinlichkeiten), Drop-Engine, Inventar, Gold-Shop, Ausrüstung, Attribute, Tränke, Haustiere/Reittiere, 6 Klassen mit Modifikator-Kette, Avatar-Editor | Erfolge/Abzeichen, Asset-Pipeline (`credits.json` + CI-Check, Blender-Skript), saisonale Inhalte |
| 4 – Familie+ | 🟡 | **Quest-System** (Boss- und Sammel-Quests, solo und gemeinsam, Schwächen/Resistenzen/Wut, wechselnde Quest-Tafel, Monster-Lexikon), Ämterplan-Rotation, Teamaufgaben mit Bonus, Familien-Feed mit Reaktionen, Wochenbericht (Seite + E-Mail), Taschengeld-Konto | Familienbündnisse, Challenges, Chat für Teens |
| 5 – Home Assistant | 🟡 | REST-API (Zustand, Ereignisse), Ereignis-Schlüssel an Aufgaben, YAML-Vorlage, **HACS-Integration** (Sensoren je Mitglied, „alle Dailies erledigt“, Dienst `trigger_event`, Spielen stellvertretend für Familienmitglieder, Avatar-/Boss-Bilder), Tüftler-Klasse | Ausgehende Webhooks (Server → HA), Belohnungsaktionen (`ha_action_key` auslösen), HA-Repo auf GitHub, Test mit echter HA-Instanz |
| G – Grafik | 🟡 | G1 Avatar-, Monster- und Haustier-Baukasten mit Animationen · G2 eigene Item-Grafiken, Helden-Szene mit Tageszeit · alles auch in der Flutter-App (gleiche Optik) | G3 isometrische Grafiken für Housing/Dungeons, G4 Sound |
| 6 – Crafting & Housing | ⬜ | Materialien fallen bereits als Funde | Rezepte, Stationen, Familienhaus, Zimmer-Editor, Bauprojekte, Garten |
| 7 – Dungeon-Quests | ⬜ | Bosse und Quest-System als Grundlage | Dungeon-Erzeugung, Fackeln, isometrischer Client |
| 8 – Feinschliff | ⬜ | – | Teen-Themes, saisonale Events, Statistiken, Barrierefreiheit, iOS |

**Tests**: Server 165 Pest-Tests, App 7 Flutter-Tests (inkl. Grafik-Vorschau), HA-Integration 25 pytest-Tests – alle grün.

**Gerade in Arbeit (eigene Sitzungen, noch nicht committet)**
- Erfolge/Abzeichen (Phase 3)

**Nächste sinnvolle Schritte**
1. Auf Hetzner live gehen (Staging zuerst) und den Online-Updater mit einem ersten Update aus dem Repository durchspielen
2. App auf einem echten Gerät testen, signierten Release-Build anlegen, Push-Benachrichtigungen (FCM)
3. Phase 5 abschließen: ausgehende Webhooks und Belohnungsaktionen
4. Phase 6 starten: Crafting & Housing (mit Grafik G3)
