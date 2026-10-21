# 9. Teststrategie / Verifikation

- **Pest-Unit-Tests** für die Spielformeln (delta, XP, Gold, Schaden, Level-Schwellen), jede Klassenfähigkeit einzeln (z. B. Schildwall stapelt nicht, Zweite Chance einmal pro Woche, Gemeinschafts-Rhythmus nur bei 100 % aller aktiven Mitglieder, Smart-Connect nur bei `source = ha`), Ablauf von Buffs, Wiederholungsregeln, Rotation und Tageswechsel mit `Carbon::setTestNow` (Zeitzonen, Sommerzeit, verpasste Tage)
- **Dungeon-Tests**: gleicher Seed erzeugt denselben Dungeon, jeder Raum ist erreichbar (Graph-Prüfung), Fackelkosten und Limits, ungültige Züge werden abgelehnt (durch Wände, ohne Fackeln, nicht benachbart), Kampfausgang reproduzierbar, Idempotenz der Züge, Boss-Raum erst nach Ankunft freigeschaltet
- **Crafting/Housing-Tests**: Materialverbrauch atomar (Transaktion + Zeilensperre, kein doppeltes Einlösen bei zwei parallelen Anfragen), Abschluss von Aufträgen über `finishes_at`, Qualitätswahrscheinlichkeiten (mit festem Zufalls-Seed), Kollisionsprüfung bei der Möbel-Platzierung, Versionskonflikt beim Layout, Beiträge zu Bauprojekten, Eltern-Freigabe bei Tausch unter 13
- **Feature-Tests** für API und Policies: Familien-Isolation (Familie A sieht nie Daten von B), Rechte nach Rolle und Altersgruppe, Idempotenz von `client_uuid`, Freigabe-Workflow, HA-Token-Fähigkeiten, HMAC-Signatur
- **Langzeitmotivation** (§14.13): Wochenziele mit Energiespar-Tag und Zeitzone, Erinnerungs-Limits und Ruhezeiten, Meister-Kriterium (60 Tage, 80 %, `best`), Sterne-Ausschleichen nur mit Elternentscheidung, Abstimmungen, Boss-HP aus Aktivitätswert, Heldenjahr je Bundesland, Kennzahlen ohne Titel und Namen
- **Freischaltungen** (§13.5): Freischaltung genau beim Level je Altersgruppe, familienrelevante Routen nie gesperrt, `403 feature_locked`, Beiträge zählen ohne Freischaltung, Eltern-Overrides, Schnellstart, `legacy`-Migration, höchstens eine Erklärung pro Tag
- **Events** (§12.8): Osterformel und Kalenderregeln für mehrere Jahre, Schulferien mit `Http::fake` und Ausfall, Trigger mit Abklingzeit, Limit und Idempotenz, Stufen-Belohnung genau einmal, Ziel bei 60 % Aktivität erreichbar, keine Sterne aus Zufall, Zufallsereignisse mit Tageslimit und genau einem Gewinner
- **Fokus & Alltagshilfen** (§11.7): Schritt-Belohnung ohne Farming, sanfter Modus, Serien pausieren und reparieren, Energiespar-Tag mit Wochenlimit, Überraschungen mit Pech-Schutz, Kopf-Ablage und Privatsphäre, OpenRouter mit `Http::fake` (keine Namen im Prompt, Budget, ohne Freigabe kein Aufruf)
- **Flutter**: Unit-Tests (Sync-Warteschlange, Timer, Schritte offline), Widget-Tests der Hauptansichten, Integrationstest gegen Staging
- **HA**: lokale HA-Instanz per Docker mit der Integration, Ereignis-Zuordnung von Anfang bis Ende testen (NFC-/Steckdosen-Szenario simulieren)
- **Hetzner-Abnahme**: Nach dem Deployment prüfen, ob Cron, Queue-Abarbeitung, E-Mail und FCM-Push auf Staging funktionieren
- **Praxistest**: 8 Wochen mit der eigenen Familie, mit Ausgangslage und Vergleich aus §14.10 (Zwischenstand nach 2 Wochen), danach Werte anpassen (Schaden, Drop-Raten, Sterne-Preise, Boss-Faktor)


