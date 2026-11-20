# ArchiveWatchdog – Konfiguration

Version 0.3.02 · Build 5

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
| Max. Abstand (min) | Maximaler zeitlicher Abstand, in dem Messpunkte für zusammenhängende Prüfung herangezogen werden. |
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
