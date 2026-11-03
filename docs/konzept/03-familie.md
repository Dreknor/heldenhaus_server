# 3. Neu: Familienaufgaben

## 3.1 Familie & Rollen
- **Familie** (Haushalt): Name, Wappen, Zeitzone, Einladungscode
- **Rollen**: `parent` (Admin, mehrere möglich) · `child` (mit Altersgruppe aus dem Geburtsjahr) · `guest` (Bekannte/Großeltern, die nur ansehen, Aufgaben anlegen oder loben dürfen)
- **Kinder-Login ohne E-Mail**: Elternteil erzeugt einen **QR-Code/Kopplungscode**, das Gerät des Kindes scannt ihn, danach meldet sich das Kind per PIN an. Teens können optional eine eigene E-Mail hinterlegen.
- **Familiengerät-Modus** (Tablet in der Küche): Profilauswahl mit Avatar, Wechsel per PIN

## 3.2 Arten von Familienaufgaben
| Art | Beschreibung | Beispiel |
|---|---|---|
| **Zugewiesen** | Eltern weisen einer Person zu | „Lea: Zimmer aufräumen“ |
| **Aufgabenbörse** | Wer zuerst zugreift, bekommt sie, oft mit Bonus | „Auto saugen – +5 Sterne“ |
| **Ämterplan (Rotation)** | Wechselt automatisch täglich oder wöchentlich zwischen festgelegten Personen | Spülmaschine ausräumen |
| **Teamaufgabe** | Mehrere müssen ihren Teil abhaken | „Garten: alle 3 helfen“ |
| **Eltern-Aufgaben** | Eltern haben ebenfalls sichtbare Aufgaben (Vorbild, Fairness) | „Papa: Steuererklärung“ |

## 3.3 Freigabe-Workflow
- Pro Aufgabe einstellbar: `keine Freigabe` / `Freigabe nötig` / `Foto-Nachweis + Freigabe`
- Status: `offen → erledigt (wartet) → bestätigt | abgelehnt (mit Kommentar)`
- XP und Gold gibt es sofort als „vorläufig“ (sofortiges Feedback). **Sterne gibt es erst nach Bestätigung.** Nach X Stunden ohne Reaktion wird automatisch bestätigt (einstellbar).
- Aufgaben, die über Home Assistant als erledigt erkannt werden, gelten als verifiziert (§4).
- Foto-Nachweise werden nach 7 Tagen automatisch gelöscht.

## 3.4 Zwei Währungen
- **Gold** (Spielwelt): für Ausrüstung, Futter, persönliche Belohnungen
- **Sterne** (reale Welt): nur aus bestätigten Familienaufgaben. Damit kauft man im **Familien-Belohnungsshop**, den die Eltern pflegen (z. B. „30 min Konsole“, „Kino-Abend wählen“, „1 € Taschengeld“, „Einmal Spülen aussetzen“).
- Einlösen → Eltern bestätigen → optional wird eine **Smart-Home-Aktion** ausgelöst (§4).
- Eltern können für Belohnungen Limits setzen (z. B. pro Woche) und **Taschengeld-Konten** führen (nur Buchhaltung, kein echter Zahlungsverkehr).

## 3.5 Quest-System (gemeinsam und solo)
- **Zwei Arten von Quests**:
  - **Boss-Quests**: Ein Gegner mit Lebensleiste. Erledigte Aufgaben machen Schaden, verpasste Dailies heilen ihn (statt andere zu verletzen – kindgerecht). Bosse haben **Eigenschaften**, damit nicht jeder Kampf gleich ist: eine **Schwäche** (Aufgaben einer Kategorie machen 50 % mehr Schaden, z. B. Sofa-Golem → Sport), eine **Resistenz** (halber Schaden) und **Wut** (heilt bei verpassten Dailies stärker).
  - **Sammel-Quests**: Gegenstände sammeln (z. B. 15 Federn für ein Vogelnest). Nur Aufgaben passender Kategorien zählen, schwere Aufgaben bringen doppelt.
- **Gemeinsam oder solo**: Gleichzeitig laufen höchstens eine **Familien-Quest** (starten die Eltern, alle tragen bei, Ziel wächst mit der Familiengröße) und je Person eine **Solo-Quest** (startet jede Person selbst, nur die eigenen Aufgaben zählen). Jede Aufgabe zählt für beide.
- **Quest-Tafel mit Abwechslung**: Pro Woche werden je drei Quests für Familie und Solo angeboten, passend zum Level. Die zuletzt gespielten Quests werden nicht erneut angeboten. Der Katalog liegt in `database/content/quests.json` und lässt sich ohne Code-Änderung erweitern.
- **Belohnung**: Gold, XP und ein Item für alle Beteiligten, bei Familien-Quests zusätzlich ein reales Familienziel, das die Eltern festlegen (z. B. Pizza-Abend).
- Ab Phase 7 werden Boss-Quests zu Mini-Dungeons (§2.7), deren Endgegner dann ein solcher Boss ist.
- **Familien-Feed**: wer hat was geschafft, Lob/Reaktionen (👍🔥⭐), Level-ups
- Die Familienseite konzentriert sich auf den Feed; Eltern verwalten Mitglieder, Spielregeln, Integrationen und optionales Szenenwetter unter „Familieneinstellungen“.
- **Wochenbericht** (Sonntag, Push und E-Mail an Eltern): Verteilung der Aufgaben (Fairness-Diagramm), Streaks, offene Freigaben. Energiespar-Tage erscheinen nur als Anzahl, pausierte Serien als „pausiert“, nie als Versagen (§11).
- Im **sanften Modus** (§11.3) nennt der Feed nie, wer eine Daily verpasst hat.

- **Familienrat und Mitbestimmung** (§14.4): Kinder schlagen Belohnungen und Aufgaben vor, alle stimmen über die nächste Familien-Quest ab, wöchentlicher Familienrat mit Rückblick, Danke-Runde und Entscheidungen. Eltern-Hilfen (§14.9): Aufgaben-Vorlagen nach Alter, Startassistent, Preis-Hilfe, Eltern-Anleitung.
- **Freischaltungen** (§13): Eltern schalten Funktionen für ihre Kinder früher frei, lassen sie für Kinder unter 13 später kommen oder geben neuen Mitgliedern einen Schnellstart. Freigaben, Sterne und Familienaufgaben sind nie gesperrt.
- **Familien-Events** (§12): Willkommensfest für neue Mitglieder, Hauseinweihung, Heldenjubiläum und auf Wunsch Geburtstage (nur Tag und Monat, von den Eltern eingetragen). Eltern schalten einzelne Events ab und wählen das Bundesland für Feiertage und Schulferien.

## 3.6 Bekannte & befreundete Familien
- **Familienbündnis**: Zwei Familien verbinden sich, wenn beide Elternteile zustimmen. Danach sind gemeinsame Challenges und Partys möglich.
- Kinder unter 13 sehen familienübergreifend **keinen Freitext-Chat**, nur vorgefertigte Nachrichten und Emojis. Teens bekommen Chat nur, wenn die Eltern ihn freigeben.
- **Challenges**: Aufgabenpaket (z. B. „7 Tage lesen“), Teilnahme freiwillig, Rangliste optional

## 3.7 Privatsphäre für Teens
Aufgaben können als `privat` markiert werden. Eltern sehen dann nur „1 private Gewohnheit erledigt“, nicht den Inhalt. Familienaufgaben sind nie privat.

