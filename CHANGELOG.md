# Changelog

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
