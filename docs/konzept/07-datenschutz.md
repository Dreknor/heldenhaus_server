# 7. Datenschutz & Sicherheit

- Hosting in Deutschland (Hetzner), keine Werbung, keine Analyse-Tools von Dritten
- **Datensparsamkeit bei Kindern**: Spitzname, Geburtsjahr (kein vollständiges Datum), keine E-Mail-Pflicht
- Eltern willigen für ihre Kinder ein (Art. 8 DSGVO). Bei privater Nutzung im Bekanntenkreis reichen eine kurze Datenschutzerklärung und Einwilligung bei der Einladung.
- Hinweis zu FCM: Push läuft über Google. Benachrichtigungen enthalten deshalb **keine Inhalte** (nur „Neue Freigabe“), die Details holt die App selbst.
- **Keine Gesundheitsdaten**: Heldenhaus speichert keine Diagnose (ADHS o. Ä., Art. 9 DSGVO). Die Fokus-Hilfen (§11) sind normale Einstellungen ohne medizinische Bezeichnung.
- **KI nur auf Wunsch**: Die Aufteilung über OpenRouter (§11.6) ist pro Familie aus, bis Eltern sie einschalten. Gesendet werden nur Aufgabentitel und Altersgruppe ohne Namen, zugelassen sind nur Anbieter ohne Speicherung und Training. Darauf weist die Datenschutzerklärung hin.
- Spracheingabe für die Kopf-Ablage läuft auf dem Gerät, es werden keine Audiodateien übertragen.
- **Erfolgsmessung** (§14.10): Kennzahlen und Umfragen bleiben in der eigenen Datenbank. Kinder geben im Stimmungs-Check nur Smileys und Auswahlantworten. Der Server-Admin sieht nur zusammengefasste Zahlen ohne Titel und Namen. Erinnerungen plant die App lokal, ihre Inhalte gehen nicht über Google.
- **Events** (§12): Für Feiertage und Schulferien wird nur Land und Bundesland an die OpenHolidays API übertragen. Wetter von Open-Meteo wird nur auf Wunsch und mit einem auf 0,1° gerundeten Ort abgerufen; bei Aktivierung wird es auch in der Heldenszene angezeigt. Geburtstage sind freiwillig und werden nur als Tag und Monat gespeichert.
- Datenexport (JSON) und Löschung pro Person und Familie, automatische Löschung von Foto-Nachweisen
- Passwort/PIN-Hashing, Ratenbegrenzung bei Login und PIN, Token-Widerruf pro Gerät, Familien-Scope wird in jeder Abfrage erzwungen (Tests dafür)

