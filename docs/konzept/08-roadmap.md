# 9. Umsetzungsplan (Phasen)

| Phase | Inhalt | Ergebnis |
|---|---|---|
| **0 – Fundament** | Laravel-Projekt, Inertia/Vue/Tailwind, Pest, CI, Deployment auf Hetzner (Staging + Prod), Cron/Queue-Setup, Auth, Familien + Einladungen + Kopplung | Einloggen, Familie anlegen, live auf Hetzner |
| **1 – Spielkern (MVP)** | 4 Aufgabentypen, Scoring-Engine, Tageswechsel, Stats/Level, Familienaufgaben (zugewiesen/Börse), Freigaben, Sterne + Familien-Belohnungsshop, Web-Oberfläche | Familie kann im Browser spielen |
| **2 – Flutter-App** | API v1, Kopplung per QR, Offline-Abgleich, Aufgaben/Stats/Freigaben, FCM-Push, Widget | App im geschlossenen Test |
| **3 – Spieltiefe** | **Asset-Pipeline** (Palette, Blender-Render-Skript, `credits.json` + CI-Check, Credits-Bildschirm), Avatar + Ausrüstung + Shop, Drops/Haustiere/Reittiere, Erfolge, 6 Klassen (Krieger, Magier, Schurke, Heiler, Tüftler, Barde) mit Modifikator-Kette für Passiv- und Aktiv-Fähigkeiten, Inhalte als JSON | „Spielgefühl“ |
| **4 – Familie+** | Ämterplan-Rotation, Teamaufgaben, Familien-Boss, Feed/Reaktionen, Wochenbericht, Familienbündnisse, Challenges, Taschengeld-Konto | Vollständige Familienfunktionen |
| **5 – Home Assistant** | HA-API + Ereignis-Zuordnung + YAML-Vorlagen → HACS-Integration (Sensoren, todo, Dienste) → ausgehende Webhooks + Belohnungsaktionen | Smart-Home-Kopplung |
| **6 – Crafting & Housing** | Materialien als Drops (Kategorie bei Aufgaben gibt es schon ab Phase 1), Rezepte, Stationen, Herstellungsaufträge, Familienlager/Tausch; Familienhaus + eigene Zimmer, Editor (Flame/Web), Bauprojekte, Garten, Unordnung-Mechanik, Besuche/Gästebuch | Sammeln, Bauen, Gestalten |
| **7 – Dungeon-Quests** | Tiled-Bausteine + Dungeon-Erzeugung, Fackel-Energie, Flame-Isometrie-Client, rundenbasierte Kämpfe, Klassen-Interaktionen, Familien-Dungeon mit Endboss, Solo-Gewölbe, Web-Kartenansicht, HA-Mechanismen | Quests als Mini-Dungeons |
| **8 – Feinschliff** | Themes für Teens, saisonale Events (saisonale Rezepte/Möbel), Statistiken, Barrierefreiheit, Leistung, iOS-Build (optional) | Version 1.0 |

Die REST-Endpunkte für HA (`ha/state`, `ha/events`) entstehen bereits in Phase 1/2, damit früh experimentiert werden kann.

## Repositories
- `D:\www\habitica` → Laravel (Web + API + Admin), Konzeptdokumente unter `docs/`
- `D:\www\habitica_app` → Flutter-App
- später eigenes Repo `heldenhaus-homeassistant` (HACS verlangt `custom_components/heldenhaus/` im Repo-Stamm)


