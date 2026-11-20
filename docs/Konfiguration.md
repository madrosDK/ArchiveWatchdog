## Verhalten v0.3.11

**Messwert:** Keine feste Zeitbegrenzung in Erkennung und Löschung. Gültige Vergleichswerte links/rechts und eine ausreichend große Abweichung bleiben notwendig.

**Zähler aufwärts/abwärts:** Die Lernfunktion berechnet jetzt zusätzlich die typische Änderungsrate pro Stunde aus richtungskonformen Archivschritten, wobei die obersten 10 % der Raten für den Lernwert abgeschnitten werden. Ein Sprung wird nur als automatischer Löschkandidat betrachtet, wenn der Zählerstand wieder zu einer plausiblen fortlaufenden Linie zurückkehrt. Größere Zeitabstände als „Max. Abstand“ kennzeichnen Kandidaten zur gesonderten manuellen Freigabe. Ein dauerhaft neuer Zählerstand wird bewusst nicht automatisch in alte Werte umgerechnet; hierzu ist eine separat entwickelte Offset-/Resetfunktion nötig.

**Optionen „Zähler-Ausreißer mit großen Zeitlücken freigeben“:** Löschen nach ausdrücklicher Bestätigung auch markierte Zählerkandidaten. Keine Freigabe im automatischen Timer. Vorher Sicherung und Vorschau empfohlen.

**Reaggregation:** Ein Aufruf je Variable nach mindestens einer tatsächlich erfolgreichen Löschung. Nicht bei Diagnose, Vorschau oder Lernen.

# Konfigurationsänderung v0.3.09

- **Messwert:** Der Zeitabstand beeinflusst die Analyse unterstützend, verhindert aber weder die Erkennung noch die ausdrücklich bestätigte Löschung. Die frühere 120-Minuten-Schutzsperre entfällt.
- **Zähler aufwärts/abwärts:** Die Einstellung „Max. Abstand (min, nur Zähler)“ bleibt wirksam; Zählerresets werden nicht automatisch korrigiert.
- **Vorschau / Diagnose / Lernen:** Keine Archivänderung, keine Reaggregation.
- **Ausreißer löschen:** Nach Bestätigung werden ausschließlich erkannte Kandidaten gelöscht; nach mindestens einem erfolgreich gelöschten Datensatz wird die Reaggregation **einmal pro Variable angefordert**, nicht als abgeschlossen gemeldet.
- **Einzelwert löschen:** Der Diagnosetag, die Variable und die Uhrzeit erlauben weiterhin eine ausdrücklich bestätigte, erneute Prüfung und anschließende gezielte Löschung.
- Zwei redundant gewordene Aktionen „Vollständige Bereinigung MIT manueller Freigabe“ sind aus der Oberfläche entfernt. Bestehende Eigenschaften und Lernprofile bleiben erhalten.

**Hinweis:** Der unten stehende historische Dokumentationsverlauf beschreibt teilweise das frühere Sicherheitsverhalten bis v0.3.08. Für v0.3.09 gelten die vorstehenden Regeln.

# ArchiveWatchdog – Konfiguration

Version 0.3.08 · Build 11

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

## Vereinfachte Variablen-Einstellungen (v0.3.06)

| Einstellung | Funktion |
| --- | --- |
| Variable | Archivierte Integer-/Float-Variable auswählen. |
| Verlauf | Messwert / Zähler aufwärts / Zähler abwärts. |
| Empfindlichkeit | Empfindlich = engere, Normal = mittlere, Großzügig = weitere Grenzwerte. Verwendet das bestehende Lernprofil, wenn vorhanden. |
| Aktiv | Variable in Prüfungen berücksichtigen. |
| Erweiterte Einstellungen für Variablen anzeigen | Schaltet nach „Übernehmen“ zusätzliche Spalten frei. |
| Manuelle Grenzen | Verwendet die gespeicherten Werte für Min. Sprung und Nachbar-Toleranz anstatt der automatisch gewählten Werte. |
| Max. Abstand (min) | Nur für Zählervariablen; ohne Einfluss auf die Zeitgrenze normaler Messwerte. |

Im Automatikmodus gilt für Messwerte mit q95-Lernwert die automatische Sprungschwelle: maximal aus 0,5 und q95 × Empfindlich 1,5 / Normal 3 / Großzügig 5. Die Nachbar-Toleranz wird ähnlich aus q95 abgeleitet. Bei fehlendem Lernprofil gelten weiterhin die bisherigen manuellen Fallbackwerte. Der frühere globale Großzügigkeitsfaktor wirkt nur bei ausdrücklich aktivierten manuellen Grenzen.

## Einzeltag-Diagnose und gezielte Löschung

Die Diagnose berechnet die wirksamen Grenzwerte aus demselben Profil und derselben Funktion wie die vollständige Vorschau. Eine Diagnose am 16.11. kann Treffer zeigen, während ein normaler 48-Stunden-Rückblick ab 18.11. keinen Treffer enthält. Für historische Werte den Archiv-Startzeitpunkt passend setzen und die vollständige Archivvorschau wählen.

Die Einstellung **Manuelle Freigabe: Uhrzeit HH:MM:SS** bezeichnet exakt einen bereits in der Diagnose erkannten *Nur manuelle Prüfung*-Kandidaten des angegebenen Diagnosetags. **Manuell bestätigten Ausreißer ... löschen** verlangt eine Sicherheitsbestätigung und prüft den Punkt vor der Löschung erneut. Nur nach tatsächlicher Löschung wird die Reaggregation angefordert. Es gibt keine automatische Sammellöschung für diese Review-Kandidaten. Bei Vorschauläufen wird nie reaggregiert.

## Vollständige Bereinigung mit manueller Freigabe (v0.3.07)

**Sichere Bereinigung – Prüfzeitraum / Archiv ab Startdatum:** Bestehende Erkennung und die 120-Minuten-Sicherheitsregel bleiben erhalten. Erkannte Ausreißer mit großen Datenlücken werden gemeldet, nicht gelöscht.

**Vollständige Bereinigung MIT manueller Freigabe – Prüfzeitraum / Archiv ab Startdatum:** Nur nach der direkten Bestätigung des jeweiligen Buttons werden auch die erkannten und als `ReviewOnly` markierten Kandidaten gelöscht. Die separate Freigabe gilt ausschließlich für diesen Aufruf. Sie wird weder dauerhaft gespeichert noch vom Timer übernommen. Bei einem Archiv-Gesamtlauf gilt das konfigurierte `Archiv ab Datum`.

**Vorgeschlagener Ablauf:** Archiv sichern → Zeitraum kontrollieren → passende Vorschau starten → Tabelle mit maximal 300 Einträgen und vollständige Gesamtzahlen prüfen → bestätigte vollständige Bereinigung starten → im Abschlussbericht die Anzahl tatsächlich gelöschter Datensätze kontrollieren → Abschluss der Reaggregation im IP-Symcon-Meldungsfenster prüfen und Diagramm neu laden. Eine 0 in der Löschstatistik bedeutet, dass kein Archivwert entfernt wurde.

**Risiko:** Bei großen Datenlücken kann ein erkannter Kandidat ein echter Messwert sein. Die Bestätigung hebt nur die Sperre für große Lücken auf und löscht weiterhin ausschließlich erkannte Kandidaten; sie garantiert keine fehlerfreie Klassifizierung. Dauerhafte Zähler-Resets und nicht erkannte Fehler werden nicht automatisch korrigiert. Reaggregation findet ausschließlich nach einem positiven Lösch-Rückgabewert einmal je betroffener Variable statt.

## Fehlerkorrektur in v0.3.08

Bei allen sechs Archivscan-Aktionen werden Instanz-ID und die drei Parameter der Methode `Scan` explizit übergeben, damit der von IP-Symcon generierte globale `AWD_Scan`-Wrapper nicht mit `ArgumentCountError` abbricht. Die automatischen Timer und die Lösch-/Reaggregationsregeln bleiben unverändert.


## Zähler-Störphasen

Zusätzlich zu einzelnen kurzzeitigen Messfehlern sucht der Watchdog nach längeren Folgen großer Rück- und Vorwärtssprünge (max. 48 h zwischen erstem und letztem Sprung, Ankerfenster bis 72 h). Erforderlich sind mindestens drei plausible Fortschritte vor und nach der Störung sowie ein insgesamt plausibler Stand am Ende. Die Ausreißer werden gegen eine linear interpolierte Ankerlinie mit großzügigem Abstandskorridor geprüft. Die Erkennung nutzt eine robuste Normalrate aus den kleineren Zählerschritten, um falsche Spitzen nicht als normal anzulernen. Bei längeren Störphasen kann es Mehrdeutigkeiten geben; sie sind deshalb als **manuelle Prüfung nötig** gekennzeichnet und werden durch AutoDelete oder gewöhnliche sichere Löschung übersprungen. Nur gesondert bestätigte Löschaktionen können sie entfernen. Die Reaggregation erfolgt nach erfolgreicher Löschung einmal je Variable. Nicht alle echten Fehlerphasen sind automatisch eindeutig rekonstruierbar; bei dauerhaftem Zähleroffset bleibt manuelle Korrektur nötig.
