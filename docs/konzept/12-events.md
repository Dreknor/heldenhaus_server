# 12. Events

Events sind **zeitlich begrenzte Spielphasen mit eigenem Thema**: Das Familienhaus ist geschmückt, es gibt eine passende Quest, besondere Funde und eine gemeinsame Belohnung. Ein Teil startet **nach Kalender** (Jahreszeiten, Feiertage, Schulferien), der andere **durch Ereignisse** (im Spiel, in der Familie oder über Home Assistant). Events ersetzen den bisherigen Punkt „Saisonale Events“ (§2.3, Phase 8).

## 12.1 Grundregeln

- **Kooperation vor Konkurrenz** (§1): Der Event-Fortschritt ist in erster Linie **gemeinsam für die Familie**. Persönliche Sammelstücke gibt es zusätzlich, Ranglisten nur in freiwilligen Event-Challenges.
- **Kein FOMO-Druck** (§11): Die Hauptbelohnung ist mit **etwa 60 % der üblichen Aktivität** erreichbar. Event-Gegenstände kommen in späteren Jahren wieder („Wiederkehr“), wer ein Event verpasst, verliert also nichts für immer. Verpasste Event-Tage haben keine Folgen, im sanften Modus und am Energiespar-Tag zählt der Fortschritt normal weiter.
- **Kein Echtgeld, keine Sterne aus dem Zufall** (§1): Events belohnen mit Gold, XP, Materialien, Rezepten, Möbeln, Kosmetik und Abzeichen. Sterne gibt es nur, wenn die Eltern ein reales Familienziel an das Event knüpfen (wie bei Familien-Quests, §3.5).
- **Nicht zu viel auf einmal**: Gleichzeitig laufen höchstens **ein Kalender-Event und zwei weitere Events**. Ein Event, das nicht starten kann, wartet bis zu 3 Tage und verfällt sonst.
- **Eltern behalten die Kontrolle**: Jedes Event lässt sich pro Familie abschalten (z. B. Halloween oder religiöse Feste), Familien-Anlässe wie Geburtstage sind nur aktiv, wenn die Eltern sie einrichten.
- **Altersgerecht**: Jedes Event hat Varianten für 9–12 und 13–16 (z. B. Halloween für Jüngere „lustig-schaurig“ statt gruselig).
- **Region**: Feiertage und Schulferien hängen vom Bundesland ab. Die Familie wählt Land und Bundesland in den Einstellungen (Standard: Deutschland, Sachsen).

## 12.2 Bausteine eines Events

Jedes Event ist Inhalt in `database/content/events.json` und kombiniert vorhandene Spielsysteme, statt eigene Mechaniken mitzubringen:

| Baustein | Beispiel | Vorhandenes System |
|---|---|---|
| **Event-Quest** | Boss „Frostriese“ oder Sammel-Quest „15 Laternen“ | Quest-System (§3.5), Vorlagen in `quests.json` mit `event_key` |
| **Event-Ziel der Familie** | 300 Event-Punkte in 10 Tagen, Stufen bei 25 / 50 / 100 % | Neu: Punkte aus erledigten Aufgaben (wie Boss-Schaden berechnet) |
| **Modifikatoren** | +25 % XP für die Kategorie „Sport & Draußen“, doppelte Ernte | Modifikator-Kette der Scoring-Engine (§2.4) |
| **Event-Funde** | Ostereier, Kürbisse, Schneeflocken als Sammelobjekte | Drop-Engine mit eigener Tabelle und Tageslimit (§2.3) |
| **Rezepte und Möbel** | Laterne, Adventskranz, Schneemann-Deko | Crafting und Housing (§2.5, §2.6) |
| **Dungeon-Thema** | Kürbis-Gruft, Eispalast | Dungeon-Erzeugung mit Thema (§2.7) |
| **Deko und Optik** | geschmücktes Familienhaus, Farbschema, Banner | Grafik-Fahrplan (§2.9), Housing-Ebenen |
| **Abzeichen** | „Lichterträger 2026“ | Erfolge (§2.3) |
| **HA-Signale** | Lampen leuchten orange (Halloween) | Ausgehende Webhooks (§4.2) |

**Ablauf einer Event-Instanz**: `geplant` → `aktiv` (Push „Das Erntefest hat begonnen!“, Feed-Eintrag, Haus-Deko) → `endet bald` (24 h vorher, sanfter Hinweis ohne Countdown-Druck) → `beendet` (Belohnungen der erreichten Stufen, Rückblick im Feed, Deko bleibt 2 Tage als Erinnerung). Eine Vorschau „Demnächst“ zeigt Kalender-Events 7 Tage vorher, damit Vorfreude entsteht.

## 12.3 Auslöser

**Kalender (datumsgesteuert)**
- `fixed`: festes Datum, z. B. 11.11.
- `easter_offset`: Abstand zu Ostersonntag (Gauß-Osterformel im Code, ohne `ext-calendar`, das auf dem Webhosting fehlen kann). Fasching = Ostern −52 bis −46, Ostern = −3 bis +1.
- `rule`: berechnete Regeln wie „erster Sonntag im Oktober“ (Erntedank) oder „4. Sonntag vor dem 25.12.“ (1. Advent)
- `season`: Jahreszeitenbeginn (20.03., 21.06., 22.09., 21.12., als feste Näherung)
- `school_holidays`: Beginn bzw. Ende von Schulferien des Bundeslands. Quelle ist die kostenlose **OpenHolidays API** (`openholidaysapi.org`, ohne Schlüssel), einmal im Monat für 12 Monate im Voraus geladen und zwischengespeichert. Fällt die API aus, tragen Eltern Ferien von Hand ein.

**Ereignisse (ereignisgesteuert)**
- `game`: Domain-Events des Servers (`BossDefeated`, `FamilyDayEnded`, `BuildProjectCompleted`, `MemberJoined`, `LevelUp` …) mit Bedingung, z. B. „3 Bosse in Folge besiegt“
- `family_date`: Anlässe aus Familiendaten (Gründungstag der Familie, Geburtstag, wenn eingerichtet)
- `ha`: Ereignis-Schlüssel von Home Assistant (§4.3), z. B. `weather_snow`, `weather_hot`
- `weather` (optional, ohne HA): Wetter von **Open-Meteo** (kostenlos, ohne Schlüssel) für einen grob gerundeten Ort der Familie. Nur wenn die Eltern es einschalten (§7).
- `random`: seltener Zufall mit festem Seed pro Familie und Monat (z. B. der wandernde Händler)
- `manual`: Eltern oder Admins starten ein Event von Hand (auch zum Testen auf Staging)

Jedes ereignisgesteuerte Event hat eine **Abklingzeit** (`cooldown_days`), damit es nicht ständig wiederkommt, und kann an Bedingungen geknüpft sein (Mindest-Level, Mindestzahl aktiver Mitglieder, HA vorhanden).

## 12.4 Event-Katalog (21 Events)

### Kalender-Events (11)

| # | Event | Zeitraum (Auslöser) | Inhalt |
|---|---|---|---|
| 1 | 🌱 **Frühlingserwachen** | 20.03. für 14 Tage (`season`) | Familien-Boss **Staubkönig** (Schwäche Haushalt) als Frühjahrsputz, Saatgut-Funde, Garten wächst doppelt so schnell, Rezept „Blumenkasten“ |
| 2 | 🥚 **Eiersuche** | Gründonnerstag bis Ostermontag (`easter_offset` −3 … +1) | Bei erledigten Aufgaben tauchen Eier versteckt im Familienhaus und im Solo-Gewölbe auf, Sammel-Quest der Familie, Event-Haustier „Frühlingshase“ als Belohnung |
| 3 | 🔄 **Rollentausch-Tag** | 15.05., Internationaler Tag der Familie (`fixed`, 1 Tag) | Kinder dürfen den Eltern je eine Aufgabe zuweisen, Eltern bekommen dafür Bonus-XP. Freigaben machen an diesem Tag die Kinder (außer Sterne-Aufgaben) |
| 4 | ☀️ **Mittsommerfest** | 21.06. für 3 Tage (`season`) | +25 % XP und Gold für „Sport & Draußen“, Sonnen-Kosmetik, Lagerfeuer im Dungeon heilen doppelt |
| 5 | 🏕️ **Sommerferien-Abenteuer** | erste Woche der Sommerferien, dann wöchentlich neues Kapitel (`school_holidays`) | Reise-Quest-Kette mit 6 Kapiteln (Strand, Berge, Burg …), je Kapitel eine Postkarte fürs Familienhaus. Ferien-Dailies dürfen leichter sein, das Gasthaus wird angeboten |
| 6 | 🎒 **Schulstart-Neustart** | 3 Tage vor bis 7 Tage nach Ferienende (`school_holidays`) | „Neuer Rucksack“-Quest: Schulsachen packen, Lern-Dailies anlegen (Vorlagen), +20 % XP für „Schule & Lernen“, sanfter Einstieg mit Kern-Dailies (§11) |
| 7 | 🍂 **Erntefest** | Herbstanfang 22.09. bis Erntedank (`season` + `rule`) | Doppelte Ernte im Garten, Bauprojekt „Erntescheune“, Rezepte „Apfelkuchen“ und „Kürbissuppe“ (Futter), gemeinsames Ziel „Vorratskammer füllen“ |
| 8 | 🎃 **Kürbisnacht** | 24.10. bis 31.10. (`fixed`) | Kostüme als Avatar-Kosmetik, Dungeon-Thema Kürbis-Gruft, Monster in Spuk-Varianten, Sammel-Quest Kürbisse; für 9–12 lustig statt gruselig, abschaltbar |
| 9 | 🏮 **Laternenfest** | 08.11. bis 11.11. (`fixed`) | Thema Teilen: Geschenke an Familienmitglieder und Bündnis-Familien geben Event-Punkte, Laterne als Rezept, im Dungeon bringt sie zusätzliche Fackeln |
| 10 | 🎄 **Wichtelwerkstatt** | 1. Advent bis 24.12. (`rule`) | Adventskalender: täglich ein Türchen nach der ersten erledigten Aufgabe (verpasste Türchen lassen sich nachholen). **Heimliche Helfer**: Man erledigt heimlich eine Aufgabe für ein anderes Mitglied, die Auflösung kommt am 24.12. Rezepte Adventskranz und Plätzchen |
| 11 | 🎆 **Raunächte & Jahreswechsel** | 27.12. bis 06.01. (`fixed`) | Ruhiges Event: **Jahresrückblick** der Familie (Aufgaben, Bosse, Haus, Erfolge), Vorsätze als freiwillige Challenges fürs neue Jahr, Feuerwerk-Effekt an Silvester, keine Pflicht-Ziele |

Ergänzend (eher als Variante von bestehenden Events): **Fasching** (`easter_offset` −52 … −46) mit kostenlosem Klassen-Probetag (für einen Tag jede Klasse ausprobieren, ohne den Monatswechsel zu verbrauchen) und Kostümen. Er zählt nicht zu den 21, weil er regional sehr unterschiedlich gefeiert wird, und ist standardmäßig aus.

### Ereignis-Events (10)

| # | Event | Auslöser | Inhalt |
|---|---|---|---|
| 12 | ❄️ **Erster Schnee** | HA `weather_snow` oder Open-Meteo meldet Schnee, frühestens ab November, einmal pro Winter | 3 Tage: Bauprojekt **Schneemann** im Garten des Familienhauses, Schneeflocken als Funde, Dungeon-Thema Eispalast |
| 13 | 🥵 **Hitzewelle** | HA `weather_hot` oder Open-Meteo ≥ 30 °C an 2 Tagen, Abklingzeit 14 Tage | „Oase“: Vorschlag einer Gewohnheit „Wasser trinken“, Dailies im Haus zählen 20 % mehr, Rezept „Eis am Stiel“ (Futter), Lampen über HA in kühlem Blau |
| 14 | 🐉 **Rückkehr des Drachenfürsten** | Die Familie hat 3 Familien-Bosse in Folge besiegt (`game`), Abklingzeit 30 Tage | Elite-Boss mit zwei Phasen und wechselnder Schwäche, Belohnung: Trophäe für die Trophäenhalle und seltene Drachenschuppen |
| 15 | ✨ **Goldene Woche** | Alle aktiven Mitglieder schaffen 3 Tage in Folge alle (Kern-)Dailies (`game`, nach `FamilyDayEnded`), Abklingzeit 21 Tage | 4 Tage +15 % Gold für alle, goldene Haus-Deko, Abzeichen; ergänzt den Gemeinschafts-Rhythmus des Barden, ohne ihn zu ersetzen |
| 16 | 🎉 **Willkommensfest** | Ein neues Mitglied tritt der Familie bei (`game`, `MemberJoined`) | 7 Tage: Quest „Zeig mir das Heldenhaus“, alle helfen dem Neuling bis Level 3, Mentoren-Bonus für jede Hilfe, Begrüßung im Feed |
| 17 | 🏡 **Hauseinweihung** | Das Familienhaus erreicht eine neue Ausbaustufe (`game`, `BuildProjectCompleted`) | 3 Tage Party: Bündnis-Familien werden zum Besuch eingeladen, Gästebuch-Sticker, Deko-Rezepte der neuen Stufe |
| 18 | 🏅 **Heldenjubiläum** | Jahrestag der Familiengründung in Heldenhaus (`family_date`) | 1 Tag: Rückblick „Ein Jahr Heldenhaus“, Jahres-Abzeichen, Konfetti beim ersten Login |
| 19 | 🎂 **Geburtstag** | Geburtstag eines Mitglieds, nur wenn die Eltern ihn eingetragen haben (`family_date`) | Geburtstagskind bekommt Konfetti, eine Krone als Kosmetik und einen **Wunsch-Joker** (eine eigene Aufgabe an diesem Tag aussetzen). Jede Aufgabe der anderen schickt ihm einen kleinen Gruß |
| 20 | 🤗 **Willkommen zurück** | Rückkehr aus dem Gasthaus nach ≥ 3 Tagen oder erste Aktivität nach ≥ 5 Tagen Pause (`game`), nur persönlich | Sanfter Wiedereinstieg (§11): Mini-Quest mit 3 leichten Aufgaben, Serien bleiben pausiert und lassen sich reparieren, kein Hinweis auf die verpassten Tage |
| 21 | 🧳 **Wandernder Händler** | Zufall (`random`), etwa einmal im Monat, nur ab Familien-Level 5 | 48 h ein besonderer Laden mit seltenen Rezepten und Möbeln **nur für Gold** (wichtiger Gold-Verbraucher gegen Inflation, §2.5), Angebot je Familie gleich |

**Jahresverlauf** (Beispiel 2027, Sachsen): Erntefest und Kürbisnacht im Herbst, Laternenfest und Wichtelwerkstatt bis Weihnachten, Raunächte zum Jahreswechsel, Frühlingserwachen und Eiersuche (28.03.) im Frühling, Rollentausch-Tag im Mai, Mittsommer und Sommerferien-Abenteuer im Sommer, danach der Schulstart. Ereignis-Events füllen die Lücken.

## 12.5 Datenmodell, Ablauf und API

**Inhalt** (`database/content/events.json`, Import mit `game:content-sync`, im Admin-Backend bearbeitbar wie Items & Drops):
`key`, `name`, `icon`, `category` (`calendar|game|family|world`), `trigger` (Typ und Parameter), `duration_days`, `cooldown_days`, `priority`, `default_enabled`, `min_family_level`, `requires_ha`, `variants` (Texte je Altersgruppe), `quest_key`, `goal` (Punkte und Stufen), `modifiers` (Effekt-Typen der Modifikator-Kette), `drops`, `recipes`, `decor`, `badge`, `ha_actions`, `returns_in_years`

**Tabellen**
- `family_event_settings`: `family_id`, `country`, `subdivision` (z. B. `DE-SN`), `disabled_events` (JSON), `weather_source` (`none|ha|open_meteo`), `weather_location` (auf 0,1° gerundet)
- `member_dates`: `user_id`, `type` (`birthday`), `month`, `day` (ohne Jahr, nur auf Wunsch der Eltern)
- `holiday_periods`: `country`, `subdivision`, `type` (`public|school`), `name`, `starts_on`, `ends_on`, `source` (`openholidays|manual`), `fetched_at`
- `family_events`: `family_id`, `event_key`, `status` (`scheduled|active|ending|finished|skipped`), `starts_at`, `ends_at`, `trigger_source`, `seed`, `progress` (JSON: Punkte, erreichte Stufen), `queued_until`
- `event_participations`: `family_event_id`, `user_id`, `points`, `collected` (JSON), `rewards_claimed_at`
- `event_trigger_log`: Auslöser, Zeitpunkt, Ergebnis (gestartet, Abklingzeit, abgeschaltet, voll) für Prüfprotokoll und Fehlersuche

**Ablauf auf dem Webhosting** (ohne Dauerprozesse, §5.1)
- `heldenhaus:events-plan` täglich um 00:10: plant Kalender-Events der nächsten 60 Tage je Familie (Zeitzone der Familie)
- `heldenhaus:events-tick` alle 15 Minuten: aktiviert, beendet und zahlt Belohnungen aus (idempotent, `withoutOverlapping`)
- `heldenhaus:holidays-sync` monatlich: Feiertage und Schulferien von der OpenHolidays API laden
- Ereignis-Events: Listener auf die Domain-Events rufen `EventTrigger::consider($family, $key, $context)` auf. Er prüft Abschaltung, Bedingungen, Abklingzeit und das Limit gleichzeitiger Events.
- Die Scoring-Engine fragt aktive Events der Familie (zwischengespeichert) und hängt deren Modifikatoren in die Kette. Event-Punkte entstehen im selben Schritt wie Boss-Schaden.
- Domäne: neues Modul `app/Domain/Events/` (Kalender-Rechner mit Osterformel, Planer, Trigger, Fortschritt, Belohnungen, Feiertags-Quelle)

**API (`/api/v1`)**
- `GET events` (aktive und demnächst), `GET events/{id}` (Fortschritt, Stufen, eigene Sammelstücke), `GET events/history`
- `POST events/{id}/advent/{day}` (Türchen öffnen), `POST events/{id}/secret-help` (Heimliche Helfer), `POST events/{id}/merchant/buy`
- Eltern: `GET|PUT family/event-settings`, `PUT members/{id}/dates`, `POST events/start` (manuell)
- Admin: Event-Editor, „Jetzt für Familie X starten“ (Staging), Vorschau des Jahreskalenders je Bundesland
- `sync` liefert aktive Events mit, damit die App Deko und Modifikatoren offline anzeigen kann

**Home Assistant**
- Neue Ereignisse `heldenhaus_event_started`, `heldenhaus_event_ended`, `heldenhaus_event_tier_reached` (mit `event_key` und Farbschema), Sensor „Aktives Event“ in der Integration
- Eingehende Schlüssel `weather_snow`, `weather_hot` lösen Welt-Events aus (YAML-Vorlage für die HA-Wetter-Entität)
- Beispiel: Kürbisnacht → Wohnzimmerlampen orange, Wichtelwerkstatt → Lichterkette an, wenn das Türchen geöffnet wurde

## 12.6 Oberfläche

- **Event-Banner** auf dem Heldenpanel mit Fortschrittsbalken der Familie (Zeit als Balken, nicht als Countdown-Zahl, §11) und Stufen-Symbolen
- **Event-Seite**: Ziel, Stufen, eigene Sammelstücke, Quest, Rezepte; im Fokus-Modus ausgeblendet
- **Deko** im Familienhaus, Farbschema und Hintergrund der Helden-Szene wechseln mit dem Event (Grafik-Fahrplan §2.9: Event-Sprites gehören zu G2/G3)
- **Kalender „Demnächst“** für Kalender-Events, in den Eltern-Einstellungen mit Schaltern je Event
- Pushes nur beim Start und beim Erreichen einer Stufe, mit Ruhezeiten (§1), ohne Inhalte (§7)

## 12.7 Tests

- Kalender: Ostersonntag für mehrere Jahre (2026-04-05, 2027-03-28, 2028-04-16), 1. Advent, Erntedank, Jahreszeiten, Zeitzone und Sommerzeit (`Carbon::setTestNow`)
- Schulferien: OpenHolidays-Antwort mit `Http::fake`, Ausfall der API (manuelle Einträge greifen), Wechsel des Bundeslands
- Trigger: Bedingungen (3 Bosse in Folge, 3 Tage alle Dailies), Abklingzeit, abgeschaltete Events, Limit gleichzeitiger Events mit Warteschlange und Verfall, Idempotenz (doppelte Domain-Events starten kein zweites Event)
- Fortschritt: Event-Punkte wie Boss-Schaden, Stufen-Belohnung genau einmal, Belohnung auch für Mitglieder im Gasthaus, Ziel bei 60 % Aktivität erreichbar (Simulation mit festem Seed)
- Regeln: keine Sterne aus Event-Zufall, Geburtstag nur mit Eintrag, Heimliche Helfer bleibt bis zum 24.12. geheim, Händler nur für Gold
- Modifikatoren: Event-Boni laufen durch die Modifikator-Kette und stapeln nicht doppelt mit gleichartigen Buffs
