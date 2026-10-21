# 2. Spielmechanik (Kern wie Habitica, kindgerecht angepasst)

## 2.1 Aufgabentypen
- **Gewohnheiten (Habits)**: +/− beliebig oft, Wert verschiebt die Farbe (rot → blau)
- **Tägliche Aufgaben (Dailies)**: Wiederholungsregeln (täglich, Wochentage, alle n Tage, monatlich), Checklisten, Streak, optional als **Kern-Daily** markiert (zählt auch am Energiespar-Tag, §11.2)
- **To-Dos**: einmalig, optional mit Fälligkeit und Checkliste. Schnell notierte Einträge aus der **Kopf-Ablage** warten als `inbox` ohne Kategorie aufs Einsortieren (§11.2).
- **Checklisten als Mikro-Schritte**: Jeder abgehakte Schritt bringt sofort seinen Anteil `1/n` der Belohnung, die Summe bleibt gleich (§11.2).
- **Belohnungen**: persönliche Belohnungen (für Gold) und **Familienbelohnungen** (für Sterne, siehe §3)

## 2.2 Werte und Formeln (in `config/game.php` einstellbar)
- Jede Aufgabe hat einen `value` (Start 0, begrenzt auf −47,27 … 21,27).
- `delta = 0.9747 ^ value` → häufig erledigte Aufgaben bringen weniger, vernachlässigte mehr.
- Schwierigkeit: trivial 0,1 · leicht 1 · mittel 1,5 · schwer 2
- `XP = round(delta × Schwierigkeit × 6 × (1 + INT-Bonus))`
- `Gold = delta × Schwierigkeit × (1 + PER-Bonus) × Streak-Bonus`
- XP bis zum nächsten Level: `round((L² × 0,25 + 10L + 139,75) / 10) × 10`
- Max. 50 HP; verpasste Dailies verursachen Schaden `delta × Schwierigkeit × 2 × (1 − KON-Bonus) × Altersfaktor`, wobei der Altersfaktor für 9–12 bei 0,5 und für 13–16 bei 0,75 liegt (von Eltern änderbar)
- **Tageswechsel („Cron“)** pro Person mit eigener Zeitzone und einstellbarem Tagesbeginn (z. B. 04:00). Er wird beim ersten App- oder Webaufruf des Tages ausgeführt (lazy) und zusätzlich per Scheduler-Batch. Vor der Auswertung kommt der Dialog „Gestern noch erledigt?“ (wie im Original).
- **Sanfter Modus und Energiespar-Tag** (§11.3, §11.2): Im sanften Modus verursachen verpasste Dailies keinen HP-Schaden. Am Energiespar-Tag zählen nur Kern-Dailies. Verpasste Dailies pausieren die Serie, statt sie auf 0 zu setzen, eine Erholungs-Quest kann sie wiederherstellen.

## 2.3 Progression
- **Freischaltungen nach Level** (§13): Shop ab 3, Funde und Haustiere ab 4, Quests ab 5, Familienhaus ab 6, Garten ab 7, Werkstatt ab 8, Bauprojekte ab 9, Dungeons ab 10, Familien-Dungeon ab 12, Bündnis-Funktionen ab 14, jeweils mit kurzer Erklärung und Mini-Aufgabe
- **Level & Attribute** mit kindgerechten Namen: **Kraft** (Boss-Schaden), **Köpfchen** (XP), **Ausdauer** (weniger Schaden), **Spürsinn** (Gold und Drops). Punkte verteilt man ab Level 2.
- **Eigene Spielklassen** statt Krieger/Magier/Heiler/Schurke, siehe §2.4
- **Avatar-Editor**: Körper, Haare, Hautfarbe, Hintergründe; Ausrüstung pro Slot (Kopf, Rüstung, Waffe, Schild, Accessoire)
- **Drops**: Eier + Schlüsselpulver → Haustiere → füttern → Reittiere. Die Drop-Chance ist begrenzt (Tageslimit). Dazu kommen bei Routineaufgaben kleine **Überraschungen** mit Pech-Schutz, nur in Spielwährung (§11.3).
- **Erfolge/Abzeichen** in Stufen (z. B. „Fleißige Hände I–VII“, „Feuer und Flamme“ für Serien, „Monsterjäger“, „Tierfreund“). Sie werden aus vorhandenen Spieldaten berechnet, bringen je Stufe etwas Gold, erscheinen im Familien-Feed und bleiben erhalten, auch wenn ein Abschluss später zurückgenommen wird. Familienaufgaben mit Freigabe zählen erst nach der Bestätigung. Der Katalog liegt in `database/content/achievements.json`; neue Stufen vergibt `php artisan heldenhaus:achievements-sync` nachträglich (ohne Feier).
- **Events** (§12): 11 Kalender-Events (Frühlingserwachen, Eiersuche, Rollentausch-Tag, Mittsommer, Sommerferien-Abenteuer, Schulstart, Erntefest, Kürbisnacht, Laternenfest, Wichtelwerkstatt, Raunächte) und 10 ausgelöste Events (Erster Schnee, Hitzewelle, Drachenfürst, Goldene Woche, Willkommensfest, Hauseinweihung, Heldenjubiläum, Geburtstag, Willkommen zurück, Wandernder Händler). Dazu kommen 16 kleine Zufallsereignisse nur zum Spaß (Diebische Elster, Ausgebüxtes Huhn, Sternschnuppe …). Sie nutzen Quests, Drops, Rezepte, Möbel, Dungeon-Themen und die Modifikator-Kette.
- **Spielinhalte als versionierte JSON-Dateien** (`database/content/*.json`) mit dem Import-Befehl `php artisan game:content-sync`. Neue Items brauchen keine Code-Änderung.
- **Admin-Backend „Items & Drops“**: Admins legen Items an, ändern sie (Typ, Slot, Seltenheit, Attribut-Boni, Shop-Preis, Mindest-Level, Kategorien) und stellen die Fund-Wahrscheinlichkeiten ein. Pro Item gibt es ein Drop-Gewicht, global die Grundchance, das Tageslimit und den Spürsinn-Bonus. Die Oberfläche rechnet die resultierende Chance pro Item live vor. Die JSON-Dateien liefern nur den Startbestand, Änderungen im Backend überschreibt `game:content-sync` nicht (außer mit `--force`).

## 2.4 Spielklassen

Es gibt sechs Klassen mit je einer **passiven** und einer **aktiven** Fähigkeit (Mana). Die aktive Fähigkeit wirkt immer auch auf die Familie oder an der Familie mit. Weitere Fähigkeiten pro Klasse (z. B. ab Level 15/20/25) können später als Inhalt ergänzt werden.

- **Freischaltung**: Level 5 bei 9–12 Jahren, Level 8 bei 13–16 Jahren, damit der Spaß früher beginnt als im Original (Level 10)
- **Klassenwechsel** einmal im Monat kostenlos (kein Bezahlen mit Edelsteinen wie im Original). Ausprobieren ist erwünscht.
- Klassen, Fähigkeiten und Zahlenwerte liegen als Inhalt in `database/content/classes.json`. Nur die Effekt-Typen sind programmiert.

| Klasse | Rolle | Fokus / Klassenwerte | Passiv | Aktiv (Mana) | Passt zu |
|---|---|---|---|---|---|
| ⚔️ **Krieger** | Der Durchbeißer | Max-HP 60 statt 50, Kraft (Boss-Schaden) | **Schildwall**: −25 % HP-Schaden für die ganze Familie, wenn ein Mitglied eine Daily verpasst | **Mächtiger Hieb**: massiver Zusatzschaden am Quest-Boss beim Erledigen einer *schweren* Aufgabe | Große Einzellasten: Garten, Keller ausmisten, schwere Einkäufe |
| 🔮 **Magier** | Der Stratege | Max-Mana +50 %, Köpfchen (XP) | **Fokus-Schild**: Mana-Regeneration für Dailies, die vor einer Uhrzeit abgehakt werden (Standard 12:00, pro Familie einstellbar) | **Weisheit der Ahnen**: +20 % XP für alle Familienmitglieder für 24 h | Denk-, Planungs- und Lernaufgaben: Hausaufgaben, Budget, Wochenplanung |
| 🗡️ **Schurke** | Der Beutejäger | Spürsinn (Gold, Drops, kritische Treffer) | **Glückstreffer**: 15 % Chance auf dreifaches Gold bei jeder Aufgabe | **Beutezug**: Bonus-Gold beim Abschließen einer Aufgabe aus der Familienbörse (Pool) | Schnelle Helfer, die freie Aufgaben aus dem Pool greifen |
| 💚 **Heiler** | Der Supporter („Wächter“) | Ausdauer (HP-Regeneration) | **Zweite Chance**: einmal pro Woche eine verpasste Daily rückwirkend ohne HP-Abzug glätten | **Heilende Welle**: HP für sich selbst oder ein gewähltes Familienmitglied | Oft Eltern: fängt Missgeschicke der Kinder auf, hält die Motivation hoch |
| 🔧 **Tüftler** | Der Automatisierer | Köpfchen/Spürsinn, Home-Assistant-Boni | **Smart-Connect**: +25 % XP und Gold für Aufgaben, die über Home Assistant ausgelöst werden (NFC, Zigbee-Taster, Sensor) | **Überlastung**: verdoppelt den Effekt der nächsten über einen HA-Sensor ausgelösten Aufgabe | Aktive Nutzung der Hausautomation: Taster am Bett, NFC am Terrarium/an der Waschmaschine |
| 🎵 **Barde** | Der Motivator | Ausgewogen, Streaks und Moral | **Gemeinschafts-Rhythmus**: Schaffen alle Familienmitglieder an einem Tag 100 % ihrer Dailies, bekommt jedes Mitglied am Folgetag einen großen Gold-Bonus | **Anfeuern**: +15 % Boss-Schaden für alle Gruppenmitglieder für 12 h | Gemeinsame Aufgaben und Familien-Quests („Frühjahrsputz“) |

**Regeln, die für die Umsetzung nötig sind:**
- **Kein Stapeln von Familien-Passiven**: Gibt es zwei Krieger, bleibt Schildwall bei −25 %. Gemeinschafts-Rhythmus zahlt den Bonus nur einmal pro Person aus, egal wie viele Barden es gibt. Gleiche aktive Buffs verlängern nur die Laufzeit, statt den Wert zu erhöhen.
- **Tüftler** ist nur wählbar, wenn die Familie eine aktive HA-Integration hat. Fällt sie weg, ist ein Klassenwechsel sofort kostenlos möglich. Smart-Connect und Überlastung gelten nur für Abschlüsse mit `source = ha` und sind auf eine Anzahl pro Tag begrenzt (gegen Dauer-Drücken am Taster).
- **Mächtiger Hieb** wird vorbereitet und beim nächsten Abschluss einer schweren Aufgabe ausgelöst. Beutezug wirkt beim nächsten Abschluss einer Pool-Aufgabe, Überlastung beim nächsten HA-Abschluss. Jeder Effekt verfällt nach 24 h.
- **Zweite Chance** ist bis 24 h nach dem Tageswechsel einlösbar, erstattet die HP und erhält den Streak. Sie setzt einmal pro Woche zurück.
- **Gemeinschafts-Rhythmus** wird im Familien-Tageswechsel geprüft, nachdem alle Mitglieder ihren Tag abgeschlossen haben. Mitglieder im Pausen-/Gasthaus-Modus zählen nicht mit. Es zählt nur, wenn mindestens 2 Mitglieder aktiv sind.
- **Glückstreffer** ist Zufall nur mit Spielgold (kein Echtgeld, keine Sterne).
- Umsetzung im Code als **Modifikator-Kette** in der Scoring-Engine: Jede Klasse und jeder aktive Buff meldet Hooks an (`onScore`, `onCronDamage`, `onFamilyDayEnd`, `onHaCompletion`). So werden alle Klassen gleich behandelt und lassen sich einzeln testen.

## 2.5 Crafting-System

**Grundidee**: Echte Tätigkeiten liefern passende Materialien. Daraus stellt man in Werkstätten, die man im eigenen Haus baut (§2.6), Ausrüstung, Tränke, Futter und Möbel her. So hängen Aufgaben, Crafting und Housing zusammen.

**Materialien** (Drops beim Erledigen von Aufgaben, mit Tageslimit):
| Aufgabenkategorie | Typische Materialien |
|---|---|
| Haushalt | Holz, Stein, Nägel |
| Schule & Lernen | Tinte, Pergament, Kristallsplitter |
| Sport & Draußen | Kräuter, Leder, Feuerstein |
| Tiere & Natur | Wolle, Federn, Samen |
| Kreativ | Farben, Stoff, Garn |
| Familien-Boss, Quests, lange Streaks | seltene Materialien (Drachenschuppe, Sternenstaub) |

Die Aufgabenkategorie wählt man beim Anlegen (Pflichtfeld mit Vorschlag). Schwere Aufgaben geben mehr bzw. seltenere Materialien.

**Rezepte (Baupläne)**: werden über Level, Quest-Belohnungen, seltene Drops, den Shop oder die Bibliothek im Haus freigeschaltet.
- Ein Rezept besteht aus Materialien, Gold, der nötigen **Station** und einer **Herstellungszeit** in Echtzeit (Sekunden bis wenige Stunden; ein Push meldet „Dein Heiltrank ist fertig!“).
- **Stationen**: Werkbank (Möbel, Werkzeug), Schmiede (Waffen, Rüstung), Alchemietisch (Tränke, Futter), Nähtisch (Kleidung, Deko). Jede Station hat Ausbaustufen, die bessere Rezepte erlauben.
- **Qualität**: normal / gut / meisterhaft. Die Chance steigt mit der persönlichen **Handwerksstufe**, die durch Craften wächst. Meisterhafte Teile bekommen einen kleinen Zusatzwert und einen Glanz-Effekt.

**Was man herstellen kann**:
- Ausrüstung: Grundausrüstung gibt es im Shop, die **besten und einzigartigen Teile nur durch Crafting**
- Tränke: Heiltrank, Manatrank, Streak-Schutz-Amulett (wöchentlich begrenzt)
- Futter und Schlüsselpulver für Haustiere
- Möbel und Deko fürs Housing (der größte Anteil der Rezepte)
- Familien-Gegenstände: z. B. „Boss-Falle“ (Extra-Schaden am Familien-Boss) oder Feuerwerk (Feed-Effekt für alle)

**Familie und Austausch**:
- **Familienlager**: Materialien kann man ins gemeinsame Lager legen, und andere dürfen sie nutzen.
- **Schenken** innerhalb der Familie ist jederzeit möglich. **Tauschen mit Bündnis-Familien** geht nur über Tauschangebote. Bei Kindern unter 13 müssen die Eltern zustimmen. Es gibt keinen Handel mit Gold oder Sternen nach außen, damit keine Tauschgeschäfte entstehen, die man ausnutzen kann.

**Wirtschaft**: Crafting ist der wichtigste **Gold-Verbraucher** (gegen Inflation). Inventarplätze sind begrenzt und wachsen mit dem Lagerraum. Klassen bekommen keine Crafting-Boni, damit die Klassenbalance unverändert bleibt.

## 2.6 Housing

**Zwei Ebenen**:
1. **Familienhaus**: das gemeinsame Zuhause, das alle zusammen ausbauen. Ausbaustufen: Hütte → Fachwerkhaus → Gutshof → Burg.
2. **Eigenes Zimmer** für jedes Familienmitglied **im** Familienhaus, das man frei einrichtet. Für Kinder ist das der wichtigste Ort zum Gestalten.

**Darstellung**: 2D-Raster in „Puppenhaus“-Seitenansicht (Wand + Boden, z. B. 10×6 Felder) statt Isometrie. Das lässt sich in Flutter und im Web mit denselben Sprite-Ebenen gleich umsetzen. Möbel haben eine Grundfläche (B×H), Ebene (Boden/Wand/Decke) und dürfen sich nicht überlappen. Man kann sie drehen und spiegeln.

**Räume mit Funktion** (Effekte bewusst klein, das Gestalten soll im Vordergrund stehen):
| Raum | Funktion |
|---|---|
| Werkstatt | Crafting-Stationen aufstellen und ausbauen |
| Stall & Gehege | Haustiere und Reittiere wohnen sichtbar dort; leichter Bonus aufs Füttern |
| Bibliothek | neue Rezepte freischalten, Regal mit Erfolgen |
| Lagerraum | mehr Inventarplätze, Familienlager |
| Garten | Beete: säen → gießen (über eine Daily) → in Echtzeit wachsen lassen → Kräuter und Samen ernten |
| Trophäenhalle | besiegte Familien-Bosse, Abzeichen, meisterhafte Gegenstände |
| Eigene Zimmer | Gestaltung frei; ein Bett erhöht die nächtliche HP-Regeneration leicht |

**Bauprojekte** (das „konstruktive“ Gegenstück zum Familien-Boss):
- Ein neuer Raum oder eine Ausbaustufe ist ein **Familien-Bauprojekt** mit Materialliste und Goldbetrag. Alle tragen dazu bei, ein Fortschrittsbalken zeigt den Stand, und der Familien-Feed meldet „Ben hat 10 Holz beigesteuert“.
- Ist alles da, dauert die **Bauzeit** 1–3 Tage. Danach gibt es eine Einweihung mit einer Animation und einem Feed-Eintrag.
- Eltern können Bauprojekte mit einem **realen Familienziel** verknüpfen (z. B. „Burg fertig = Ausflug in den Freizeitpark“).

**Unordnung-Mechanik**: Wenn Familienaufgaben im Haushalt überfällig sind, erscheinen im virtuellen Familienhaus Staub, Spinnweben und herumliegende Kisten. Erledigt man die echten Aufgaben, verschwindet die Unordnung mit einem kleinen Putz-Effekt. Das funktioniert ohne Home Assistant.

**Besuche**: Bündnis-Familien können das Haus besuchen (nur ansehen) und ins **Gästebuch** schreiben. Für Kinder unter 13 gibt es dort nur Emojis und vorgefertigte Texte.

**Wetter in der Heldenszene (optional)**: Bei aktiviertem Open-Meteo-Wetter spiegelt die Szene die aktuellen Bedingungen mit Regen-, Schnee-, Wolken- oder Sonneneffekten. Dafür wird der bereits grob gerundete Familienstandort verwendet.

**Home-Assistant-Spielereien (optional)**: Ein echter Pflanzensensor (Bodenfeuchte) kann das Gießen des Garten-Beets auslösen; Wetterereignisse können weiterhin über die HA-Integration ausgelöst werden.

## 2.7 Quests als isometrische Mini-Dungeons

**Grundidee**: Eine Quest ist ein kleiner isometrischer Dungeon (5–12 Räume), den die Familie **gemeinsam, rundenbasiert und asynchron** erkundet. Jeder zieht, wann er will. Der Dungeon wird **nur durch erledigte Aufgaben angetrieben**. So bleibt die Spielzeit an echte Aufgaben gebunden, und das Spiel ersetzt die Aufgaben nicht.

**Energie (Fackeln)**: Jede erledigte Aufgabe gibt Fackeln (trivial 1, leicht 1, mittel 2, schwer 3, alle Dailies erledigt +3). Ziehen, Kisten öffnen und Kämpfen kosten Fackeln. Ein Tages- und ein Vorratslimit verhindern, dass man Fackeln sammelt und dann stundenlang spielt. Eine Dungeon-Sitzung dauert typisch **2–5 Minuten**.

**Ablauf**:
- **Nebel des Krieges**: Räume werden erst sichtbar, wenn jemand sie betritt. Die Karte ist für die ganze Familie dieselbe, die Avatare aller Mitglieder stehen sichtbar auf der Karte.
- **Raumtypen**:
  - Monster: kurzer rundenbasierter Kampf, 1–3 Runden, Werte aus Stats und Ausrüstung, Aktionen Angriff / Fähigkeit / Trank
  - Schatz: Materialien fürs Crafting, Rezepte, Möbel
  - Falle: Spürsinn-Probe
  - Rätsel: einfache Logik- oder Schiebe-Rätsel, für 9–12 leichter
  - Lagerfeuer: heilen, Mitglieder treffen sich hier
  - Händler, verschlossene Türen (Schlüssel als Drop)
- **Endboss** = Familien-Boss (§3.5). Er bekommt Schaden aus allen erledigten Aufgaben (wie bisher) **und** aus Dungeon-Kämpfen. Der Kampf im Boss-Raum wird erst freigeschaltet, wenn die Familie dort angekommen ist.
- **Kein Game Over**: Bei 0 HP im Dungeon geht es zurück zum Eingangs-Lager mit Fackel-Verlust, aber ohne Verlust von Gegenständen.
- **Rhythmus**: ein Familien-Dungeon pro Woche (passt zum Wochen-Boss), dazu optional ein kurzes **Solo-Gewölbe** (3 Räume) pro Tag für alle, die schon früh fertig sind.
- **Altersanpassung**: Für 9–12 gibt es weniger Fallen, klarere Hinweise und leichtere Rätsel. Für 13–16 gibt es komplexere Karten, Geheimgänge und härtere Elite-Gegner.

**Klassen im Dungeon** (Vorschlag: zusätzliche Interaktionen auf der Karte, die Klassenfähigkeiten bleiben unverändert):
| Klasse | Dungeon-Interaktion |
|---|---|
| Krieger | Rissige Wände einschlagen (Abkürzungen), zieht Monsterangriffe auf sich |
| Magier | Deckt angrenzende Räume im Nebel auf, liest Runen (Rätsel-Hinweis) |
| Schurke | Öffnet verschlossene Kisten ohne Schlüssel, entschärft Fallen |
| Heiler | Lagerfeuer heilen die ganze Gruppe stärker, belebt K.O.-Mitglieder vor Ort |
| Tüftler | Bedient Hebel und Mechanismen. Mit **HA-Kopplung** kann ein echter NFC-Tag oder Taster einen Mechanismus im Dungeon auslösen. |
| Barde | Bonus, wenn mehrere Mitglieder im selben Raum stehen; „Lied“ senkt die Fackelkosten der Gruppe |

**Dungeon-Erzeugung**: Der Server erzeugt den Dungeon aus einem **Seed** und **Raum-Bausteinen**, die im **Tiled**-Editor gebaut werden (isometrische `.tmx`-Dateien plus Inhalts-JSON für Monster, Beute und Thema). Themen: Spinnenkeller, Staubmonster-Gruft, Wäschedrachen-Höhle usw., passend zu Haushaltsthemen.

**Bausteine (Stand Server)**: Jeder Raumtyp hat 1–3 Bausteine (`blocks` in `database/content/dungeons.json`), je eine isometrische Tiled-Karte mit 5×5 Feldern unter `public/dungeon/blocks/<key>.tmx` (Tileset `public/dungeon/dungeon.tsx`, Grafiken aus Kenney „Isometric Miniature Dungeon“, CC0). Ebenen: `floor`, `walls_back`, `props`, `walls_front` und die Objektebene `spots` mit `door_N/E/S/W`, `spawn` und je nach Typ `monster`, `chest`, `trap`, `puzzle`, `lever`, `campfire`, `merchant`, `boss`. Norden ist im Dungeon-Raster `y-1` und liegt oben rechts. Die Türlücken bleiben frei: Die API liefert je Raum `doors` mit Art und fertigem Sprite-Namen (Torbogen, verschlossene/geöffnete Tür, Tor, Riss/Loch, sonst Wand), dazu `block_url` und unter `art` die Sprite-Zuordnung. Neu erzeugen mit `python3 tools/dungeon/build_blocks.py <entpacktes Kenney-Paket>`.

**Technik**:
- **Server-autoritativ**: Die App sendet nur Absichten (`move`, `interact`, `fight`). Der Server prüft Fackeln, Wege und Zufall (deterministischer Zufallsgenerator aus Seed + Aktionszähler, dadurch testbar und schwer zu schummeln). Es gibt kein Echtzeit-Protokoll. Andere Mitglieder sehen Züge per Polling oder Push („Lea hat die Schatzkammer gefunden!“).
- **Flutter**: **Flame** + `flame_tiled` für die isometrische Karte, Tap-to-move mit Wegfindung über das Raster, Avatare aus denselben Sprite-Ebenen wie der Avatar-Editor (4 Richtungen, gespiegelt, um Grafikaufwand zu sparen)
- **Web**: Für den Anfang eine vereinfachte 2D-Kartenansicht mit Kampf-Log. Ein vollwertiger isometrischer Web-Client (PixiJS) wäre optional später möglich, der Dungeon ist also zuerst ein Erlebnis in der App.
- **Grafiken**: kostenlose CC0-Modelle (Kenney) und eigene Modelle, über die Blender-Pipeline aus §2.8 als Isometrie-Sprites gerendert. Weil die Pipeline ohnehin Isometrie erzeugt, kann das Housing ohne Zusatzkosten bei der Grafik denselben Stil nutzen (statt der Puppenhaus-Ansicht in §2.6). Mehraufwand entsteht nur beim Editor.

## 2.8 Grafik, Sound & Assets (nur kostenlos oder selbst erstellt)

**Grundsatz**: Es werden **keine Habitica-Sprites** verwendet (CC BY-NC-SA, fremder Stil), und der Code wird neu geschrieben, nicht kopiert. Alle Assets sind **kostenlos mit klarer Lizenz** oder **selbst erstellt**.

**Lizenz-Reihenfolge**: CC0 (bevorzugt) → CC BY / OFL (Namensnennung im Credits-Bildschirm) → CC BY-SA (nur, wenn es nicht anders geht). **Nicht verwendet** werden: NC-Lizenzen, „nur privat“ ohne Lizenztext, Assets ohne nachvollziehbare Quelle und Grafiken aus Spielen oder Marken.

**Kostenlose Quellen**:
| Bereich | Quelle | Lizenz |
|---|---|---|
| Dungeon, Housing, Möbel, Gelände (3D → Isometrie) | Kenney 3D-Kits (Dungeon, Furniture, Castle, Nature, Mini-Kits) | CC0 |
| **Figuren & Monster (fertig geriggt + animiert)** | **KayKit** von Kay Lousberg (Adventurers, Skeletons, Dungeon Remastered, Furniture Bits), **Quaternius** (Ultimate Monsters, Animated Characters, Animal Pack) | CC0 |
| Isometrische 2D-Sets, UI-Rahmen, Buttons, Partikel | Kenney (Isometric Miniature Dungeon/Library/Farm, UI Pack, Particle Pack) | CC0 |
| Fähigkeiten-, Item- und Material-Icons | game-icons.net (einfarbige SVG, im eigenen Stil eingefärbt) | CC BY 3.0 |
| Weitere Sprites, Monster | OpenGameArt.org (pro Asset prüfen, nur CC0 oder CC BY) | gemischt |
| Soundeffekte | Kenney Audio Packs, freesound.org (Filter: CC0) | CC0 |
| Musik | OpenGameArt (CC0), Kevin MacLeod / incompetech | CC0 / CC BY |
| Schriften | Google Fonts, z. B. „Nunito“ (UI für Kinder), „Pixelify Sans“ (Überschriften im Spiel) | OFL |
| Animationen | eigene Rive- oder Lottie-Dateien, Partikeleffekte in Flame programmiert | selbst erstellt |

**Selbst erstellen (Pipeline für einen einheitlichen Look)**:
1. **3D → isometrische Sprites**: Kenney-CC0-Modelle und eigene Low-Poly-Modelle werden in **Blender** mit fester isometrischer Kamera, einheitlichem Licht und Umriss-Shader gerendert. Ein Python-Skript (`tools/render_iso.py`) gibt pro Objekt 4 Richtungen und mehrere Qualitätsstufen aus und packt sie zu Spritesheets. So passen Dungeon, Möbel, Haus und Monster automatisch zusammen.
2. **Avatar**: modularer Low-Poly-Charakter in Blender (Körper, Haare, Ausrüstung als getrennte Teile), aus denselben Kamerawinkeln gerendert. Damit bleibt der Avatar-Editor, die Ausrüstungsanzeige und die Dungeon-Figur aus einem Guss. Für den Anfang reicht ein Platzhalter-Avatar aus Kenney-Figuren.
3. **Icons und UI**: game-icons-SVGs und eigene SVGs, umgefärbt über ein Skript auf die **Projekt-Palette**. Die Palette (ca. 32 Farben) wird einmal festgelegt, zusätzlich je ein helles „Kids“-Theme und ein dunkles „Teen“-Theme.
4. **Vereinheitlichung**: Ein Skript (`tools/palette_remap.py`) passt fremde 2D-Assets an die Palette an, damit gemischte Quellen nicht zusammengewürfelt aussehen.
5. **Direkt per Code/Skript erstellbar (z. B. mit Claude)**: SVG-Icons, UI-Elemente (Rahmen, Buttons, Leisten, Abzeichen), Wappen-Baukasten für Familien, einfache Pixel-Sprites per Skript, Partikel- und Shader-Effekte, Rive/Lottie-taugliche Vektor-Grundformen, sowie die Blender- und Paletten-Skripte selbst. Aufwendige Figuren und Monster werden besser aus CC0-Modellen (KayKit, Quaternius) gerendert und bei Bedarf von Hand verfeinert (Werkzeuge und Ablauf siehe unten).

**Werkzeuge (alle kostenlos)**:
| Aufgabe | Software | Hinweis |
|---|---|---|
| 3D-Modelle anpassen, riggen, **isometrisch rendern** | **Blender** | Kernwerkzeug, das Render-Skript läuft über `blender --background --python tools/render_iso.py` |
| Eigene Figuren und Monster modellieren (einsteigerfreundlich) | **Blockbench** | Low-Poly im Stil von KayKit, mit eingebautem Animations-Editor, Export als glTF nach Blender |
| Alternative: Voxel-Stil | **MagicaVoxel** | Sehr schnell und kindgerecht, rendert isometrisch sehr gut. Wählt man diesen Stil, dann einheitlich für alle Assets. |
| Fertige Animationen für humanoide Figuren | Mixamo (kostenloses Adobe-Konto) | Optional, KayKit und Quaternius bringen bereits Animationen mit |
| **Sprites von Hand verfeinern** (Umrisse, Glanzlichter, Pixel-Korrekturen) | **Pixelorama** oder **LibreSprite** | Beide Open Source. Aseprite ist der Standard (ca. 20 €, oder kostenlos selbst aus dem Quellcode kompilieren). |
| Gemalte Grafiken (Hintergründe, Boss-Illustrationen) | **Krita** | Open Source |
| SVG-Icons, Wappen, UI-Elemente | **Inkscape** | Open Source, Figma (Free-Tarif) optional für UI-Entwürfe |
| Dungeon-Bausteine bauen | **Tiled** | Isometrische Karten, wird direkt von `flame_tiled` gelesen |
| Spritesheets packen | **Free Texture Packer** | Open Source, gibt das TexturePacker-JSON-Format aus, das `flame_texturepacker` liest |
| UI-Animationen | **Rive** (Free-Tarif) oder **Glaxnimate** (Open Source, Lottie) | Level-up, Truhe öffnen, Konfetti |
| Soundeffekte erzeugen | **jsfxr / ChipTone** | Generierte Sounds gehören einem selbst, gut für Retro-Effekte |
| Audio schneiden und normalisieren | **Audacity** | Export als OGG |
| Farbpalette | **Lospec**-Palettendatenbank | Fertige 32-Farben-Palette als Ausgangspunkt |

**Ablauf für eine neue Figur oder ein neues Monster**:
1. Basis nehmen (KayKit/Quaternius-Modell) **oder** in Blockbench modellieren
2. In Blender Farben und Material auf die Projekt-Palette setzen, Pose und Animation wählen
3. `render_iso.py` erzeugt 4 Richtungen × Animationsbilder in doppelter Auflösung
4. `palette_remap.py` reduziert auf die Palette und setzt einen einheitlichen Umriss
5. Nur bei Helden-Figuren und Bossen in Pixelorama/LibreSprite von Hand nachbessern (Gesicht, Glanzlichter)
6. Mit Free Texture Packer packen, in `credits.json` eintragen, hochladen. Fertig ohne App-Update.

**Lizenzverwaltung**:
- `assets/credits.json`: Für jede Datei werden Quelle, Autor, Lizenz und URL erfasst. Daraus entsteht automatisch der **Credits-Bildschirm** in App und Web.
- Ein CI-Check prüft, dass jede Datei unter `assets/` einen Eintrag in `credits.json` hat und keine verbotene Lizenz verwendet.
- Die Assets liegen im Laravel-Repo unter `resources/game-assets/` (Quellen) bzw. `public/game/` (Build). Die App lädt Spritesheets und Atlas-JSON vom Server und speichert sie zwischen. Neue Möbel oder Monster brauchen deshalb **kein App-Update**.

**Technische Vorgaben**: isometrische Kachel 128×64 px (@2x), Spritesheets als WebP plus Atlas-JSON (TexturePacker-Format, das Flame lesen kann), Icons 64 px als SVG und PNG, Sounds als OGG (unter 100 KB je Effekt).



## 2.9 Grafik-Fahrplan und Stil (ohne Grafik kein Spiel)

Grafik ist kein Feinschliff am Ende, sondern läuft als **eigener Strang parallel zu allen Phasen** (Roadmap: Strang G). Jede neue Spielfunktion bekommt ihre Grafik gleich mit, Emojis sind nur Platzhalter.

**Stil**: flacher Cartoon-Look mit kräftigen, dunklen Konturen, runden Formen und leichten Schatten. Kindgerecht, aber nicht babyhaft. Eine feste Palette (ca. 32 Farben) für alles; für Teens dieselben Figuren auf dunklerem Theme. Gegner wirken frech statt gruselig.

**Stufen**:
| Stufe | Inhalt | Technik | Wann |
|---|---|---|---|
| G1 | **Avatar-Baukasten**: Körper, Hautton, Frisur, Haarfarbe, Augen, Kleidung; Klassen-Kostüm und jede angelegte Ausrüstung erscheinen sichtbar am Körper (wie bei Habitica) | LPC-Pixelfiguren aus dem Universal LPC Spritesheet Character Generator (nur OGA-BY/CC-BY/CC0, Credits je Datei), Ebenen in `public/avatar/lpc`, Regeln in `resources/avatars/lpc-rules.json` für Web und Server-SVG; dieselben Spritesheets liefern später die Laufanimation im Dungeon | umgesetzt |
| G1 | **Monster-Baukasten** für alle Quest-Bosse: Körperform, Farbe, Augen, Hörner/Stacheln, Mund – jeder Boss sieht anders aus | parametrisches SVG, Werte je Boss in `quests.json` | jetzt |
| G1 | **Haustiere und Reittiere**: je Tierart eine Vorlage, gefärbt nach Schlüpfpulver (Feuer, Eis, Regenbogen …), Reittiere größer mit Sattel | parametrisches SVG | jetzt |
| G1 | **Effekte**: Treffer am Boss, Level-up, Fund, Abhaken | CSS-/Flutter-Animationen | jetzt |
| G2 | **Item-Icons** einheitlich statt Emoji (Eier, Pulver, Futter, Material, Tränke, Ausrüstung) | game-icons.net (CC BY) eingefärbt + eigene SVGs | nach G1 |
| G2 | **Szenen**: Hintergrund des Heldenpanels mit Tag/Nacht, Boss-Arena, Stall | Fluent Emoji 3D (Microsoft, MIT) vor CSS-Himmel, Credits in `CREDITS.md` | nach G1 |
| G3 | **Isometrische Sprites** für Housing und Dungeons | Blender-Pipeline mit Kenney/KayKit (CC0), Flame in der App (§2.8) | mit Phase 6/7 |
| G4 | **Sound** | jsfxr/ChipTone, Kenney Audio (CC0) | mit Phase 8 |

**Regeln**: Alle Grafiken liegen als Code bzw. Datei im Repo, sind lizenzfrei bzw. CC0/CC BY mit Eintrag in `credits.json`, und funktionieren in Web und App gleich.
