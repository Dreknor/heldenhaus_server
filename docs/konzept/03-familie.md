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

## 3.5 Familien-Quest (gemeinsamer Boss)
- Bis Phase 7 ist der Boss eine Lebensleiste. Ab Phase 7 sitzt er am Ende des wöchentlichen **Familien-Dungeons** (§2.7).
- Ein Boss pro Woche oder Monat (z. B. „Chaos-Drache“). **Alle** erledigten Aufgaben aller Familienmitglieder fügen ihm Schaden zu. Verpasste Dailies heilen ihn (statt die anderen zu verletzen – kindgerecht).
- Belohnung beim Sieg: ein Familienziel, das Eltern festlegen (z. B. Pizza-Abend), plus seltene Items für alle
- **Familien-Feed**: wer hat was geschafft, Lob/Reaktionen (👍🔥⭐), Level-ups
- **Wochenbericht** (Sonntag, Push und E-Mail an Eltern): Verteilung der Aufgaben (Fairness-Diagramm), Streaks, offene Freigaben

## 3.6 Bekannte & befreundete Familien
- **Familienbündnis**: Zwei Familien verbinden sich, wenn beide Elternteile zustimmen. Danach sind gemeinsame Challenges und Partys möglich.
- Kinder unter 13 sehen familienübergreifend **keinen Freitext-Chat**, nur vorgefertigte Nachrichten und Emojis. Teens bekommen Chat nur, wenn die Eltern ihn freigeben.
- **Challenges**: Aufgabenpaket (z. B. „7 Tage lesen“), Teilnahme freiwillig, Rangliste optional

## 3.7 Privatsphäre für Teens
Aufgaben können als `privat` markiert werden. Eltern sehen dann nur „1 private Gewohnheit erledigt“, nicht den Inhalt. Familienaufgaben sind nie privat.


