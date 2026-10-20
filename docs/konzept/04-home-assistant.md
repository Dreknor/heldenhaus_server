# 4. Neu: Home-Assistant-Integration

## 4.1 Grundproblem und Lösung
Home Assistant läuft zu Hause hinter NAT, der Laravel-Server im Internet. Deshalb geht der Datenfluss **primär von HA zum Server** (HA fragt ab und sendet Ereignisse). Der Rückweg vom Server zu HA ist optional per Webhook, wenn HA erreichbar ist (Nabu Casa oder eigener Reverse Proxy).

## 4.2 Bausteine (in dieser Reihenfolge)
1. **REST-API + YAML-Vorlagen** (schnell nutzbar): `rest`-Sensoren und `rest_command`, dokumentiert mit Kopiervorlagen im Web („Integration einrichten“)
2. **Eigene HA-Integration `heldenhaus`** (Python, über HACS installierbar, eigenes Repo):
   - Config Flow: Server-URL + Familien-Token
   - `DataUpdateCoordinator`, Abfrage alle 60 s (bei Webhook-Empfang sofort)
   - **Entitäten pro Familienmitglied**: `sensor` (Level, XP, Gold, Sterne, HP, offene Aufgaben, Streak), `binary_sensor` (alle Dailies erledigt, Freigaben ausstehend), **`todo`-Entität** (HA-Aufgabenliste ↔ To-Dos/Dailies, auch in der HA-App abhakbar), `image` (Avatar)
   - **Familien-Entitäten**: Boss-HP, Wochenfortschritt, offene Freigaben
   - **Dienste**: `heldenhaus.complete_task`, `create_task`, `score_habit`, `trigger_event`, `approve`, `grant_stars`, `water_garden` (Server: `POST ha/garden/{beet}/water`, z. B. von einem Pflanzensensor)
   - **HA-Ereignisse**: `heldenhaus_task_completed`, `_reward_redeemed`, `_level_up`, `_all_dailies_done`, `_boss_defeated`, für die Fokus-Hilfen (§11.5) außerdem `_step_completed`, `_focus_started`, `_focus_ended`, `_low_energy_day` und ein Sensor „Fokus-Zeit“ je Mitglied
3. **Ausgehende Webhooks** (Laravel → HA): HMAC-signiert, Wiederholung bei Fehlern über die Queue

## 4.3 Ereignis-Zuordnung (Kernidee)
HA sendet nur **Ereignis-Schlüssel** (`dishwasher_done`, `laundry_finished`, `nfc_feed_cat`). Was daraus wird, legen die Eltern **in der Web-Oberfläche** fest:
- „Aufgabe erzeugen“ (aus Vorlage, Zuweisung über Ämterplan)
- „Aufgabe X von Person Y als erledigt (verifiziert) markieren“
- „Gewohnheit +1“

Belohnungen können ebenfalls einen **Aktions-Schlüssel** tragen (`screen_time_30`). Beim Einlösen geht ein Webhook an HA, und eine HA-Automation schaltet dann z. B. für 30 Minuten die Konsolensteckdose oder das WLAN-Profil frei.

## 4.4 Beispiel-Anwendungen
- Waschmaschine fertig (Stromsensor) → Aufgabe „Wäsche aufhängen“ für die Person, die laut Ämterplan dran ist
- NFC-Tag am Katzenfutter/an der Spülmaschine → Aufgabe abgehakt (verifiziert, keine Freigabe nötig)
- Alle Dailies erledigt → Zimmerlicht leuchtet kurz grün, TTS „Gut gemacht, Lea!“
- Hausaufgaben-Daily nicht erledigt → Konsolensteckdose bleibt aus (von Eltern einstellbar)
- Wandtablet mit Lovelace-Karte (Familienstatus, Boss-HP); später eventuell eine eigene Lovelace-Karte
- Level-up → Sprachansage im Wohnzimmer
- Events (§12): HA-Wetter meldet Schnee (`weather_snow`) oder Hitze (`weather_hot`) und startet „Erster Schnee“ bzw. „Hitzewelle“; Kürbisnacht färbt die Lampen orange, die Wichtelwerkstatt schaltet die Lichterkette beim Öffnen des Türchens
- Fokus-Zeit läuft → Schreibtischlampe wechselt langsam von Grün nach Orange, am Ende ein sanftes Signal; offene Hausaufgaben-Daily → Lampe statt Handy erinnert (§11.4)
- Abschlüsse über HA zählen mit `source = ha` und verstärken die Klasse **Tüftler** (Smart-Connect, Überlastung, §2.4)

## 4.5 Sicherheit
- **Familien-Integrationstoken** (Sanctum, Fähigkeiten `ha:read`, `ha:write`, `ha:approve`), in der Web-Oberfläche widerrufbar
- Ratenbegrenzung pro Token, Prüfprotokoll aller HA-Aktionen
- Ausgehende Webhooks mit `X-Heldenhaus-Signature` (HMAC-SHA256) und Zeitstempel gegen Replay-Angriffe

## 4.6 Webhook-Format (Server → HA)
Eltern tragen unter **Familie → Home Assistant** die Webhook-URL ein (z. B. `https://…ui.nabu.casa/api/webhook/heldenhaus`) und wählen die Ereignisse. Das Secret wird einmal angezeigt.

```http
POST <webhook-url>
Content-Type: application/json
X-Heldenhaus-Event: reward_redeemed
X-Heldenhaus-Delivery: <uuid>
X-Heldenhaus-Timestamp: 1760950000
X-Heldenhaus-Signature: sha256=<HMAC-SHA256(secret, "<timestamp>.<body>")>

{"id": "<uuid>", "event": "reward_redeemed", "family_id": 1, "occurred_at": "…",
 "data": {"member_id": 3, "member_name": "Lea", "reward_title": "30 Minuten Konsole", "stars": 5, "action_key": "screen_time_30"}}
```

- Ereignisse: `task_completed`, `approval_requested`, `all_dailies_done` (einmal pro Tag), `level_up`, `reward_redeemed`, `boss_defeated`, `quest_completed`, `ping` (Test-Knopf)
- Fokus & Alltagshilfen (§11.5): `step_completed`, `focus_started`, `focus_ended` (mit `remaining_minutes` und `result`), `low_energy_day`
- Events (§12.6): `event_started`, `event_ended`, `event_tier_reached` (mit `event_key` und Farbschema)
- Private Aufgaben werden ohne Titel und Kategorie gemeldet (§3.7)
- Empfänger prüfen die Signatur und verwerfen Zeitstempel, die mehr als 5 Minuten abweichen
- Fehler (Timeout, kein 2xx) → bis zu 5 Versuche über die Queue (30 s, 2 min, 10 min, 30 min), Ergebnis im Prüfprotokoll
- Eltern-Dienste über HA (`approvals`, `completions/{id}/approve|reject`, `redemptions/{id}/approve|reject`, `stars/{member}`) brauchen ein Token mit der Fähigkeit `ha:approve`

