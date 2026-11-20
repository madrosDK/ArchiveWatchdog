# Changelog

## 0.3.07 · Build 10 (2026-11-20)

### Neu
- Zwei zusätzliche, ausdrücklich zu bestätigende Aktionen zur vollständigen Bereinigung im Prüfzeitraum bzw. ab Archiv-Startdatum. Diese berücksichtigen auch von der Erkennung markierte Ausreißer mit großen Datenlücken.
- Getrennte sichere Löschaktionen behalten die bisherige Schutzregel; der automatische Timer verwendet ausschließlich die sichere Variante und kann die zusätzliche Freigabe nicht speichern oder wiederverwenden.
- Ausführlichere Berichte mit Anzahl erkannter und als zeitlich unsicher markierter Kandidaten, erfolgreicher Löschungen und wegen fehlender Freigabe übersprungener Werte. Status in der Diagnosetabelle wird nach Löschaufrufen aktualisiert.
- Reaggregation weiterhin genau einmal je Variable nach mindestens einem tatsächlich gelöschten Datensatz; niemals bei Vorschau, Lernen oder Diagnose.
- README und Konfigurationsdokumentation aktualisiert; Versionsangaben synchronisiert.


## 0.3.06 · Build 9 (2026-11-20)

### Ergänzt und vereinfacht
- Variablenliste: standardmäßig nur Variable, Verlauf, Empfindlichkeit (Empfindlich/Normal/Großzügig) und Aktiv; erweiterte Grenzwerte auf Wunsch einblendbar.
- Automatische Grenzwerte aus q95 des Lernprofils, bestehende individuelle Grenzwerte weiterhin im manuellen Modus nutzbar.
- Archiv-Scan überspringt Variablen ohne passendes Lernprofil nicht mehr, sondern verwendet gespeicherte Fallback-Grenzen.
- Diagnose und Scan verwenden dieselbe Wirksamkeitsberechnung für die Variablenschwellen.
- Gezielt bestätigte Löschung eines wegen großer Datenlücke nur zur manuellen Prüfung zugelassenen Ausreißers nach erneuter Rohdatenprüfung.
- Keine Reaggregation bei Vorschau/Diagnose; nach erfolgreicher gezielter Löschung Reaggregation nur für die betroffene Variable angefordert.


## 0.3.05 · Build 8 (2026-11-20)

### Geändert
- Messwerte: Die starre Zeitgrenze und der adaptive Lückenfaktor verhindern keine Erkennung mehr. Die Archivwerte werden anhand der Nachbarwerte und der gelernten/manuellen Abweichungsgrenzen geprüft.
- Große Datenlücken (>120 Minuten zwischen Anker und betroffenem Rohwert oder innerhalb einer Fehlerfolge) werden als auffällig erkannt, aber ausschließlich in Vorschau/Diagnose gezeigt und bei Löschläufen **nicht gelöscht**.
- Zähler behalten die zeitabhängige Prüfung mit `MaxGapMinutes`.
- `Lückenfaktor` aus der Konfigurationsoberfläche entfernt (bestehende gespeicherte Eigenschaft wird ignoriert). `Max. Abstand` ist als reine Zählereinstellung gekennzeichnet.
- Vor- und Nachlauf beim Einzeltag- und Tagesblock-Scan für Messwerte erweitert (jeweils mindestens 48 Stunden), damit längere Archivlücken geprüft werden können. Weiterhin Abbruch ohne Löschung bei unvollständigen Abfragen.
- Diagnose und Prüfergebnisse unterscheiden normal erkannte von wegen großer Datenlücke nur manuell zu prüfenden Ausreißern.

### Unverändert
- Keine automatische Korrektur dauerhafter Zähler-Resets. Reaggregation ausschließlich nach tatsächlich gelöschten Archivwerten, nie bei Vorschau, Diagnose oder Lernen.

## 0.3.04 · Build 7 (2026-11-20)

### Behoben
- Messwert-Ausreißer wurden bei Rohwertlücken von mehr als 30 Minuten trotz plausibler Nachbarn ausgeschlossen. Die Zeitgrenze wird für Messwerte jetzt aus dem erlernten typischen Abstand und einem einstellbaren Lückenfaktor (Standard 8) adaptiv erweitert, maximal bis zu zwei Stunden.
- Die Einzeltag-Diagnose zeigt die wirksame Zeitgrenze statt ausschließlich den manuellen Grenzwert.
- Prüfberichte weisen den effektiven Messabstand je Variable aus.

### Unverändert
- Lernprofile, bestehende Einstellungen und Zählerbehandlung bleiben erhalten; automatische Löschung bleibt standardmäßig AUS.
- Reaggregation nur nach tatsächlich gelöschten Archivwerten, niemals bei Vorschau, Diagnose oder Lernlauf.


## 0.3.03 · Build 6 (2026-11-20)

### Ergänzt
- Einzeltag-Diagnose für frei wählbare Archivvariable und Datum: Ausreißer, Nachbarwerte und Gründe für Nicht-Erkennung. Ohne Löschung/Reaggregation.
- Persistenter letzter Prüfbericht und Diagnosetabelle mit bis zu 300 konkreten Archivwerten und Löschstatus.
- Erfolgreiche Löschungen und fehlgeschlagene Löschversuche werden in der Diagnosetabelle unterschieden.
- Vorhandene Erkennungs-, Lern- und Löschfunktion sowie das Reaggregationsverhalten bleiben erhalten.


## 0.3.02 · Build 5 (2026-11-20)

### Korrigiert
- `AC_DeleteVariableData()` liefert die Anzahl tatsächlich gelöschter Archivdatensätze (Integer), keinen booleschen Erfolg. Der Rückgabewert wird nun korrekt ausgewertet; 0 wird als keine Löschung behandelt.
- Nach positiven Löschungen werden die betroffenen Zeitstempel aus den Rohdaten nachgelesen und verbleibende Werte als Warnung ausgewiesen.
- `AC_ReAggregateVariable()` wird weiterhin nur nach tatsächlichen Löschungen einmal je Variable gestartet; der Bericht bezeichnet den Vorgang ausdrücklich als **gestartet**, nicht als abgeschlossen.
- Meldungen unterscheiden erkannte Ausreißer, tatsächlich gelöschte Datensätze und fehlgeschlagene Löschaufrufe.

## 0.3.01 · Build 4 (2026-11-20)

### Geändert
- Reaggregation nach Löschungen explizit abgesichert: genau ein Aufruf pro betroffener Variable nach Abschluss der Löschaufrufe, sofern mindestens einer erfolgreich war.
- Kein Aufruf der Reaggregation bei Lernlauf, Vorschau oder Löschlauf ohne erfolgreiche Löschung.
- Erfolgreiche Reaggregation bzw. Fehler werden in Ergebnis und Debug protokolliert.
- README und Konfigurationsdokumentation ergänzt; Versionsangaben vereinheitlicht.

## 0.3 · Build 3

- Archivprüfung ab konfigurierbarem Startdatum und in Tagesblöcken.
- Erkennung kurzer Fehlerfolgen zusätzlich zu isolierten Ausreißern.
- Vorschau und Löschung für Prüfzeitraum oder Archiv ab Startdatum.
- Schutz vor teilweiser Löschung bei unvollständigen Prüfblöcken.

## 0.2 · Build 2

- Lernprofile aus Archivwerten und unterschiedliche Verlaufstypen hinzugefügt.
- Einstellbarer Großzügigkeitsfaktor und variableabhängige Prüfgrenzen.

## 0.1 · Build 1

- Grundlegende Archivüberwachung für Messwerte mit konfigurierbaren Sprung- und Nachbartoleranzen.
