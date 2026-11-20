# ArchiveWatchdog

IP-Symcon-Modul zur Prüfung und gezielten Bereinigung auffälliger Archivwerte von Integer- und Float-Variablen. Unterstützt Messwerte, aufwärtszählende und abwärtszählende Zähler sowie das Erkennen kurzer Fehlerfolgen.

**Version:** 0.3.06 · **Build:** 9

## Installation

Das Repository bzw. Modulverzeichnis in IP-Symcon einbinden und eine Instanz vom Typ `ArchiveWatchdog` anlegen. Archivierung der zu prüfenden Variablen muss aktiviert sein. Einstellungen übernehmen, Variablen konfigurieren und zunächst einen Lernlauf sowie eine Vorschau ausführen.

## Betriebsablauf

1. Überwachte Variablen und deren Verlaufstyp auswählen.
2. Über **Aus Archiv lernen** ein Profil aus vorhandenen Werten erstellen (ohne Änderung der Archivdaten).
3. Den gewünschten Zeitraum in der **Vorschau** kontrollieren (ohne Änderung der Archivdaten).
4. Vor einer Löschung das Archiv sichern und anschließend bei Bedarf den passenden Löschknopf verwenden.
5. Nach mindestens einem tatsächlich gelöschten Datensatz für eine Variable startet das Modul **einmal pro Variable** `AC_ReAggregateVariable`, nachdem alle erkannten Ausreißer dieser Variable bearbeitet wurden. Bei Vorschau, Lernlauf und Löschläufen ohne erfolgreiche Löschung erfolgt **keine** Reaggregation.

## Hinweise

- Automatische Löschung ist standardmäßig deaktiviert.
- Die Erkennung ist auf isolierte Ausreißer und kurze Fehlerfolgen ausgelegt; dauerhafte Zähler-Resets und Offsets werden nicht automatisch korrigiert.
- Archivlöschungen sind nicht rückgängig zu machen. Es werden nur erkannte Ausreißer gelöscht, nicht pauschal sämtliche Archivwerte.
- Tatsächlich gelöschte Datensätze, eine stichpunktbezogene Rohdatenkontrolle und der Startstatus der Reaggregation werden im Prüfergebnis dokumentiert. Bei Reaggregationsfehler wird eine Warnung ausgegeben.
- Es werden keine Live-Variablenwerte überschrieben.

Ausführliche Beschreibung jeder Einstellung: [docs/Konfiguration.md](docs/Konfiguration.md).

**Wichtig:** `AC_ReAggregateVariable()` startet die Reaggregation nur. Ihr Abschluss muss im IP-Symcon-Meldungsfenster überprüft werden; ein erfolgreich gestarteter Auftrag garantiert keinen bereits aktualisierten Chart. Wenn ein Ausreißer in den Rohdaten verblieben ist, kann die Reaggregation ihn nicht entfernen.

## Diagnose und nachvollziehbare Bereinigung

Die neue Einzeltag-Diagnose liest die Rohdaten einer frei wählbaren Variablen mit zusätzlichem zeitlichem Rand und listet auffällige Messpunkte samt Nachbarwerten und einer Begründung für die Erkennung oder Nicht-Erkennung auf. **Die Diagnose verändert keine Archivdaten.**

Der letzte Prüfbericht und eine Tabelle mit bis zu 300 erkannten Werten werden in Instanzattributen gespeichert und bleiben nach dem Schließen des Dialogs erhalten. Nach einem Löschlauf erscheint pro erfasstem Punkt der Löschstatus. Die Tabelle ist eine Diagnoseansicht und löscht selbst keine Daten. Bei einer erneuten Prüfung wird sie durch deren Ergebnis ersetzt.

**Hinweis:** Änderungen an den Diagnose-Einstellungen zuerst mit „Übernehmen“ speichern, dann Diagnoseknopf ausführen. Einziger nachgewiesener Problemwert aus dem bisherigen Screenshot: 16.11.2026, 03:55:27, 0,0 °C. Eine erfolgreiche Löschung ist erst durch Nachlesen der Rohdaten bestätigt.

## Zeitabstände ab v0.3.05

Beim Verlaufstyp **Messwert** ist die zeitliche Lücke kein Ausschlusskriterium für die Ausreißererkennung mehr. Auch 70 Minuten oder mehrere Stunden auseinanderliegende Rohwerte werden untersucht. Für die Prüfung werden plausible Werte vor und nach einer Auffälligkeit sowie die erlernte bzw. eingestellte Abweichungsschwelle benötigt. Eine große Lücke allein macht einen Wert weder falsch noch richtig.

**Sicherheitsregel:** Besteht bei einem erkannten Ausreißer eine Rohdatenlücke von mehr als 120 Minuten zur relevanten Nachbarschaft, wird er in Vorschau/Diagnose angezeigt, aber im Löschmodus übersprungen. Eine manuelle Prüfung im Originalarchiv ist erforderlich. Die Erkennung solcher Punkte ist möglich, ihre automatische Entfernung nicht. Die Scanabfrage holt für Messwerte bis zu mindestens 48 Stunden Kontext je Tagesabschnitt; bei extrem langen Lücken außerhalb des Kontextfensters ist eine Erkennung möglicherweise nicht möglich.

Bei Zählern bleibt die Zeitgrenze `Max. Abstand (min)` aktiv. Die Einstellung `Lückenfaktor` entfällt in der Oberfläche und wird für Messwerte nicht mehr verwendet. Die Großzügigkeits-, Sprung- und Nachbar-Toleranzen bleiben veränderbar.

**Reaggregation:** Einmal pro Variable ausschließlich nach erfolgreich gelöschten Archivdatensätzen; niemals nach Vorschau, Diagnose oder Lernen.

## Vereinfachte Bedienung (v0.3.06)

In der Variablenliste stehen standardmäßig nur **Variable, Verlauf, Empfindlichkeit und Aktiv**. Die Empfindlichkeit *Empfindlich / Normal / Großzügig* steuert den Toleranzbereich aus dem 95%-Lernwert. Ohne gültiges Lernprofil werden die bisherigen gespeicherten Grenzen verwendet; die normale Archivprüfung überspringt die Variable nicht mehr.

**Erweiterte Einstellungen für Variablen anzeigen** aktivieren, übernehmen und die Form erneut öffnen. Dort erscheinen bestehende Grenzwerte und *Manuelle Grenzen*. Erst wenn der manuelle Modus pro Variable aktiv ist, gelten diese Werte anstelle der aus dem Profil berechneten Grenzen. Die Einstellungen bleiben beim Versionswechsel erhalten.

Die **Einzeltag-Diagnose** und die vollständige Prüfung rufen dieselbe Erkennungsfunktion mit denselben wirksamen Schwellen auf. Nur Kandidaten innerhalb des jeweils gewählten Prüfzeitraums werden angezeigt. Der normale Prüfzeitraum kann deshalb null Treffer zeigen, obwohl ein älterer Diagnosetag Treffer enthält. Für ältere Werte **Gesamtes Archiv ab Startdatum prüfen (Vorschau)** nutzen.

**Manuell bestätigte Ausnahme:** Bei einem erkannten Kandidaten mit großer Datenlücke kann nach Kontrolle im Rohdatenarchiv die Uhrzeit *HH:MM:SS* zum eingestellten Diagnose-Datum angegeben und die eigene, bestätigungspflichtige Schaltfläche verwendet werden. Der Rohwert wird unmittelbar vor der Löschung erneut geprüft. Andere Punkte bleiben unberührt. Nach erfolgreicher Löschung wird die Reaggregation angefordert. Diese Funktion ist ausschließlich für als *ReviewOnly* erkannte Punkte vorgesehen.
