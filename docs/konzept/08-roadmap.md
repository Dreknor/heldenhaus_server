# 8. Umsetzungsplan (Phasen)

| Phase | Inhalt | Ergebnis |
|---|---|---|
| **0 – Fundament** | Laravel-Projekt, Inertia/Vue/Tailwind, Pest, CI, Deployment auf Hetzner (Staging + Prod, Git-Checkout für den Online-Updater), Cron/Queue-Setup, Auth, Familien + Einladungen + Kopplung | Einloggen, Familie anlegen, live auf Hetzner |
| **1 – Spielkern (MVP)** | **Server-Updater** (git-basiert, vom Scheduler ausgeführt, DB-Sicherung), 4 Aufgabentypen, Scoring-Engine, Tageswechsel, Stats/Level, Familienaufgaben (zugewiesen/Börse), Freigaben, Sterne + Familien-Belohnungsshop, Web-Oberfläche | Familie kann im Browser spielen |
| **2 – Flutter-App** | **App-Updater** (APK-Kanäle, Pflicht-Update per 426, Play In-App Updates für den Store-Flavor), API v1, Kopplung per QR, Offline-Abgleich, Aufgaben/Stats/Freigaben, FCM-Push, Widget | App im geschlossenen Test |
| **3 – Spieltiefe** | **Asset-Pipeline** (Palette, Blender-Render-Skript, `credits.json` + CI-Check, Credits-Bildschirm), Avatar + Ausrüstung + Shop, Drops/Haustiere/Reittiere, Erfolge, 6 Klassen (Krieger, Magier, Schurke, Heiler, Tüftler, Barde) mit Modifikator-Kette für Passiv- und Aktiv-Fähigkeiten, Inhalte als JSON | „Spielgefühl“ |
| **4 – Familie+** | Ämterplan-Rotation, Teamaufgaben, Familien-Boss, Feed/Reaktionen, Wochenbericht, Familienbündnisse, Challenges, Taschengeld-Konto | Vollständige Familienfunktionen |
| **4a – Fokus & Alltagshilfen** (§11) | Server zuerst: sanfter Modus, Serien pausieren + Erholungs-Quests, Kern-Dailies + Energiespar-Tag, Schritt-Belohnung für Checklisten, Kopf-Ablage (`inbox`), Überraschungen mit Pech-Schutz, Schritt-Vorlagen, optional KI-Aufteilung über OpenRouter, Fokus-Zeiten-API. Danach App/Web: Fokus-Modus, visueller Timer, reizarme Einstellungen, Schnell-Eingabe im Widget. Mit Phase 5: HA-Ereignisse und Lichtsignale | Heldenhaus ist ADHS-freundlich ohne Etikett |
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
| 2 – Flutter-App | 🟡 | Login (E-Mail, Familiencode, QR, PIN), Heldenpanel mit Offline-Sync, Quests, Inventar/Shop/Stall, Familie, Belohnungsshop, Freigaben, **App-Updater**, alle Spielgrafiken | Server: Geräte-Tokens, FCM-Push (HTTP v1) ohne Inhalte, Ruhezeiten, Einstellungen per API · App: FCM-Push, Home-Screen-Widget, Avatar-Editor und Klassen-/Held-Ansicht in der App, Test auf echtem Gerät, signierter Release-Build |
| 3 – Spieltiefe | 🟡 | Admin-Backend „Items & Drops“ (Items + Fund-Wahrscheinlichkeiten), Drop-Engine, Inventar, Gold-Shop, Ausrüstung, Attribute, Tränke, Haustiere/Reittiere, 6 Klassen mit Modifikator-Kette, Avatar-Editor, **Erfolge** (15 Stufen-Erfolge mit eigenen Abzeichen, Feier, Gold je Stufe, Feed, Familienansicht, API) | Erfolge in der App anzeigen, Asset-Pipeline (`credits.json` + CI-Check, Blender-Skript), saisonale Inhalte |
| 4 – Familie+ | 🟡 | **Quest-System** (Boss- und Sammel-Quests, solo und gemeinsam, Schwächen/Resistenzen/Wut, wechselnde Quest-Tafel, Monster-Lexikon), Ämterplan-Rotation, Teamaufgaben mit Bonus, Familien-Feed mit Reaktionen, Wochenbericht (Seite + E-Mail + Push), Taschengeld-Konto, **Familienbündnisse** (Code, Zustimmung beider Eltern), **Challenges** (Aufgabenpaket, Zeitraum, Ziel, optionale Rangliste, auch mit Verbündeten), **Nachrichten** (Familie frei, im Bündnis Freitext nur für Erwachsene und freigegebene Teens) | App-Ansichten für Bündnisse, Challenges und Nachrichten |
| 4a – Fokus & Alltagshilfen | 🟡 | **Server**: Einstellungen je Person (Eltern für Kinder) und Familienstandard, sanfter Modus ohne HP-Schaden, Serien pausieren + Erholungs-Quest (Bestwert bleibt), Kern-Dailies + Energiespar-Tag mit Wochenlimit und Bonus, Schritt-Belohnung 1/n mit `client_uuid` (auch im Offline-Abgleich), Kopf-Ablage (`inbox`), Überraschungen mit Pech-Schutz, Fokus-Zeiten (auch gemeinsam), Schritt-Vorschläge aus Vorlagen/Familie/OpenRouter mit Budget, HA-Ereignisse `step_completed`, `focus_started/ended`, `low_energy_day` | App/Web: Fokus-Modus, visueller Timer, reizarme Darstellung, Schnell-Eingabe; HA: Fokus-Sensor, Lichtsignal-Vorlagen |
| 5 – Home Assistant | 🟡 | REST-API (Zustand, Ereignisse), Ereignis-Schlüssel an Aufgaben, YAML-Vorlage, **HACS-Integration** (Sensoren je Mitglied, „alle Dailies erledigt“, Dienst `trigger_event`, Spielen stellvertretend für Familienmitglieder, Avatar-/Boss-Bilder), Tüftler-Klasse, **ausgehende Webhooks** (HMAC-signiert, Wiederholung über die Queue), **Belohnungsaktionen** (`action_key` beim Einlösen), Dienste `create_task`/`complete_task`/`approve`/`grant_stars`, Prüfprotokoll | Webhook-Empfang in der HACS-Integration, HA-Repo auf GitHub, Test mit echter HA-Instanz |
| G – Grafik | 🟡 | G1 Avatar-, Monster- und Haustier-Baukasten mit Animationen · G2 eigene Item-Grafiken, Helden-Szene mit Tageszeit · alles auch in der Flutter-App (gleiche Optik) · G3 Dungeon: 17 isometrische Tiled-Bausteine (`public/dungeon/blocks/*.tmx`) und 86 Sprites aus Kenney „Isometric Miniature Dungeon“ (CC0), erzeugt mit `tools/dungeon/build_blocks.py` | G3 Housing-Grafiken, Monster/Figuren im Dungeon, G4 Sound |
| 6 – Crafting & Housing | 🟡 | **Server**: 17 neue Materialien/Erntegüter, 14 Möbel mit Grundfläche und Ebenen, 5 Crafting-Ausrüstungen, 24 Rezepte an 4 Stationen mit Ausbaustufen, Herstellungsaufträge in Echtzeit mit Push „fertig“, Qualität normal/gut/meisterhaft nach Handwerksstufe (meisterhafte Ausrüstung +1 je Wert), Bibliotheks-Rezepte, Familienlager, Schenken, Tausch mit Bündnis-Familien (Treuhand, Eltern-Zustimmung unter 13, kein Gold/Sterne, keine Ausrüstung), Familienhaus mit Ausbaustufen und eigenem Zimmer je Mitglied, Layout-API mit Versionsnummer (409 bei Konflikt), Raster-, Ebenen- und Überlappungsprüfung, 14 Bauprojekte (Räume, Stufen, Stationen) mit Beiträgen aus Inventar/Lager/Gold, Bauzeit, Einweihung und realem Familienziel, Garten (säen, täglich gießen, auch per HA, ernten), Unordnung aus überfälligen Haushaltsaufgaben, Besuche und Gästebuch, Stall-Bonus beim Füttern, eigenes Zimmer gestalten (Name, Wand, Boden; Eltern dürfen Kinderzimmer mitgestalten, Teen-Zimmer bleiben privat), Inventarplätze + Lagerraum, Bett-Regeneration, Trophäenhalle, Boss-Falle, Feuerwerk, Serien-Amulett | App/Web: Werkstatt, Zimmer-Editor (Flame/Web), Bauprojekt- und Garten-Ansicht; Grafik G3 für Housing |
| 7 – Dungeon-Quests | 🟡 | **Server**: Erzeugung aus Seed (5–12 Räume, Schlüssel, Tore mit Hebel, Abkürzungen, Rätsel), Fackeln aus Aufgaben mit Tages-/Vorratslimit, wöchentlicher Familien-Dungeon mit Familien-Boss als Endboss, tägliches Solo-Gewölbe, Züge idempotent per `client_uuid`, reproduzierbare Kämpfe, Klassen-Interaktionen, HA-Taster als Mechanismus, Zug-Log; je Raum Tiled-Baustein und Tür-Sprites in der API | Isometrischer Client in der App (Flame + `flame_tiled`), Web-Kartenansicht |
| 8 – Feinschliff | ⬜ | – | Teen-Themes, saisonale Events, Statistiken, Barrierefreiheit, iOS |

**Tests**: Server 240 Pest-Tests, App 7 Flutter-Tests (inkl. Grafik-Vorschau), HA-Integration 25 pytest-Tests – alle grün.

**Nächste sinnvolle Schritte**
1. Auf Hetzner live gehen (Staging zuerst) und den Online-Updater mit einem ersten Update aus dem Repository durchspielen
2. App auf einem echten Gerät testen, signierten Release-Build anlegen, Push-Benachrichtigungen (FCM) in der App anbinden (Server ist fertig, Dienstkonto-JSON nach `storage/app/private/firebase.json`)
3. Phase 4a (§11) in App und Web: Fokus-Modus, Timer, Schritte einzeln anzeigen, Kopf-Ablage (Server ist fertig)
4. Phase 5 abschließen: Webhook-Empfang in der HACS-Integration (Server sendet bereits)
5. Phase 6 in App und Web: Werkstatt, Zimmer-Editor, Bauprojekte, Garten (Server ist fertig, mit Grafik G3)
6. Phase 7 in der App: Dungeon mit Flame + `flame_tiled` aus den fertigen Bausteinen (Server ist fertig)
