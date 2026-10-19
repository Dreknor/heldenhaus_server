# Heldenhaus – Gesamtkonzept

> Habitica-inspiriertes Aufgaben-Rollenspiel für Familien (Kinder 9–16 + Eltern) · Laravel + Inertia/Vue · Flutter-App · Home Assistant · Stand: 2026-10-19

## Ausgangslage

Ziel ist ein eigener Nachbau des Gamification-Prinzips von Habitica (Aufgaben → XP/Gold → Level, Ausrüstung, Haustiere, Quests), zugeschnitten auf **Kinder von 9–16 Jahren und ihre Eltern**. Gegenüber dem Original kommen **Familienaufgaben** (Haushalt, Ämterplan, Freigaben, reale Belohnungen) und eine **Home-Assistant-Integration** hinzu. Backend und Web liegen in `D:\www\habitica`, die Flutter-App in `D:\www\habitica_app`.

Festgelegte Rahmenbedingungen:
- Zielgruppe: **eigene Familie + Bekannte** – mehrere Familien möglich, Beitritt nur per Einladung, keine öffentliche Registrierung
- Backend: **Laravel (aktuelle Version, PHP ≥ 8.3)**, Web-Frontend **Inertia + Vue 3 + Tailwind**
- App: **Flutter** (zunächst Android, iOS später ohne Neubau möglich)
- Server: **Hetzner Webhosting mit SSH + Cronjobs** → keine Dauerprozesse (kein Supervisor, kein Redis, keine WebSockets)
- Arbeitstitel „Heldenhaus“ – der Name „Habitica“ ist markenrechtlich geschützt und wird nicht verwendet

## Inhalt

| Nr. | Dokument | Inhalt |
|---|---|---|
| 1 | [Leitprinzipien](konzept/01-leitprinzipien.md) | Motivation und Pädagogik je Zielgruppe, Grundregeln |
| 2 | [Spielmechanik](konzept/02-spielmechanik.md) | Aufgabentypen, Formeln, Progression, Spielklassen, Crafting, Housing, Dungeon-Quests, Assets und Werkzeuge |
| 3 | [Familie](konzept/03-familie.md) | Rollen, Familienaufgaben, Freigaben, Sterne und Belohnungsshop, Familien-Boss, Bündnisse, Privatsphäre |
| 4 | [Home Assistant](konzept/04-home-assistant.md) | Datenfluss, HACS-Integration, Ereignis-Zuordnung, Anwendungsbeispiele, Sicherheit |
| 5 | [Architektur](konzept/05-architektur.md) | Hetzner-Webhosting-Setup, Deployment, Domänen-Module, Datenmodell, API |
| 6 | [App & Web](konzept/06-app-und-web.md) | Flutter-App (Offline-first, Flame) und Inertia/Vue-Frontend |
| 7 | [Datenschutz](konzept/07-datenschutz.md) | DSGVO, Datensparsamkeit bei Kindern, Sicherheit |
| 8 | [Roadmap](konzept/08-roadmap.md) | Phasen 0–8, Repositories |
| 9 | [Tests](konzept/09-tests.md) | Teststrategie und Abnahme |
