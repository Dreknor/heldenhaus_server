# 14. Langzeitmotivation und Zielerreichung

Die Kapitel 1–13 tragen die ersten Wochen sehr gut. Dieses Kapitel ergänzt, was Kinder und Eltern über **Monate und Jahre** dabeihält und was dafür sorgt, dass die **echten Ziele** erreicht werden: selbständig erledigte Aufgaben, feste Gewohnheiten und weniger Streit. Grundlage ist eine Prüfung des Konzepts gegen Selbstbestimmungstheorie (§1), Gewohnheitsbildung (Auslöser → Routine → Belohnung → Automatisierung) und Zielsetzung.

**Leitgedanke**: Heldenhaus ist erfolgreich, wenn ein Kind eine Aufgabe irgendwann **nicht mehr** braucht. Deshalb gibt es neben Belohnungen auch Wege, Gewohnheiten abzuschließen und Belohnungen langsam auszuschleichen.

| Abschnitt | Baustein | Priorität |
|---|---|---|
| §14.1 | Ziele und Wochenziele | A |
| §14.2 | Erinnerungen und Wenn-dann-Pläne | A |
| §14.3 | Gewohnheit meistern, Belohnungen ausschleichen | A |
| §14.4 | Familienrat und Mitbestimmung | A |
| §14.5 | Persönlicher Rückblick | B |
| §14.6 | Schwierigkeit passt sich an | A |
| §14.7 | Langzeitziele nach Level 14 | B |
| §14.8 | Chronik des Heldenhauses | B |
| §14.9 | Hilfen für Eltern | B |
| §14.10 | Erfolgsmessung | A |
| §14.11 | Altersübergänge | C |

## 14.1 Ziele und Wochenziele

Aufgaben sind Mittel, Ziele geben ihnen Sinn. Bisher verspricht §1 „Wochenziele statt täglicher Perfektion“, ohne sie auszuarbeiten.

**Drei Arten von Zielen**

| Art | Beispiel | Messung |
|---|---|---|
| **Wochenziel** | „5 von 7 Abenden Zähne putzen“, „3× diese Woche Sport“ | Anzahl Erledigungen einer Daily oder Gewohnheit im Wochenfenster |
| **Meilenstein-Ziel** | „Fahrrad ohne Stützräder“: 1. Laufrad-Runde, 2. mit Hilfe 20 m, 3. allein bis zur Ecke, 4. allein um den Block | 3–5 Meilensteine, abgehakt von der Person (bei Kindern unter 13 bestätigt ein Elternteil) |
| **Zähl-Ziel** | „10 Bücher bis zu den Sommerferien“, „30 Tage Hausaufgaben vor 17 Uhr“ | Zähler, verknüpft mit einer Aufgabe oder von Hand |

**Regeln**
- Jedes Ziel hat ein **„Warum“** in eigenen Worten (Pflichtfeld mit Vorschlägen: „damit ich allein zur Schule fahren kann“). Es steht auf der Ziel-Karte und im Rückblick.
- **Höchstens 3 aktive Ziele pro Person** (weniger kognitive Last, §11). Weitere Ideen landen in der Kopf-Ablage.
- Ziele dürfen ein Enddatum haben, müssen aber nicht. Ein verpasstes Enddatum führt zur Frage „Verlängern, anpassen oder loslassen?“, nie zu einer Strafe.
- **Wochenziele** werten am Wochenende aus. Am Energiespar-Tag (§11.2) gilt der Tag als „ausgelassen“, das Ziel verkleinert sich um diesen Tag. Eine Woche mit erreichtem Wochenziel hält die Serie einer Daily, auch wenn einzelne Tage fehlen. So wird „Wochenziele statt täglicher Perfektion“ technisch umgesetzt.
- **Belohnung**: Jeder Meilenstein bringt Gold und XP einer schweren Aufgabe und einen Feed-Eintrag, das erreichte Ziel ein Abzeichen und einen Platz in der Trophäenhalle. Eltern können ein Ziel zusätzlich mit Sternen oder einem realen Wunsch verknüpfen.
- **Unterstützer**: Ein Familienmitglied kann „Ich helfe dir“ drücken. Es sieht dann den Fortschritt und bekommt einen Hinweis, wenn ein Meilenstein erreicht ist.
- **Familienziele**: Bauprojekte, Quest-Belohnungen und Event-Ziele erscheinen im selben Ziel-Bereich, damit gemeinsame und eigene Ziele an einem Ort sichtbar sind.
- **Vorlagen** je Altersgruppe in `database/content/goal_templates.json` (etwa 40 Ziele: Schwimmabzeichen, Zimmer eine Woche ordentlich, Taschengeld sparen, Instrument üben …).
- Teens können Ziele privat markieren (§3.7).

## 14.2 Erinnerungen und Wenn-dann-Pläne

Gewohnheiten entstehen an festen Auslösern. Ein Wenn-dann-Plan („Wenn ich vom Abendessen aufstehe, räume ich meinen Teller weg“) verdoppelt laut Forschung zur Umsetzungsabsicht ungefähr die Erfolgsquote.

- Jede Daily und Gewohnheit bekommt optional einen **Anker** (Auswahl oder freier Text: „nach dem Frühstück“, „wenn ich von der Schule komme“) und eine **Erinnerungszeit**. Beim Anlegen fragt die App freundlich „Wann und wo machst du das?“.
- **Erinnerungen plant die App lokal** auf dem Gerät (lokale Benachrichtigungen aus den Daten des Abgleichs). Dadurch bleiben die Inhalte auf dem Gerät und es geht nichts über Google (§7). Für Web/PWA gibt es eine inhaltsleere Push-Nachricht („Eine Erinnerung wartet“).
- **Am Ort über Home Assistant**: Optional sendet der Server zur Erinnerungszeit das Ereignis `heldenhaus_reminder` (Mitglied, `task_key`, nie bei privaten Aufgaben). Eine HA-Automation lässt z. B. das Badlicht sanft blau leuchten. Erledigt man die Aufgabe, geht das Licht wieder aus (`task_completed`).
- **Grenzen**: höchstens eine Erinnerung pro Aufgabe und Tag, höchstens 5 pro Person und Tag, Ruhezeiten (§1) und Fokus-Modus (§11.4) halten Erinnerungen zurück. „Später“ verschiebt um 15 oder 60 Minuten.
- **Lernen statt nerven**: Wird eine Erinnerung dreimal hintereinander ignoriert, fragt die App einmal: „Passt die Zeit noch?“ mit den Antworten „andere Zeit“, „anderer Anker“, „Erinnerung aus“ und „Aufgabe pausieren“.

## 14.3 Gewohnheit meistern und Belohnungen ausschleichen

**Abgrenzung**: Tageslimit, sinkende Erfahrung bei häufigem Gelingen und die Farbcodierung der Gewohnheiten (wie bei Habitica) sind in §2.1 und §2.2 geregelt. Dieser Abschnitt regelt, was danach kommt: der **Abschluss** einer Gewohnheit, die sitzt.

**Meistern**
- **Kriterium**: Eine Daily oder positive Gewohnheit ist an mindestens 60 Tagen aktiv und wurde in den letzten 60 Tagen zu mindestens 80 % erledigt (Energiespar-Tage und Gasthaus-Tage zählen nicht mit). Zusätzlich muss die höchste Farbstufe aus §2.2 erreicht sein (`best`, `value` ≥ 10). Für Gewohnheiten gilt außerdem: mindestens 5 positive Tage pro Woche im Zeitraum, das Tageslimit aus §2.1 zählt nicht mit.
- **Angebot** (eine Karte, keine Pflicht): „Zähneputzen sitzt! Willst du es meistern?“ mit drei Antworten:
  - **Meistern**: Die Aufgabe verlässt die tägliche Liste, bekommt das Abzeichen „Meister: Zähneputzen“, einen Platz in der Trophäenhalle und einen Feed-Eintrag. Vier Wochen lang fragt die App einmal pro Woche „Klappt es noch?“. Ein „Nein“ holt die Aufgabe ohne Kommentar zurück.
  - **Seltener**: Die Daily wird zu einem Wochenziel (z. B. 5 von 7), die tägliche Liste wird kürzer.
  - **Weitermachen**: alles bleibt, die Frage kommt frühestens in 30 Tagen wieder.
- **Familienaufgaben** werden nicht gemeistert (sie bleiben nötig), dafür gilt das Ausschleichen.

**Belohnungen ausschleichen („Ehrensache“)**
- Bei einer Familienaufgabe mit Sternen, die eine Person 30-mal bestätigt erledigt hat (mindestens 80 % im Zeitraum), schlägt die App den Eltern vor, die Sterne in Stufen zu senken: −25 % alle 2 Wochen bis 0.
- Bei 0 wird die Aufgabe **Ehrensache**: Sie bringt weiter XP und Gold, im Feed ein „Danke“ der Familie und **Familien-Ehre** (Punkte für das aktuelle Familienziel oder Bauprojekt).
- Die Eltern entscheiden, die App ändert nichts allein. Das Kind wird **vorher** informiert („Ab nächster Woche ist Spülmaschine Ehrensache. Dafür bekommst du das Abzeichen ‚Verlässlich‘.“). So entsteht kein Gefühl von Wegnehmen.
- Sterne werden frei für neue oder schwerere Aufgaben. Das schützt vor dem Überrechtfertigungs-Effekt (§1) und vor einer Sterne-Inflation (§14.9).

## 14.4 Familienrat und Mitbestimmung

Autonomie ist bisher für Teens stark (private Aufgaben, eigene Ziele), für 9–12 aber schwach. Kinder sollen mitreden können.

**Vorschläge der Kinder**
- **Belohnungen vorschlagen**: Kinder schlagen Belohnungen für den Sterne-Shop vor (Titel, Wunschpreis). Eltern nehmen sie an, ändern den Preis oder lehnen freundlich mit Begründung ab.
- **Aufgaben vorschlagen**: für die Aufgabenbörse oder für Eltern („Papa: Fahrrad reparieren“), Eltern bestätigen.
- **Abstimmung über die nächste Familien-Quest**: Die Quest-Tafel bietet 3 Familien-Quests, jedes Mitglied hat eine Stimme, bei Gleichstand entscheiden die Eltern.
- Kinder unter 13 wählen Texte aus Vorlagen oder schreiben frei, wenn die Eltern das erlauben.

**Familienrat** (wöchentlich, Standard Sonntag 18 Uhr, etwa 10 Minuten, am besten am Familiengerät)
1. **Rückblick**: Familien-Wochenleistung als Bild (Boss besiegt, Bauprojekt, Event-Stufe), ohne Ranglisten
2. **Danke-Runde**: Jedes Mitglied wählt eine Person und einen Grund zum Danken (Vorlagen oder frei), das landet im Feed
3. **Vorschläge entscheiden**: offene Belohnungs- und Aufgabenvorschläge
4. **Abstimmung**: nächste Familien-Quest, ggf. Event-Optionen
5. **Ein Wunsch für nächste Woche** je Person (wird zum Wochenziel oder zur Notiz)

Ergebnis: ein Feed-Eintrag „Familienrat vom 25.10.“. Eine Erinnerung kommt eine Stunde vorher. Der Wochenbericht der Eltern (§3.5) ist die Vorbereitung dafür. Der Rat ist freiwillig, ausgelassene Räte haben keine Folgen.

## 14.5 Persönlicher Rückblick

- **Wochenrückblick** für jede Person (Montagmorgen, im Heldenpanel): drei Höhepunkte der Woche, erreichte Wochenziele, längste Serie, stärkster Tag, Fortschritt bei Zielen. **Verglichen wird nur mit sich selbst** („12 Aufgaben mehr als letzte Woche“). Gab es weniger, steht dort Positives („Du hast 4 Tage geschafft“), nie ein Minus.
- **Monatskarte**: ein Bild mit Kalender-Heatmap der eigenen Aktivität, neuen Haustieren, Abzeichen und dem Satz „Darauf kannst du stolz sein“. Teilbar in den Familien-Feed.
- **Jahresrückblick** zum Jahreswechsel (Raunächte, §12.4).
- Eltern sehen die Rückblicke ihrer Kinder, private Inhalte von Teens nur als Anzahl (§3.7).
- Zieht einen Teil der Statistik aus Phase 8 vor.

## 14.6 Schwierigkeit passt sich an

Die 60-%-Regel aus §12.1 gilt bisher nur für Events. Sie wird für alle gemeinsamen Ziele übernommen.

- **Aktivitätswert** je Mitglied: Median der Punkte pro Tag (Boss-Schaden-Formel) der letzten 14 Tage, ohne Gasthaus-Tage. Neue Mitglieder starten mit einem Standardwert je Altersgruppe.
- **Boss-HP** = Summe der Aktivitätswerte × Quest-Tage × Faktor. Der Faktor kommt aus der Wahl der Familie: leicht 0,5 · normal 0,65 · schwer 0,8. Dazu gibt es Unter- und Obergrenzen je Boss aus `quests.json`.
- **Sammel-Quests** und **Event-Ziele** werden genauso berechnet.
- Berechnet wird **beim Start** einer Quest bzw. eines Events, nie mittendrin. So bleibt es fair und nachvollziehbar.
- Scheitert die Familie zweimal hintereinander knapp, schlägt die Quest-Tafel automatisch „leicht“ vor. Gelingt es dreimal deutlich, bietet sie „schwer“ mit besserer Beute an.

## 14.7 Langzeitziele nach Level 14

Die Freischaltungen (§13) enden bei Level 14, also nach etwa 6 Wochen. Danach tragen vier Stränge:

- **Fähigkeiten-Stufen**: Jede Klasse bekommt bei Level 15, 20 und 25 eine weitere Fähigkeit (in §2.4 angelegt), als Inhalt in `classes.json`, z. B. Krieger 15 „Schutzschild für ein Familienmitglied“, Barde 20 „Ständchen: +1 Fackel für alle“.
- **Sammelalbum**: Haustiere, Reittiere, Monster-Lexikon, Möbel, Rezepte und Abzeichen mit Fortschritt in Prozent und Belohnungen bei 25 / 50 / 75 / 100 % je Album. Event-Gegenstände sind gekennzeichnet und kommen wieder (§12.1), es bleibt also immer etwas zu sammeln.
- **Meisterschaften** pro Aufgabenkategorie (Haushalt, Schule & Lernen, Sport & Draußen, Tiere & Natur, Kreativ) in den Stufen I–V nach Anzahl erledigter Aufgaben (z. B. 25 / 100 / 250 / 500 / 1000), mit Titel unter dem Namen („Haushalts-Meisterin III“).
- **Heldenjahr**: eine kostenlose Jahresleiste mit 50 Stufen, die zum Schuljahresbeginn neu startet (Datum aus den Schulferien, §12.3). Jahrespunkte entstehen aus Aufgaben, Zielen und Events. Belohnungen sind Kosmetik, Möbel und ein Jahres-Abzeichen. Stufe 50 ist mit etwa 50 % der üblichen Aktivität erreichbar. Es gibt keine Bezahl-Stufe.
- **Titel** bei Level 20, 30, 50, 75 und 100. Es gibt kein Level-Maximum.

## 14.8 Chronik des Heldenhauses

Eine Geschichte gibt den Monstern, Quests und dem Haus einen Zusammenhang.

- **12 Kapitel** zum Start: Wie die Familie das alte Heldenhaus findet, warum das Staubmonster sich dort eingenistet hat, wer der Drachenfürst ist usw. Jedes Kapitel hat eine Seite Text und ein Bild aus den vorhandenen Grafiken.
- **Freischaltung** über Level (2, 4, 6 … 24) und besiegte Bosse. Kapitel sind **personalisiert** mit Familienname, Wappen und Heldennamen.
- **Vorlesen** auf dem Gerät (Text-to-Speech von Android, ohne Server) für 9–12.
- Inhalt in `database/content/chronicle.json`. Neue Kapitel kommen mit Events oder als Staffel und brauchen kein App-Update.
- Der Familienrat (§14.4) kann ein neues Kapitel gemeinsam „aufschlagen“.

## 14.9 Hilfen für Eltern

- **Aufgaben-Vorlagen nach Alter**: etwa 80 Vorlagen in `database/content/task_templates.json`, je Bereich (Zimmer, Küche, Wäsche, Tiere, Schule, Selbstfürsorge) und Altersstufe (9–10, 11–12, 13–14, 15–16), mit Schwierigkeit, Kategorie, Sterne-Vorschlag und Schritt-Vorlage (§11.2).
- **Startassistent**: beim Anlegen eines Kindes 3–5 Aufgaben aus den Vorlagen wählen, nicht mehr. Die App weist darauf hin, dass weniger am Anfang mehr ist. Danach kommt höchstens eine neue Aufgabe pro Woche dazu.
- **Preis-Hilfe für Belohnungen**: Beim Anlegen zeigt die App, wie lange ein Kind dafür arbeitet („etwa 3 Tage Familienaufgaben“), berechnet aus seinem Sterne-Schnitt der letzten 14 Tage.
- **Sterne-Polster**: Hat ein Kind mehr Sterne, als es in 4 Wochen verdient, schlägt die App vor, ein größeres Ziel in den Shop zu stellen oder Sterne auszuschleichen (§14.3).
- **Eltern-Anleitung** (6 kurze Karten im Hilfebereich, beim ersten Login einmal angeboten):
  1. Wenige Aufgaben, altersgerecht
  2. Den Einsatz loben, nicht die Person („Du hast drangeblieben“ statt „Du bist toll“)
  3. Freigaben zügig erledigen (innerhalb eines Tages)
  4. Sterne nie als Strafe wegnehmen
  5. Kinder mitentscheiden lassen (§14.4)
  6. Selbst mitspielen und Vorbild sein

## 14.10 Erfolgsmessung

Ohne Messung lässt sich nicht sagen, ob Heldenhaus seine Ziele erreicht. Alle Daten bleiben in der eigenen Datenbank (§7), es gibt keine Analyse-Werkzeuge von Dritten.

**Ausgangslage und Vergleich** (Eltern, je 1–5)
1. Wie oft werden Aufgaben ohne Erinnerung erledigt?
2. Wie oft gibt es Streit um Aufgaben?
3. Wie selbständig sind die Kinder im Alltag?

Beim Start und nach 8 Wochen, danach alle 3 Monate. Das Ergebnis erscheint als einfacher Vorher-Nachher-Vergleich.

**Stimmungs-Check** (alle 4 Wochen, jedes Mitglied, 10 Sekunden): „Macht Heldenhaus dir noch Spaß?“ mit 5 Smileys und optional „Was soll besser werden?“ (Auswahl: zu viele Aufgaben, zu langweilig, zu schwer, zu wenig Belohnung, etwas anderes).

**Kennzahlen** (je Familie, Eltern-Ansicht „So läuft's“)

| Kennzahl | Bedeutung |
|---|---|
| Aktive Tage pro Woche je Mitglied | Bindung |
| Anteil erledigter Dailies (gleitend 14 Tage) | Gewohnheitsstärke |
| Familienaufgaben ohne Nachfrage (erledigt vor Fälligkeit) | Selbständigkeit |
| Gemeisterte Gewohnheiten | Verinnerlichung (§14.3) |
| Erreichte Ziele und Wochenziele | Zielerreichung (§14.1) |
| Längste Pause und Rückkehr | Rückfall und Wiedereinstieg |
| Sterne verdient / eingelöst | Gesundheit der Sterne-Wirtschaft |

**Entscheidungsregeln** (Hinweise an die Eltern, nie automatische Änderungen)
- Aktivität eines Kindes sinkt 2 Wochen in Folge um mehr als 30 % → Hinweis mit 3 Vorschlägen: weniger Aufgaben, Energiespar-Tage anbieten, Thema für den Familienrat
- Stimmung zweimal ≤ 2 → Vorschlag, die genannten Gründe im Familienrat zu besprechen, und passende Einstellungen (z. B. leichtere Quests, §14.6)
- Mehr als 12 aktive Dailies bei einem Kind unter 13 → Hinweis auf Überforderung und „Seltener“ (§14.3)

**Für die Weiterentwicklung** (Server-Admin): nur zusammengefasste Zahlen über alle Familien (Anzahlen und Mittelwerte, keine Titel, keine Namen), um Standardwerte wie Drop-Raten, Boss-Faktor oder Schrittzahl anzupassen.

## 14.11 Altersübergänge

- **Mit 13** (Jahreswechsel des Geburtsjahrs bzw. Bestätigung durch die Eltern): Karte „Du bist jetzt Teen“ mit Wahl des Themes, Erklärung der privaten Aufgaben (§3.7) und, wenn die Eltern es erlauben, Chat (§3.6). Klassen und Fortschritt bleiben.
- **Ab 16**: Angebot eines **Erwachsenenprofils** mit eigener Anmeldung, sachlicherem Ton und „Alles sofort zeigen“ (§13). Eltern sehen dann nur noch Familienaufgaben und Sterne. Taschengeld-Konto und Erfolge bleiben.
- Ziel: Ältere Jugendliche sollen nicht aus Peinlichkeit aussteigen, sondern Heldenhaus als eigenes Werkzeug weiter nutzen können.

## 14.12 Datenmodell und API

**Inhalt**: `goal_templates.json`, `task_templates.json`, `chronicle.json`, Erweiterungen in `classes.json` (Stufen 15/20/25) und `achievements.json` (Meisterschaften, Alben)

**Tabellen**
- `goals` (Person oder Familie, Art `weekly|milestone|count`, Titel, `why`, Ziel, Fenster/Enddatum, Status, `private`), `goal_milestones`, `goal_links` (verknüpfte Aufgaben), `goal_supporters`
- `tasks` bekommt `anchor`, `remind_at`, `remind_ha`, `ignored_reminders`, `mastered_at`, `mastery_check_until`, `star_fade_step`
- `proposals` (Art `reward|task`, von, Status, Begründung), `votes` (Familie, Anlass, Optionen, Stimmen), `family_councils` (Datum, Danke-Einträge, Entscheidungen, Wünsche)
- `activity_scores` (Person, Datum, Punkte) für den Aktivitätswert und den Rückblick
- `hero_years` (Person, Schuljahr, Punkte, Stufe), `album_progress`, `mastery_levels` (Person, Kategorie, Stufe)
- `chronicle_unlocks` (Person, Kapitel, freigeschaltet, gelesen)
- `surveys` (Person, Art `baseline|mood`, Datum, Antworten), `family_metrics` (täglich zusammengefasst)

**API (`/api/v1`)**
- Ziele: `GET|POST goals`, `PUT goals/{id}`, `POST goals/{id}/milestones/{m}/done`, `POST goals/{id}/support`
- Erinnerungen: Felder an `tasks`, `POST tasks/{id}/reminder/snooze`, `POST tasks/{id}/reminder/feedback`
- Meistern: `POST tasks/{id}/master`, `POST tasks/{id}/less-often`, `POST tasks/{id}/still-ok`; Eltern: `POST tasks/{id}/fade-stars`
- Mitbestimmung: `GET|POST proposals`, `POST proposals/{id}/accept|decline`, `GET|POST votes/{id}`, `GET|POST family-council`
- Rückblick: `GET me/review/week`, `GET me/review/month`
- Langzeit: `GET me/album`, `GET me/masteries`, `GET me/hero-year`, `GET chronicle`
- Eltern: `GET task-templates`, `GET family/metrics`, `POST surveys`, `GET family/metrics/compare`
- HA: Ereignis `heldenhaus_reminder` (§4.2)

**Ablauf**: Wochenauswertung der Ziele und Rückblicke im Scheduler (Sonntag 23:30 bzw. Montag 05:00 in der Zeitzone der Familie), Meister-Prüfung im Tageswechsel, `family_metrics` nächtlich. Domäne: neue Module `app/Domain/Goals/` (Ziele, Wochenziele, Meistern, Ausschleichen), `app/Domain/Council/` (Vorschläge, Abstimmungen, Familienrat), `app/Domain/Insights/` (Aktivitätswert, Rückblick, Kennzahlen, Umfragen); Chronik, Album, Meisterschaften und Heldenjahr in `Achievements/`.

## 14.13 Tests

- Wochenziel: Zählung im Fenster, Energiespar-Tag verkleinert das Ziel, erreichtes Wochenziel hält die Serie, Zeitzone und Wochenwechsel (`Carbon::setTestNow`)
- Höchstens 3 aktive Ziele, Meilenstein unter 13 nur mit Bestätigung, Belohnung genau einmal
- Erinnerungen: Tages- und Personenlimit, Ruhezeiten, Fokus-Modus, private Aufgaben ohne HA-Ereignis, Frage nach drei ignorierten Erinnerungen
- Meistern: Kriterium 60 Tage / 80 % ohne Energiespar- und Gasthaus-Tage, Farbstufe `best` (`value` ≥ 10), „Klappt es noch?“ holt die Aufgabe zurück
- Ausschleichen: Vorschlag erst nach 30 Bestätigungen, Stufen −25 %, nie ohne Elternentscheidung, Ehrensache gibt Familien-Ehre
- Mitbestimmung: Vorschlag → Annahme/Ablehnung, eine Stimme pro Mitglied, Gleichstand entscheiden Eltern
- Schwierigkeit: Boss-HP aus Aktivitätswert und Faktor mit Grenzen, Berechnung nur beim Start, Vorschlag „leicht“ nach zwei knappen Niederlagen
- Heldenjahr: Neustart zum Schuljahresbeginn je Bundesland, Stufe 50 mit 50 % Aktivität erreichbar (Simulation)
- Kennzahlen und Entscheidungsregeln: Hinweise bei −30 % über 2 Wochen und bei Stimmung ≤ 2, zusammengefasste Admin-Zahlen enthalten keine Titel und Namen
