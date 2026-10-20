# 6. Android-App (Flutter) – `D:\www\habitica_app`

- **Architektur**: Riverpod (Zustand), go_router, dio (HTTP + Token-Interceptor), **drift** (lokale SQLite-DB, Offline-first), freezed/json_serializable, flutter_secure_storage
- **Offline-first**: Aktionen kommen in eine lokale Warteschlange mit `client_uuid` und werden bei Verbindung per `sync/actions` übertragen. Der Server ist maßgeblich, die App rechnet Animationen optimistisch voraus.
- **Animationen/Spielgefühl**: Rive oder Lottie (Level-up, Drops, Boss-Treffer), Haptik, Sounds (abschaltbar)
- **Housing-Editor** mit der **Flame**-Engine: Raster, Möbel per Drag & Drop, Kollisionsprüfung, Drehen. Gespeichert wird das ganze Layout. Crafting-Warteschlange mit Countdown und lokaler Benachrichtigung, wenn ein Auftrag fertig ist.
- **Modi**: Kinder-Ansicht (große Kacheln, wenig Text), Teen-Ansicht (kompakter, Themes), **Eltern-Ansicht** (Freigabe-Inbox, Familienverwaltung, Belohnungsshop, Statistik), Familiengerät-Modus
- **Android-Extras**: Home-Screen-Widget (`home_widget`) mit den Dailies des Tages und einem Abhak-Button, FCM-Push mit Aktionsbuttons („Bestätigen“ direkt aus der Benachrichtigung), Kamera für Foto-Nachweise, QR-Scanner zum Koppeln
- **Fokus & Alltagshilfen** (§11): Kopf-Ablage als schwebender Knopf, im Widget und im Teilen-Menü, Spracheingabe auf dem Gerät; Fokus-Modus mit nur einer nächsten Aufgabe; visueller Timer (schrumpfende Scheibe) mit lokaler Benachrichtigung; Schritte einer Aufgabe einzeln mit Sofort-Feedback; Rückmeldung voll / dezent / nur Haptik, folgt „Animationen entfernen“
- **Events** (§12.7): Event-Banner mit Familien-Fortschritt als Balken, Event-Seite, Deko im Familienhaus und in der Helden-Szene, Kalender „Demnächst“, Adventskalender
- **Builds**: Flavors `dev`/`staging`/`prod`, Verteilung über einen **geschlossenen Play-Store-Test** (Familie + Bekannte, bis 100 Tester) oder eine signierte APK. Eine öffentliche Store-Veröffentlichung (Families Policy) ist nicht nötig. Updates laufen über den eingebauten **Online-Updater** (§10).



## Web-Frontend (Inertia + Vue 3)

- Gleiche Funktionen wie die App, Schwerpunkt **Eltern-Verwaltung** am großen Bildschirm (Ämterplan-Editor, Belohnungsshop, HA-Ereignis-Zuordnung, Statistik-Dashboards)
- Fokus-Modus, visueller Timer und Kopf-Ablage auch im Web (§11.4)
- PWA-fähig (Manifest), damit das Familien-Tablet es ohne App nutzen kann
- Housing im Web: Raumansicht und einfacher Editor mit denselben Sprite-Ebenen (CSS-Grid und absolut positionierte Sprites, Drag & Drop), Crafting-Werkstatt-Ansicht
- Komponenten: Avatar-Renderer (SVG/Sprite-Layer, wiederverwendbar), Aufgabenspalten (Habits/Dailies/To-Dos/Belohnungen wie im Original), Freigabe-Inbox, Familien-Feed


