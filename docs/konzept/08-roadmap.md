# 9. Umsetzungsplan (Phasen)

| Phase | Inhalt | Ergebnis |
|---|---|---|
| **0 – Fundament** | Laravel-Projekt, Inertia/Vue/Tailwind, Pest, CI, Deployment auf Hetzner (Staging + Prod, Release-Struktur kompatibel zum Online-Updater), Cron/Queue-Setup, Auth, Familien + Einladungen + Kopplung | Einloggen, Familie anlegen, live auf Hetzner |
| **1 – Spielkern (MVP)** | **Server-Updater** (signierte Release-Pakete, Zustandsautomat, Rollback), 4 Aufgabentypen, Scoring-Engine, Tageswechsel, Stats/Level, Familienaufgaben (zugewiesen/Börse), Freigaben, Sterne + Familien-Belohnungsshop, Web-Oberfläche | Familie kann im Browser spielen |
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
- `D:\www\habitica` → Laravel (Web + API + Admin), Konzeptdokumente unter `docs/`
- `D:\www\habitica_app` → Flutter-App
- später eigenes Repo `heldenhaus-homeassistant` (HACS verlangt `custom_components/heldenhaus/` im Repo-Stamm)



## Umsetzungsstand (20.10.2026)

| Phase | Stand |
|---|---|
| 0 – Fundament | ✅ fertig (Deployment auf Hetzner noch nicht live erprobt) |
| 1 – Spielkern | ✅ fertig, inkl. Server-Updater |
| 2 – Flutter-App | ✅ Grundversion (Login, Board, Offline-Sync, Freigaben, Shop, App-Updater); Push und Widget fehlen noch |
| 3 – Spieltiefe | ✅ Items & Drops (Admin), Inventar, Gold-Shop, Ausrüstung, Attribute, Haustiere/Reittiere, 6 Klassen mit Modifikator-Kette · offen: Avatar-Editor, Erfolge, Asset-Pipeline |
| 4 – Familie+ | 🟡 Quest-System (Boss- und Sammel-Quests, solo und gemeinsam, wechselnde Quest-Tafel), Ämterplan-Rotation, Teamaufgaben, Feed (Backend), Taschengeld-Buchung · offen: Feed- und Rotations-Oberfläche, Wochenbericht, Bündnisse, Challenges |
| G – Grafik | 🟡 G1 fertig: Avatar-Baukasten mit Editor und sichtbarer Ausrüstung, Monster-Baukasten (8 verschiedene Bosse, Treffer-Animation, Monster-Lexikon), Sammel-Korb, Haustiere/Reittiere je Art × Schlüpfpulver · offen: G2 Item-Icons statt Emoji, Szenen; G3 Isometrie; G4 Sound |
| 5 – Home Assistant | 🟡 REST-API + YAML-Vorlage · offen: HACS-Integration, ausgehende Webhooks |
| 6–8 | offen |
