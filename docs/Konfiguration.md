# ArchiveWatchdog – Konfiguration

Version 0.3.05 · Build 8

## Allgemeine Einstellungen

| Einstellung | Bedeutung |
| --- | --- |
| Regelmäßig prüfen | Aktiviert die zeitgesteuerte Prüfung des normalen Prüfzeitraums. |
| Automatische Löschung bei regelmäßiger Prüfung | Bei Aktivierung löscht der Timer erkannte Ausreißer. **Standard: AUS.** Nach mindestens einem tatsächlich gelöschten Datensatz wird die betroffene Variable reaggregiert. |
| Prüfintervall (Minuten) | Zeitabstand der automatischen Prüfung. |
| Normaler Prüfzeitraum (Stunden) | Rückblick für die normale Vorschau/Löschung und den Timer. |
| Neueste Daten auslassen (Minuten) | Verhindert die Prüfung sehr neuer Daten, bei denen Folgemessungen fehlen können. |
| Lernzeitraum (Tage) | Zeitraum, aus dem beim manuellen Lernlauf charakteristische Änderungen ermittelt werden. |
| Großzügigkeitsfaktor | Multipliziert den aus der Archivstatistik ermittelten 95%-Änderungswert; die wirksame Grenze ist mindestens `Min. Sprung`. Ein größerer Faktor macht die Erkennung weniger empfindlich. |
| Maximale Länge kurzer Fehlerfolgen | Höchstzahl benachbarter auffälliger Messpunkte, die als zusammenhängende Fehlerfolge erkannt werden können. |
| Archiv ab Datum prüfen/löschen | Startdatum für die umfassende Archivprüfung im Format JJJJ-MM-TT. |

## Überwachte Archivvariablen

| Einstellung | Bedeutung |
| --- | --- |
| Variable | IP-Symcon-ID der archivierten Integer- oder Float-Variable. |
| Verlauf | `Messwert`, `Zähler aufwärts` oder `Zähler abwärts`; bestimmt die Lern- und Erkennungslogik. |
| Min. Sprung | Untergrenze für einen als auffällig untersuchten Sprung, in der Einheit der Variable. |
| Nachbar-Toleranz | Zulässige Abweichung zwischen Werten vor und nach einem vermuteten Ausreißer; die effektive Prüfung berücksichtigt zusätzlich erlernte Grenzen. |
| Max. Abstand (min, nur Zähler) | Maximale Zeitlücke zwischen Zähler-Rohwerten für eine zusammenhängende Prüfung. Bei Messwerten nicht aktiv. |
| Aktiv | Schließt die Variable in Lernen und Prüfungen ein bzw. aus. |

## Aktionen und Datenänderung

| Aktion | Archivänderung | Reaggregation |
| --- | --- | --- |
| Aus Archiv lernen (nur Statistik) | Keine | **Nein** |
| Prüfzeitraum prüfen (Vorschau) | Keine | **Nein** |
| Gesamtes Archiv ab Startdatum prüfen (Vorschau) | Keine | **Nein** |
| Alle erkannten Ausreißer im Prüfzeitraum löschen | Erkannte Punkte werden gelöscht | **Ja**, je Variable nach mindestens einem tatsächlich gelöschten Datensatz |
| Alle erkannten Ausreißer ab Archiv-Startdatum löschen | Erkannte Punkte werden gelöscht | **Ja**, je Variable nach mindestens einem tatsächlich gelöschten Datensatz |
| Zeitgesteuerte Prüfung, Löschung AUS | Keine | **Nein** |
| Zeitgesteuerte Prüfung, Löschung EIN | Erkannte Punkte werden gelöscht | **Ja**, je Variable nach mindestens einem tatsächlich gelöschten Datensatz |

Die Reaggregation wird **nicht für jeden einzelnen Messpunkt**, sondern nach sämtlichen Löschaufrufen einmal je betroffener Variable gestartet. Auch bei teilweise fehlgeschlagenen Löschaufrufen wird reaggregiert, sofern mindestens ein Löschaufruf erfolgreich war. Fehler oder ein fehlgeschlagener Reaggregationsaufruf werden als Warnung protokolliert. Wenn keine Löschung erfolgreich war, findet keine Reaggregation statt.

**Sicherheit:** Zuerst Vorschau prüfen und eine Archivsicherung erstellen. Dauerhafte Zählerresets und lang anhaltende falsche Werte sind nicht automatisch bereinigbar. Weder Vorschau noch Lernlauf verändern archivierte Werte.

## Kontrolle nach einem Löschlauf

- `Erkannte Ausreißer` ist nur die Kandidatenzahl; die Werte sind damit **nicht** zwingend gelöscht.
- `Tatsächlich gelöschte Datensätze (API)` zählt den Integer-Rückgabewert von `AC_DeleteVariableData`. Ein Rückgabewert von `0` bedeutet, dass keine Daten entfernt wurden.
- Die erkannten Zeitstempel werden direkt danach per `AC_GetLoggedValues` kontrolliert. Verbliebene Rohdaten werden als Warnung gemeldet.
- `Reaggregation ANGEFORDERT` bedeutet, dass Symcon die Neuberechnung gestartet hat; der Abschluss kann dauern und ist im Meldungsfenster des Archive Control zu kontrollieren.
- Erst nach Abschluss das Diagramm neu laden. Bestehen Ausreißer weiterhin in den Rohdaten, muss die Erkennung/Löschung untersucht werden, nicht nur reaggregiert werden.
- Bei **Vorschau** und **Lernlauf** werden weder Daten gelöscht noch Reaggregationen gestartet.

## Diagnose (v0.3.03)

| Einstellung / Schaltfläche | Bedeutung |
| --- | --- |
| Diagnose: Variable | Variablen-ID, deren Rohwerte einzeln untersucht werden sollen; unabhängig von Aktiv-Status der Überwachung. |
| Diagnose: Datum | Tag im Format `JJJJ-MM-TT` (lokale Zeitzone des IP-Symcon-Servers). |
| Einzeltag-Diagnose | Liest Rohwerte dieses Tages inklusive zeitlicher Nachbarwerte, untersucht auffällige Werte und erklärt Bedingungen, die ihre Erkennung verhindern. Kein Schreibzugriff. |
| Letztes Prüfergebnis / Diagnose | Persistenter Bericht der zuletzt ausgeführten Diagnose oder Archivprüfung. |
| Erkannte und untersuchte Archivwerte | Bis zu 300 Rohwerte mit Zeitstempel, Wert, optionalen Nachbarwerten und Status (z. B. erkannt, nicht gelöscht, gelöscht, noch vorhanden). Wird bei nächster Prüfung ersetzt. |

Änderungen in den Einstellungen vor dem Auslösen über **Übernehmen** speichern. Zur Untersuchung des bekannten Beispielwerts die Variable `38701` und den Tag `2026-11-16` wählen. Bei größerem Prüfzeitraum bleiben die bisherigen Aktionsknöpfe erhalten. Eine Reaggregation erfolgt **ausschließlich**, wenn ein Löschlauf mindestens einen Datensatz tatsächlich gelöscht hat; nicht bei Lernen, Vorschau oder Diagnose.

## Zeitprüfung ab v0.3.05

**Messwerte:** Kein starres Zeitlimit mehr für die Erkennung. Einzeltag-Diagnose und Prüfblöcke laden jeweils mindestens 48 Stunden Kontext, um vor/nach dem vermuteten Fehler plausible Nachbarn zu finden. Es wird nicht automatisch interpoliert oder ein fehlender Rohwert erzeugt. Maßgeblich sind Abweichungsschwelle, Nachbar-Toleranz und die maximale Zahl kurzer Fehlerwerte. Die vergangene Zeit kann die erlernte Abweichungsschwelle weiterhin moderat beeinflussen.

**Sicherheit bei Datenlücken:** Wenn mehr als 120 Minuten zwischen einem verdächtigen Wert und dem relevanten Anker oder innerhalb der Fehlerfolge liegen, wird ein erkannter Punkt mit **NUR VORSCHAU: große Datenlücke** markiert. Der Löschlauf überspringt ihn; es erfolgt für diesen Punkt keine Löschung. Bei noch größeren Datenlücken außerhalb des geladenen Kontextfensters kann die Erkennung unvollständig sein.

**Zähler:** `Max. Abstand (min)` bleibt eine strikte Bedingung für zeitlich plausible Zählerverläufe.

`Lückenfaktor` ist kein konfigurierbarer Parameter mehr; vorhandene Einstellungen werden bei Updates nicht aktiv genutzt. Bei Vorschau, Diagnose und Lernen wird nie reaggregiert. Bei Löschen reaggregiert das Modul ausschließlich Variablen mit tatsächlich gelöschten Rohwerten.
