# 11. ADHS-Optimierung (Fokus & Alltagshilfen)

Dieses Kapitel beschreibt, wie Heldenhaus für Kinder, Jugendliche und Erwachsene mit ADHS (und ähnlichen Mustern bei den Exekutivfunktionen) angepasst wird. Die Funktionen helfen allen in der Familie. Deshalb heißen sie in der Oberfläche neutral **„Fokus & Alltagshilfen“**, niemand wird als „ADHS-Kind“ markiert und es wird **keine Diagnose gespeichert** (§7).

## 11.1 Leitprinzipien

Die Grundregeln aus §1 (sanfte Strafen, kein Streak-Stress, Kooperation vor Konkurrenz) gelten weiter. Für ADHS werden sie zugespitzt:

| Prinzip | Bedeutung | Umsetzung in Heldenhaus |
|---|---|---|
| **Keine Scham-Schleifen** | Inaktivität und vergessene Tage werden nicht bestraft. Charaktere sterben nicht, Serien brechen nicht ersatzlos ab. | **Sanfter Modus** ohne HP-Schaden (§11.3), Serien pausieren statt zurückzufallen und lassen sich reparieren (§11.3), das K.O. aus §1 bleibt die härteste Folge |
| **Sofortige Rückmeldung** | Jede Aktion gibt sofort sichtbares und hörbares Feedback. | Optimistische Anzeige in App und Web (Offline-first, §6), kurze Animation, Ton und Haptik bei jedem Abhaken, auch bei jedem Teilschritt |
| **Weniger kognitive Last** | Die Oberfläche lässt sich auf einen reizarmen Fokus-Modus reduzieren. | **Fokus-Modus** (§11.4), Animationen und Töne einzeln abschaltbar |
| **Hilfe bei Zeitblindheit** | Zeit wird nie nur als Zahl gezeigt, sondern grafisch. | **Visuelle Timer**, Fälligkeiten als Balken oder „noch 2× schlafen“ (§11.4) |
| **Autonomie vor Zwang** | Aufgaben bieten Wahlmöglichkeiten und passen sich der Tagesform an. | **Energiespar-Tag** mit Kern-Aufgaben (§11.2), Wahl zwischen mehreren kleinen Schritten, Aufgabenbörse statt Zuweisung, wo es geht |

Zielkonflikt: „Sofortiges Feedback“ und „reizarm“ widersprechen sich teilweise. Deshalb bleibt die Rückmeldung immer erhalten, nur ihre **Stärke** ist einstellbar (voll / dezent / nur Haptik).

## 11.2 Aufgaben und Alltag strukturieren

**Kopf-Ablage (Quick-Add / Brain Dump)**
- Ein überall erreichbarer Eingang: schwebender Knopf in App und Web, Home-Screen-Widget, Android-Teilen-Menü und der HA-Dienst `heldenhaus.create_task` ohne weitere Angaben.
- Eingabe als Text oder per **Sprache**. Die Spracherkennung läuft auf dem Gerät (Android-Spracheingabe), zum Server geht nur der Text, keine Audiodatei.
- Einträge landen als To-Do mit dem Status `inbox` ohne Kategorie, Frist oder Schwierigkeit. Die Pflicht-Kategorie (§2.5) wird erst beim Einsortieren verlangt, mit Vorschlag aus dem Titel.
- Einsortieren geht später mit einem Wisch („To-Do“, „Daily“, „Gewohnheit“, „löschen“). Unsortierte Einträge geben keine Materialien, aber beim Abhaken die normale Belohnung einer leichten Aufgabe, damit sich auch das spontane Erledigen lohnt.
- Kinder unter 13 können die Kopf-Ablage für sich nutzen. Eltern sehen sie nicht, solange die Einträge nicht zu Familienaufgaben werden (wie private Aufgaben, §3.7).

**Mikro-Schritte (Chunking)**
- Große Aufgaben bestehen aus **Schritten**. Technisch ist das die vorhandene Checkliste (`checklist`-JSON), neu ist, dass **jeder Schritt sofort belohnt** wird.
- Belohnung je Schritt: Anteil `1/n` der Aufgabenbelohnung (XP, Gold, Boss-Schaden), gerundet auf mindestens 1 XP. Die Summe entspricht der normalen Belohnung, Schritte lassen sich also nicht „farmen“. Der letzte Schritt schließt die Aufgabe ab und zählt für Streak, Quest-Sammelobjekte und Erfolge. Sterne für Familienaufgaben gibt es weiterhin erst nach der Freigabe der ganzen Aufgabe (§3.3).
- Die Schritte werden angezeigt **einer nach dem anderen** („Nur das hier: Wäsche aufheben“), die übrigen sind eingeklappt.
- Vorschläge für Schritte kommen aus drei Quellen, in dieser Reihenfolge:
  1. **Vorlagen** in `database/content/chunk_templates.json` (z. B. „Zimmer aufräumen“ → Wäsche aufheben, Stifte einsammeln, Bett machen, Boden frei). Sie funktionieren ohne Internet und ohne KI.
  2. Gespeicherte Schritte der Familie, wenn dieselbe Aufgabe schon einmal zerlegt wurde
  3. **KI-Aufteilung über OpenRouter** (§11.6), nur wenn die Eltern sie freigeschaltet haben
- Alle Vorschläge sind nur Vorschläge: Man übernimmt sie, ändert sie oder verwirft sie. Wie viele Schritte es höchstens gibt, ist einstellbar (Standard 3–7, für 9–12 Jahre eher 3–5).

**Energiespar-Tag („Schlechter-Tag-Joker“)**
- An Tagen mit wenig Kraft aktiviert man den Energiespar-Tag selbst, mit einem Tipp und ohne Begründung. Eltern können ihn für jüngere Kinder ebenfalls setzen.
- An diesem Tag zählen nur **Kern-Dailies** (pro Daily markierbar, Standard: keine, dann zählen die zwei leichtesten). Alle anderen Dailies gelten nicht als verpasst und verursachen keinen Schaden, die Serie bleibt stehen.
- Wer trotzdem mehr schafft, bekommt die volle Belohnung und einen kleinen **„Trotzdem geschafft“-Bonus**.
- Der Joker ist begrenzt (Standard 2 pro Woche, von den Eltern einstellbar), damit er eine Hilfe bleibt und nicht zum Dauerzustand wird. Ein länger dauernder Ausfall ist weiterhin ein Fall für den Pausen-/Gasthaus-Modus (§1).
- Familienfunktionen: Beim **Gemeinschafts-Rhythmus** (Barde, §2.4) zählt ein Energiespar-Tag als 100 %, wenn alle Kern-Dailies erledigt sind. Im **Wochenbericht** (§3.5) erscheinen Energiespar-Tage nur als Anzahl, nie als Fehler.

## 11.3 Spielmechanik und Belohnung

**Sanfter Modus (pro Person)**
- Verpasste Dailies verursachen **keinen HP-Schaden**. Stattdessen verliert der Avatar etwas „Schwung“ (eine rein optische Anzeige, die mit der nächsten erledigten Aufgabe zurückkommt).
- Der Quest-Boss heilt bei verpassten Dailies weiterhin (§3.5), im Feed steht aber nie, wer etwas verpasst hat, sondern nur „Der Boss hat sich etwas erholt“.
- Eltern schalten den sanften Modus für Kinder ein, Teens und Erwachsene für sich selbst. Er ist auch als Standard für die ganze Familie wählbar.
- Klassen, die vor Schaden schützen (Krieger-Schildwall, Heiler-Zweite-Chance), wirken in diesem Modus auf den Boss-Heileffekt bzw. die Serie statt auf HP, damit sie nicht wertlos werden.

**Serien schützen und reparieren**
- Verpasst man eine Daily, fällt die Serie **nicht auf 0**. Sie wird **pausiert**: Der alte Wert bleibt sichtbar (z. B. „Serie 12 – pausiert“) und der Serienbonus beim Gold ruht.
- Vor dem Pausieren greift wie bisher zuerst ein Streak-Schutz (`streak_shields`), dann die Zweite Chance des Heilers.
- **Erholungs-Quest**: Mit der Pause startet automatisch eine kleine Quest für genau diese Daily (Standard: die Daily an 2 der nächsten 4 Tage erledigen). Gelingt sie, läuft die Serie mit dem alten Wert weiter. Gelingt sie nicht, beginnt die Serie neu, der bisherige Rekord bleibt aber als Bestwert und für Erfolge erhalten.
- Für die Erfolge „Feuer und Flamme“ (§2.3) zählt die reparierte Serie wie eine ununterbrochene.

**Überraschungen (Zufalls-Loot)**
- Bei Routineaufgaben (Dailies und Gewohnheiten) gibt es zusätzlich zu den normalen Drops eine kleine Chance auf eine **Überraschung**: etwas Bonus-Gold, Futter, ein Material oder ein kosmetisches Teil (Hintergrund, Konfetti-Farbe).
- Die Chance steigt leicht mit jeder Erledigung ohne Überraschung und setzt danach zurück („Pech-Schutz“), damit sie verlässlich ab und zu kommt. Sie hat ein Tageslimit wie die Drops (§2.3).
- Es gelten die Regeln aus §1: nur Spielwährung und Spielgegenstände, **nie Sterne, nie Echtgeld**, keine Kisten zum Kaufen.

**Events** (§12) folgen denselben Regeln: kein Countdown-Druck, Ziele mit etwa 60 % Aktivität erreichbar, Event-Gegenstände kehren wieder, „Willkommen zurück“ für den Wiedereinstieg nach einer Pause.

**Gemeinsam statt allein**
- Familien-Quests (§3.5) sind für Menschen mit ADHS besonders geeignet, weil der Fortschritt gemeinsam entsteht. Im Fokus-Profil ist die Familien-Quest deshalb auf dem Hauptbildschirm sichtbar, die Solo-Quest ist optional.
- Teamaufgaben und „Körper-Doubling“: Eine Person kann eine andere zu einer **gemeinsamen Fokus-Zeit** einladen (beide sehen denselben Timer, §11.4). Beide bekommen beim Ende einen kleinen Bonus.

## 11.4 Oberfläche und Sinneseindrücke

**Visuelle Timer**
- Zu jeder Aufgabe lässt sich eine **Fokus-Zeit** starten (5 / 10 / 15 / 25 Minuten oder frei). Angezeigt wird eine schrumpfende farbige Scheibe nach dem Vorbild des Time Timers, keine Ziffern (Ziffern optional).
- Am Ende kommt ein sanftes Signal und die Frage „Geschafft? / Noch 5 Minuten / Pause“. Eine abgebrochene Fokus-Zeit hat keine Folgen.
- Läuft die App im Hintergrund, zeigt eine Benachrichtigung bzw. das Widget den Timer weiter (lokale Benachrichtigung, nicht über FCM).
- Fälligkeiten und der Tag selbst werden grafisch gezeigt: ein Tagesbalken bis zum Tagesbeginn (§2.2), To-Do-Fristen als „noch 2× schlafen“ und Farbverlauf.

**Fokus-Modus („Clean-Fokus“)**
- Blendet Menüs, Inventar, Shop, Feed und Nebenstatistiken auf dem Hauptbildschirm aus. Übrig bleiben: die **eine nächste Aufgabe** (bzw. der nächste Schritt), ein Timer und ein großer Abhak-Knopf.
- Die nächste Aufgabe wählt man selbst aus einer kurzen Liste von höchstens 3 Vorschlägen (fällig, Kern-Daily, kurz). Das erhält die Autonomie.
- Verlassen geht jederzeit mit einem Tipp. Pushes außer Freigaben und Timer-Ende werden währenddessen zurückgehalten.
- Gilt in App und Web. Auf dem Familiengerät kann jede Person ihren Modus selbst wählen.

**Reizarm einstellbar**
- Animationen voll / dezent / aus (folgt auch der Android-Einstellung „Animationen entfernen“), Töne und Haptik getrennt schaltbar, ruhiges Farbschema mit wenig Kontrast-Wechseln.
- Diese Einstellungen gehören zur Barrierefreiheit (Phase 8) und werden hier vorgezogen.

**Haptisches und physisches Feedback über Home Assistant** (baut auf §4 auf)
- **NFC-Sticker und Smart-Buttons** an Orten, an denen die Aufgabe passiert (Mülleimer, Spülmaschine, Terrarium): Abhaken ohne App, direkt dort, wo die Erinnerung nötig ist. Das gibt es schon über die Ereignis-Zuordnung (§4.3).
- **Lichtsignale**: Beim Abhaken leuchtet eine Lampe (z. B. Hue) kurz grün, bei einer laufenden Fokus-Zeit wechselt die Farbe langsam von Grün nach Orange. Dafür bekommt HA neue Ereignisse (§11.5).
- Erinnerungen an einem Ort statt aufs Handy (z. B. Lampe am Schreibtisch blinkt sanft, wenn die Hausaufgaben-Daily noch offen ist), damit das Handy nicht ablenkt.

## 11.5 Datenmodell, API und HA-Ereignisse

**Datenmodell**
- `user_support_settings`: `user_id`, `gentle_mode`, `focus_mode_default`, `feedback_level` (`full|subtle|haptic`), `reduce_motion`, `sound`, `haptics`, `timer_style` (`disc|digits`), `max_steps`, `low_energy_per_week`, `set_by` (selbst oder Elternteil)
- `tasks`: neuer Status `inbox` für unsortierte Einträge, `category` darf bei `inbox` leer sein, neues Feld `is_core` (Kern-Daily), `streak_paused_at`, `streak_best`
- Checkliste: jeder Eintrag bekommt `id` und `rewarded_at`, damit ein Schritt nur einmal belohnt wird (auch offline mit `client_uuid`)
- `low_energy_days`: `user_id`, `date`, `set_by`
- `recovery_quests`: `user_id`, `task_id`, `needed`, `done`, `streak_to_restore`, `expires_at`, Status
- `focus_sessions`: `user_id`, `task_id` null, `planned_minutes`, `started_at`, `ended_at`, Ergebnis (`done|extended|aborted`), `shared_with` (gemeinsame Fokus-Zeit)
- `surprise_counters` in `user_stats` (Pech-Schutz-Zähler, Überraschungen heute)
- `ai_requests`: `family_id`, `user_id`, Zweck, Modell, Tokens, Kosten, Zeitstempel (für Budget und Prüfprotokoll, ohne Inhalt)

**API (`/api/v1`)**
- `POST inbox` (Titel), `POST inbox/{id}/sort` (Typ, Kategorie, Schwierigkeit)
- `POST tasks/{id}/steps/{step}/score` (mit `client_uuid`), `POST tasks/{id}/steps/suggest` (Vorlage, Familie, KI)
- `POST me/low-energy`, `DELETE me/low-energy`
- `POST focus-sessions`, `POST focus-sessions/{id}/finish|extend|abort`, `POST focus-sessions/{id}/invite`
- `GET me/support-settings`, `PUT me/support-settings` (Eltern auch für ihre Kinder)

**Domäne**: neues Modul `app/Domain/Support/` (Einstellungen, Energiespar-Tag, Erholungs-Quests, Fokus-Zeiten, Schritt-Vorschläge). Die Scoring-Engine bekommt passende Modifikatoren (`GentleMode`, `LowEnergyDay`, `StepReward`, `Surprise`) in der vorhandenen Modifikator-Kette (§2.4), der Tageswechsel prüft Kern-Dailies, pausiert Serien und startet Erholungs-Quests.

**Neue HA-Ereignisse** (über die ausgehenden Webhooks, §4.2): `heldenhaus_step_completed`, `heldenhaus_focus_started`, `heldenhaus_focus_ended` (mit Restzeit und Ergebnis), `heldenhaus_low_energy_day`. Die HA-Integration bekommt einen `sensor` „Fokus-Zeit“ je Mitglied (Restminuten) und YAML-Vorlagen für die Lichtsignale.

## 11.6 KI-Aufteilung über OpenRouter (optional)

Große Aufgaben in sinnvolle Schritte zu zerlegen, fällt gerade Menschen mit ADHS schwer. Eine KI kann dafür Vorschläge machen. Angebunden wird sie über **OpenRouter**, damit das Modell austauschbar bleibt.

- **Aus, bis die Eltern es einschalten** (pro Familie). Ohne KI funktionieren Vorlagen und gespeicherte Schritte weiter.
- Der Aufruf läuft **nur auf dem Server** (Queue-Job, ausgehendes HTTPS ist auf dem Webhosting erlaubt). Der API-Schlüssel liegt in `.env` (`OPENROUTER_API_KEY`, `OPENROUTER_MODEL`), nie in der App.
- **Datensparsam**: Gesendet werden nur Aufgabentitel, Notiz (optional), Altersgruppe und gewünschte Schrittanzahl, **keine Namen, keine Familien- oder Gesundheitsdaten**. In OpenRouter werden nur Anbieter ohne Datenspeicherung und ohne Training zugelassen (Provider-Einstellungen bzw. `data_collection: deny`).
- Ein fester System-Prompt sorgt für kindgerechte, kurze Schritte (höchstens `max_steps`, je ein Verb, keine Gefahren wie Chemikalien). Die Antwort wird als JSON geprüft, ungültige Antworten werden verworfen.
- **Kosten und Missbrauch**: Ratenbegrenzung pro Person, Monatsbudget pro Familie (`ai_requests`), Ergebnis wird pro Aufgabentitel zwischengespeichert. Kinder unter 13 bekommen KI-Vorschläge nur, wenn die Eltern das zusätzlich erlauben. Freitext-Ausgaben der KI erscheinen nie ungeprüft im Familien-Feed.
- Übernommene Schritte werden als Familienvorlage gespeichert, damit dieselbe Aufgabe beim nächsten Mal ohne KI zerlegt wird.

## 11.7 Tests

- Schritt-Belohnung: Summe der Teilbelohnungen = normale Belohnung, jeder Schritt nur einmal (auch bei doppelter `client_uuid`), Sterne erst nach Freigabe der ganzen Aufgabe
- Sanfter Modus: kein HP-Schaden, Boss heilt trotzdem, Feed nennt keine Namen, Schildwall/Zweite Chance wirken auf Serie und Boss
- Serien: Pausieren statt Zurücksetzen, Reihenfolge Streak-Schutz → Zweite Chance → Pause, Erholungs-Quest stellt den alten Wert wieder her bzw. läuft ab (mit `Carbon::setTestNow`)
- Energiespar-Tag: nur Kern-Dailies zählen, Wochenlimit, Gemeinschafts-Rhythmus und Wochenbericht
- Überraschungen: Pech-Schutz und Tageslimit mit festem Zufalls-Seed, nie Sterne
- Kopf-Ablage: `inbox` ohne Kategorie erlaubt, Einsortieren verlangt Kategorie, Privatsphäre gegenüber Eltern
- KI: OpenRouter mit `Http::fake`, gesendete Daten enthalten keine Namen, Budget und Ratenbegrenzung greifen, ungültiges JSON wird verworfen, ohne Freigabe kein Aufruf
- Praxistest: Im Familientest (§9) gezielt prüfen, ob Fokus-Modus, Timer und Energiespar-Tag im Alltag genutzt werden, und Standardwerte (Schrittzahl, Joker pro Woche) anpassen
