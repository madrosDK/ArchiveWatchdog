# ArchiveWatchdog

IP-Symcon-Modul zur Prüfung und gezielten Bereinigung auffälliger Archivwerte von Integer- und Float-Variablen. Unterstützt Messwerte, aufwärtszählende und abwärtszählende Zähler sowie das Erkennen kurzer Fehlerfolgen.

**Version:** 0.3.02 · **Build:** 5

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
