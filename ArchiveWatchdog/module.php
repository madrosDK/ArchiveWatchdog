<?php
/** ArchiveWatchdog v0.3.11 (Build 14). Archive analysis only; never overwrites live variable values. */
class ArchiveWatchdog extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Enabled', false);
        $this->RegisterPropertyBoolean('AutoDelete', false);
        $this->RegisterPropertyInteger('IntervalMinutes', 5);
        $this->RegisterPropertyInteger('LookbackHours', 24);
        $this->RegisterPropertyInteger('MinAgeMinutes', 5);
        $this->RegisterPropertyInteger('TrainingDays', 14);
        $this->RegisterPropertyFloat('SafetyFactor', 3.0);
        $this->RegisterPropertyString('Targets', '[]');
        $this->RegisterPropertyBoolean('AdvancedSettings', false);
        $this->RegisterPropertyString('ManualDeleteTimestamp', '');
        $this->RegisterPropertyString('ArchiveStartDate', '2020-01-01');
        $this->RegisterPropertyInteger('MaxErrorSeries', 6);
        $this->RegisterAttributeString('LearnedProfiles', '{}');
        $this->RegisterAttributeString('LastReport', 'Noch keine Prüfung durchgeführt.');
        $this->RegisterAttributeString('DiagnosisRows', '[]');
        $this->RegisterPropertyInteger('DiagnosticVariableID', 0);
        $this->RegisterPropertyString('DiagnosticDate', '2026-11-16');
        $this->RegisterTimer('ScanTimer', 0, 'AWD_AutoScan($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $interval = max(1, $this->ReadPropertyInteger('IntervalMinutes'));
        $this->SetTimerInterval('ScanTimer', $this->ReadPropertyBoolean('Enabled') ? $interval * 60000 : 0);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if (!$this->ReadPropertyBoolean('AdvancedSettings')) {
            foreach ($form['elements'] as &$element) {
                if (($element['name'] ?? '') === 'Targets') {
                    $element['columns'] = array_values(array_filter($element['columns'], static function ($col) {
                        return !in_array($col['name'], ['Spike', 'NeighborTolerance', 'MaxGapMinutes', 'UseManual'], true);
                    }));
                }
            }
            unset($element);
        }
        $form['actions'][] = [
            'type' => 'ExpansionPanel', 'caption' => 'Letztes Prüfergebnis / Diagnose',
            'items' => [
                ['type' => 'Label', 'caption' => $this->ReadAttributeString('LastReport')],
                [
                    'type' => 'List', 'caption' => 'Erkannte und untersuchte Archivwerte (max. 300)',
                    'add' => false, 'delete' => false, 'rowCount' => 12,
                    'values' => json_decode($this->ReadAttributeString('DiagnosisRows'), true) ?: [],
                    'columns' => [
                        ['name' => 'Date', 'caption' => 'Zeitpunkt', 'width' => '155px'],
                        ['name' => 'Variable', 'caption' => 'Variable', 'width' => '135px'],
                        ['name' => 'Value', 'caption' => 'Wert', 'width' => '85px'],
                        ['name' => 'Neighbors', 'caption' => 'Vorher / Nachher', 'width' => '155px'],
                        ['name' => 'Status', 'caption' => 'Erkennung / Löschung', 'width' => '200px']
                    ]
                ]
            ]
        ];
        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function AutoScan()
    {
        if ($this->ReadPropertyBoolean('Enabled')) {
            $this->Scan($this->ReadPropertyBoolean('AutoDelete'));
        }
    }

    private function archiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return count($ids) ? (int) $ids[0] : 0;
    }

    private function targets(): array
    {
        $value = json_decode($this->ReadPropertyString('Targets'), true);
        return is_array($value) ? $value : [];
    }

    private function profiles(): array
    {
        $value = json_decode($this->ReadAttributeString('LearnedProfiles'), true);
        return is_array($value) ? $value : [];
    }

    /** A single effective configuration is used by scan, day diagnosis and deletion. */
    private function effectiveTarget(array $target, array $profile): array
    {
        $mode = (int) ($target['Mode'] ?? 0);
        $sensitivity = max(0, min(2, (int) ($target['Sensitivity'] ?? 1)));
        // Larger factor = more tolerant. Robust q95 is used instead of q99,
        // since obvious outliers can contaminate the high percentiles.
        $factors = [1.5, 3.0, 5.0];
        $q95 = max(0.0, (float) ($profile['q95'] ?? 0.0));
        if (!empty($target['UseManual'])) {
            $target['Spike'] = max(0.001, (float) ($target['Spike'] ?? 3.0));
            $target['NeighborTolerance'] = max(0.0, (float) ($target['NeighborTolerance'] ?? 1.0));
            $target['_Factor'] = max(1.0, $this->ReadPropertyFloat('SafetyFactor'));
        } elseif ($q95 > 0.0) {
            $target['Spike'] = max($mode === 0 ? 0.5 : 0.001, $q95 * $factors[$sensitivity]);
            $target['NeighborTolerance'] = max($mode === 0 ? 0.5 : 0.001, $q95 * ($sensitivity === 0 ? 1 : ($sensitivity === 1 ? 1.5 : 2.5)));
            $target['_Factor'] = 1.0; // Already applied in Spike above.
        } else {
            // No learned profile: preserve the existing manual thresholds so
            // that a new instance works without forcing a learning step.
            $target['Spike'] = max(0.001, (float) ($target['Spike'] ?? 3.0));
            $target['NeighborTolerance'] = max(0.0, (float) ($target['NeighborTolerance'] ?? 1.0));
            $target['_Factor'] = 1.0;
        }
        return $target;
    }

    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        $pos = ($n - 1) * $p;
        $a = (int) floor($pos);
        $b = min($a + 1, $n - 1);
        return (float) ($sorted[$a] + ($sorted[$b] - $sorted[$a]) * ($pos - $a));
    }

    private function saveReport(string $report, array $rows): void
    {
        $this->WriteAttributeString('LastReport', $report);
        $this->WriteAttributeString('DiagnosisRows', json_encode(array_slice($rows, 0, 300), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->SendDebug('Diagnosis', $report, 0);
    }

    private function detailRow(int $id, array $item, string $status, string $neighbors = ''): array
    {
        return [
            'Date' => date('d.m.Y H:i:s', (int) $item['TimeStamp']),
            'Variable' => IPS_GetName($id) . ' (' . $id . ')',
            'Value' => (string) $item['Value'],
            'Neighbors' => $neighbors,
            'Status' => $status
        ];
    }

    /** Focused raw-data check, independently of the trained detection result. Never deletes. */
    public function DiagnoseDay(): string
    {
        $id = $this->ReadPropertyInteger('DiagnosticVariableID');
        $date = $this->ReadPropertyString('DiagnosticDate');
        $archive = $this->archiveID();
        if (!$archive || $id <= 0 || !IPS_VariableExists($id)) {
            return 'Diagnose: Bitte eine gültige Variable wählen und ein Archiv bereitstellen.';
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            return 'Diagnose: Datum muss JJJJ-MM-TT sein.';
        }
        $target = null;
        foreach ($this->targets() as $t) {
            if ((int) ($t['VariableID'] ?? 0) === $id) { $target = $t; break; }
        }
        $start = $dt->getTimestamp();
        $end = $start + 86399;
        $lines = ['Einzeltag-Diagnose ' . $date . ': ' . IPS_GetName($id) . ' (' . $id . ')',
            'Nur Rohdatenanalyse, keine Löschung, keine Reaggregation.'];
        if ($target === null) {
            $lines[] = 'WARNUNG: Variable ist nicht in den überwachten Variablen konfiguriert.';
            $target = ['Mode' => 0, 'Spike' => 3, 'NeighborTolerance' => 1, 'MaxGapMinutes' => 30];
        } elseif (isset($target['Enabled']) && !$target['Enabled']) {
            $lines[] = 'HINWEIS: Variable ist in der Überwachung deaktiviert.';
        }
        $profile = $this->profiles()[(string) $id] ?? [];
        $mode = (int) ($target['Mode'] ?? 0);
        if (!$profile) { $lines[] = 'WARNUNG: Kein Lernprofil, es gelten nur manuelle Grenzwerte.'; }
        elseif ((int) ($profile['mode'] ?? -1) !== $mode) {
            $lines[] = 'WARNUNG: Lernprofil für anderen Verlaufstyp. Bitte neu lernen.';
            $profile = [];
        }
        $target = $this->effectiveTarget($target, $profile);
        $gap = $this->allowedGap($target, $profile);
        $pad = (int) ($target['Mode'] ?? 0) === 0 ? max(172800, ($this->ReadPropertyInteger('MaxErrorSeries') + 2) * $gap) : max(259200, ($this->ReadPropertyInteger('MaxErrorSeries') + 2) * $gap);
        try {
            $rows = AC_GetLoggedValues($archive, $id, max(1, $start - $pad), $end + $pad, 10000);
        } catch (Throwable $e) {
            return 'Archiv-Abfrage fehlgeschlagen: ' . $e->getMessage();
        }
        if (count($rows) >= 10000) { return 'Diagnose abgebrochen: Mehr als 9999 Werte im Abfragefenster. Kein vollständiger Datensatz.'; }
        usort($rows, static fn($a, $b) => (int) $a['TimeStamp'] <=> (int) $b['TimeStamp']);
        $detected = $this->detect($rows, $target, $profile, $start, $end);
        $lines[] = $mode === 0
            ? 'Messwert: keine feste Zeitgrenze und keine zeitbasierte Löschsperre. Erkennung anhand plausibler Nachbarwerte.'
            : 'Zähler: maximaler Zeitabstand ' . round($gap / 60, 1) . ' min.';
        $lines[] = 'Rohdaten inkl. Nachbarschaft: ' . count($rows) . '; erkannte Ausreißer an diesem Tag: ' . count($detected) . '.';
        $lines[] = 'Aktive Schwellen: Sprung ' . round((float)$target['Spike'], 3) . ', Nachbar-Toleranz ' . round((float)$target['NeighborTolerance'], 3) . '.';
        $diag = [];
        foreach ($rows as $i => $row) {
            $ts = (int) $row['TimeStamp'];
            if ($ts < $start || $ts > $end) { continue; }
            $val = (float) $row['Value'];
            $prev = $rows[$i-1] ?? null;
            $next = $rows[$i+1] ?? null;
            $abnormal = $val == 0.0;
            if ($prev && $next) {
                $deviation = abs($val - ((float)$prev['Value'] + (float)$next['Value']) / 2);
                $abnormal = $abnormal || $deviation >= max(0.001, (float)($target['Spike'] ?? 3));
            }
            if (!isset($detected[(string)$ts]) && !$abnormal) { continue; }
            $neighbors = ($prev ? (string)$prev['Value'] : '–') . ' / ' . ($next ? (string)$next['Value'] : '–');
            if (isset($detected[(string)$ts])) {
                $status = 'ERKANNT: ' . ($detected[(string)$ts]['Reason'] ?? (!empty($detected[(string)$ts]['ReviewOnly']) ? 'manuelle Freigabe erforderlich' : 'löschbar nach Bestätigung'));
            } elseif (!$prev || !$next) {
                $status = 'NICHT ERKANNT: Nachbarwert fehlt';
            } else {
                $dt1 = $ts - (int)$prev['TimeStamp'];
                $dt2 = (int)$next['TimeStamp'] - $ts;
                $threshold = $this->threshold($target, $profile, min($dt1, $dt2));
                $anchor = abs((float)$next['Value'] - (float)$prev['Value']);
                $maxAnchor = max((float)($target['NeighborTolerance'] ?? 1), (float)($profile['q95'] ?? 0) * 1.5);
                if ($mode !== 0 && ($dt1 > $gap || $dt2 > $gap)) {
                    $status = 'NICHT ERKANNT: Zähler-Zeitabstand ' . max($dt1, $dt2) . ' s > ' . $gap . ' s';
                } elseif ($anchor > $maxAnchor) {
                    $status = 'NICHT ERKANNT: Nachbarn differieren ' . round($anchor, 2) . ' > ' . round($maxAnchor, 2);
                } else {
                    $status = 'NICHT ERKANNT: Schwelle ' . round($threshold, 2) . ' / ggf. Fehlerfolge';
                }
            }
            $diag[] = $this->detailRow($id, $row, $status, $neighbors);
            $lines[] = date('d.m.Y H:i:s', $ts) . ' | ' . $val . ' | Nachbarn ' . $neighbors . ' | ' . $status;
            if (count($diag) >= 300) { $lines[] = 'Diagnose auf 300 auffällige Werte begrenzt.'; break; }
        }
        if (!$diag) { $lines[] = 'Keine auffälligen Rohwerte nach Diagnosekriterien gefunden.'; }
        $report = implode("\n", $lines);
        $this->saveReport($report, $diag);
        return $report;
    }

    /** Explicitly confirmed deletion of one detected candidate from the selected day.
     * The point is re-detected from original raw data immediately before deletion.
     */
    public function DeleteReviewedPoint(): string
    {
        $id = $this->ReadPropertyInteger('DiagnosticVariableID');
        $stamp = trim($this->ReadPropertyString('ManualDeleteTimestamp'));
        $date = $this->ReadPropertyString('DiagnosticDate');
        $archive = $this->archiveID();
        if (!$archive || !IPS_VariableExists($id)) { return 'Ungültige Variable oder fehlendes Archiv.'; }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $tsObj = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $stamp);
        if (!$dt || !$tsObj || $tsObj->format('Y-m-d H:i:s') !== $date . ' ' . $stamp) {
            return 'Bitte Diagnose-Datum und Uhrzeit im Format HH:MM:SS angeben.';
        }
        $ts = $tsObj->getTimestamp();
        $start = $dt->getTimestamp();
        if ($ts < $start || $ts >= $start + 86400) { return 'Zeit liegt außerhalb des Diagnosetags.'; }
        $target = null;
        foreach ($this->targets() as $t) {
            if ((int) ($t['VariableID'] ?? 0) === $id) { $target = $t; break; }
        }
        if ($target === null || (isset($target['Enabled']) && !$target['Enabled'])) {
            return 'Variable muss aktiv zur Überwachung hinzugefügt sein.';
        }
        $profile = $this->profiles()[(string)$id] ?? [];
        if ($profile && (int)($profile['mode'] ?? -1) !== (int)($target['Mode'] ?? 0)) { $profile = []; }
        $target = $this->effectiveTarget($target, $profile);
        $gap = $this->allowedGap($target, $profile);
        $pad = (int)($target['Mode'] ?? 0) === 0 ? max(172800, ($this->ReadPropertyInteger('MaxErrorSeries') + 2) * $gap)
            : max(259200, ($this->ReadPropertyInteger('MaxErrorSeries') + 2) * $gap);
        try {
            $rows = AC_GetLoggedValues($archive, $id, max(1, $start - $pad), $start + 86399 + $pad, 10000);
            if (count($rows) >= 10000) { return 'Zu viele Rohwerte; Löschung abgebrochen.'; }
            usort($rows, static fn($a, $b) => (int)$a['TimeStamp'] <=> (int)$b['TimeStamp']);
            $bad = $this->detect($rows, $target, $profile, $start, $start + 86399);
            if (!isset($bad[(string)$ts])) {
                return 'Der Zeitstempel ist aktuell kein erkannter Ausreißer. Nichts gelöscht.';
            }
            $removed = AC_DeleteVariableData($archive, $id, $ts, $ts);
            if (!is_int($removed) || $removed < 1) { return 'Löschfunktion hat keine Datensätze entfernt.'; }
            $remaining = AC_GetLoggedValues($archive, $id, max(1, $ts - 1), $ts + 1, 100);
            $found = false;
            foreach ($remaining as $row) { if ((int)($row['TimeStamp'] ?? -1) === $ts) { $found = true; break; } }
            $requested = AC_ReAggregateVariable($archive, $id);
            $report = 'Manuell bestätigte Löschung: ' . IPS_GetName($id) . ' (' . $id . ') ' . date('d.m.Y H:i:s', $ts)
                . '; Datensätze laut Archiv-API entfernt: ' . $removed
                . '; Rohwert noch vorhanden: ' . ($found ? 'JA – prüfen!' : 'nein')
                . '; Reaggregation: ' . ($requested ? 'ANGEFORDERT (Abschluss nicht bestätigt)' : 'FEHLER beim Start');
            $this->saveReport($report, [$this->detailRow($id, $bad[(string)$ts], $found ? 'WARNUNG: Rohwert vorhanden' : 'GELÖSCHT; Reaggregation angefordert')]);
            return $report;
        } catch (Throwable $e) {
            return 'Manuelle Löschung fehlgeschlagen: ' . $e->getMessage();
        }
    }

    /** Training is manual and never deletes anything. */
    public function Learn(): string
    {
        $archive = $this->archiveID();
        if (!$archive) {
            return 'Kein Archiv gefunden.';
        }
        $days = max(1, min(365, $this->ReadPropertyInteger('TrainingDays')));
        $end = time() - 3600;
        $start = $end - $days * 86400;
        $profiles = $this->profiles();
        $lines = ['Lernlauf: ' . $days . ' Tage, nur Statistik (keine Löschung).'];
        foreach ($this->targets() as $t) {
            if (isset($t['Enabled']) && !$t['Enabled']) {
                continue;
            }
            $id = (int) ($t['VariableID'] ?? 0);
            if ($id <= 0 || !IPS_VariableExists($id)) {
                continue;
            }
            $mode = (int) ($t['Mode'] ?? 0);
            $gap = max(1, (int) ($t['MaxGapMinutes'] ?? 30)) * 60;
            $steps = [];
            $rates = []; // monotonic counter progress per hour
            $intervals = [];
            $reversals = 0;
            $prev = null;
            $error = '';
            // Prevent the 10,000-value response cap from silently truncating training.
            for ($from = $start; $from < $end; $from += 86400) {
                $to = min($end, $from + 86400);
                try {
                    $rows = AC_GetLoggedValues($archive, $id, $from, $to, 10000);
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                    break;
                }
                if (count($rows) >= 10000) {
                    $error = 'Mindestens 10000 Werte an einem Tag; Lernzeitraum nicht vollständig. Keine Profiländerung.';
                    break;
                }
                usort($rows, static fn($a, $b) => (int) $a['TimeStamp'] <=> (int) $b['TimeStamp']);
                foreach ($rows as $r) {
                    $ts = (int) ($r['TimeStamp'] ?? 0);
                    $v = (float) ($r['Value'] ?? NAN);
                    if (!is_finite($v) || $ts <= 0 || ($prev !== null && $ts <= $prev[0])) {
                        continue;
                    }
                    if ($prev !== null) {
                        $dt = $ts - $prev[0];
                        if ($dt <= $gap) {
                            $diff = $v - $prev[1];
                            if (($mode === 1 && $diff < 0) || ($mode === 2 && $diff > 0)) {
                                $reversals++;
                            } else {
                                $steps[] = abs($diff);
                                if ($mode !== 0) {
                                    $rates[] = abs($diff) * 3600.0 / $dt;
                                }
                                $intervals[] = $dt;
                            }
                        }
                    }
                    $prev = [$ts, $v];
                }
            }
            if ($error !== '') {
                $lines[] = IPS_GetName($id) . ': ' . $error;
                continue;
            }
            if (count($steps) < 30) {
                $lines[] = IPS_GetName($id) . ': nur ' . count($steps) . ' geeignete Schritte (mindestens 30 erforderlich).';
                continue;
            }
            sort($steps, SORT_NUMERIC);
            sort($rates, SORT_NUMERIC);
            sort($intervals, SORT_NUMERIC);
            // Avoid learning the largest 10% of (potentially erroneous) positive counter jumps.
            // q95 of the lower 90% is conservative, while an outlier cannot single-handedly inflate it.
            $trimmedRates = $mode === 0 ? [] : array_slice($rates, 0, max(1, (int) floor(count($rates) * 0.9)));
            $p = [
                'mode' => $mode,
                'q95' => $this->percentile($steps, 0.95),
                'q99' => $this->percentile($steps, 0.99),
                'rate95PerHour' => count($trimmedRates) ? $this->percentile($trimmedRates, 0.95) : 0.0,
                'medianInterval' => max(1.0, $this->percentile($intervals, 0.5)),
                'steps' => count($steps),
                'reversals' => $reversals,
                'learnedAt' => time(),
                'trainingDays' => $days
            ];
            $profiles[(string) $id] = $p;
            $lines[] = IPS_GetName($id) . ' (' . $id . '): ' . count($steps) . ' Schritte, 95%-Sprung=' . round($p['q95'], 3)
                . ', 99%-Sprung=' . round($p['q99'], 3) . ', typische Zeit=' . round($p['medianInterval'])
                . 's, Richtungswechsel=' . $reversals . '.';
        }
        $this->WriteAttributeString('LearnedProfiles', json_encode($profiles));
        $report = implode("\n", $lines);
        $this->SendDebug('Learn', $report, 0);
        return $report;
    }

    /**
     * Archiviert werden nur Wertänderungen; unveränderte Messungen erzeugen keine neuen Rohwerte.
     * Bei Messwerten deshalb die zeitliche Lücke anhand des gelernten typischen Abstands
     * vorsichtig erweitern. Zähler verwenden weiterhin den manuell konfigurierten Grenzwert.
     */
    private function allowedGap(array $target, array $profile): int
    {
        $manual = max(1, (int) ($target['MaxGapMinutes'] ?? 30)) * 60;
        if ((int) ($target['Mode'] ?? 0) !== 0) {
            return $manual;
        }
        $typical = (float) ($profile['medianInterval'] ?? 0);
        if ($typical <= 0 || !is_finite($typical)) {
            return $manual;
        }
        // Standard: bis zu acht typische Abstände, maximal zwei Stunden.
        // Die Ankerwerte müssen weiterhin nahe beieinanderliegen; dies ist keine
        // Freigabe für beliebige Ausreißer bei großen Datenlücken.
        $multiplier = max(1, min(20, (int) ($target['GapMultiplier'] ?? 8)));
        return max($manual, min(7200, (int) ceil($typical * $multiplier)));
    }

    private function threshold(array $target, array $profile, int $dt): float
    {
        $manual = max(0.001, (float) ($target['Spike'] ?? 3));
        $typical = max(1.0, (float) ($profile['medianInterval'] ?? $dt));
        $scale = min(4.0, max(1.0, sqrt($dt / $typical)));
        $factor = max(1.0, min(20.0, ($target['_Factor'] ?? $this->ReadPropertyFloat('SafetyFactor'))));
        if ((int) ($target['Mode'] ?? 0) !== 0 && (float) ($profile['rate95PerHour'] ?? 0.0) > 0) {
            // Real counter progress depends on elapsed time, not on how often a value was logged.
            // Only transient deviations outside the anchor trend are deletion candidates.
            return max($manual, (float) $profile['rate95PerHour'] * $dt / 3600.0 * $factor);
        }
        return max($manual, (float) ($profile['q95'] ?? 0) * $factor * $scale);
    }

    /**
     * Erkenne mehrstündige Zähler-Störphasen mit wiederholten Vor-/Rücksprüngen.
     * An beiden Seiten sind mehrere ruhige, richtungstreue Anker notwendig.
     * Mehrpunkt-Phasen bleiben IMMER manuell prüfpflichtig (ReviewOnly),
     * auch wenn die automatische Löschung eingeschaltet ist.
     */
    private function detectCounterEpisodes(array $rows, array $target, array $profile, int $start, int $end): array
    {
        $n = count($rows);
        if ($n < 12) { return []; }
        $mode = (int)($target['Mode'] ?? 0);
        if ($mode === 0) { return []; }
        $dir = $mode === 1 ? 1.0 : -1.0;
        $positive = [];
        $timeRates = [];
        for ($i = 1; $i < $n; $i++) {
            $dt = (int)$rows[$i]['TimeStamp'] - (int)$rows[$i-1]['TimeStamp'];
            $dv = $dir * ((float)$rows[$i]['Value'] - (float)$rows[$i-1]['Value']);
            if ($dt > 0 && $dv >= 0 && is_finite($dv)) {
                $positive[] = $dv;
                $timeRates[] = $dv * 3600.0 / $dt;
            }
        }
        if (count($positive) < 6) { return []; }
        sort($positive, SORT_NUMERIC);
        sort($timeRates, SORT_NUMERIC);
        // Untere Hälfte verhindert, dass wiederholte, große Fehl-Sprünge
        // die für die Erkennung verwendete Normalrate selbst erhöhen.
        $medianStep = max(0.001, $this->percentile($positive, 0.40));
        $baseRate = max(0.001, $this->percentile($timeRates, 0.40));
        $edgeBad = array_fill(0, $n, false);
        $edgeLimit = [];
        for ($i = 1; $i < $n; $i++) {
            $dt = (int)$rows[$i]['TimeStamp'] - (int)$rows[$i-1]['TimeStamp'];
            if ($dt <= 0) { continue; }
            $dv = $dir * ((float)$rows[$i]['Value'] - (float)$rows[$i-1]['Value']);
            $limit = max(8.0 * $medianStep, 4.0 * $baseRate * $dt / 3600.0, 1.0);
            $edgeLimit[$i] = $limit;
            $edgeBad[$i] = $dv < -$limit || $dv > $limit;
        }
        $badEdges = [];
        for ($i = 1; $i < $n; $i++) {
            if ($edgeBad[$i]) { $badEdges[] = $i; }
        }
        if (count($badEdges) < 2) { return []; }

        $isStable = static function (int $a, int $b) use ($rows, $edgeBad): bool {
            if ($a < 0 || $b >= count($rows) || $b - $a < 2) { return false; }
            for ($k = $a + 1; $k <= $b; $k++) {
                if ($edgeBad[$k]) { return false; }
                if ((int)$rows[$k]['TimeStamp'] <= (int)$rows[$k-1]['TimeStamp']) { return false; }
            }
            return true;
        };
        $result = [];
        $groups = [];
        $group = [];
        foreach ($badEdges as $idx) {
            if ($group && (int)$rows[$idx]['TimeStamp'] - (int)$rows[end($group)]['TimeStamp'] > 24*3600) {
                $groups[] = $group;
                $group = [];
            }
            $group[] = $idx;
        }
        if ($group) { $groups[] = $group; }
        foreach ($groups as $g) {
            if (count($g) < 2) { continue; }
            $first = $g[0];
            $last = end($g);
            if ((int)$rows[$last]['TimeStamp'] - (int)$rows[$first]['TimeStamp'] > 48*3600) { continue; }
            $left = -1;
            // Normaler Anker vor dem ersten Sprung: 3 saubere Verbindungen.
            for ($i = $first - 1; $i >= max(3, $first - 200); $i--) {
                if ($isStable($i - 3, $i)) { $left = $i; break; }
            }
            $right = -1;
            // Nach dem letzten Sprung: 3 aufeinanderfolgende plausible Verbindungen.
            for ($i = $last; $i <= min($n - 4, $last + 200); $i++) {
                if ($isStable($i, $i + 3)) { $right = $i; break; }
            }
            if ($left < 0 || $right <= $left + 1) { continue; }
            $aTs = (int)$rows[$left]['TimeStamp'];
            $zTs = (int)$rows[$right]['TimeStamp'];
            $aV = (float)$rows[$left]['Value'];
            $zV = (float)$rows[$right]['Value'];
            $duration = $zTs - $aTs;
            if ($duration <= 0 || $duration > 72*3600) { continue; }
            $anchorChange = $dir * ($zV - $aV);
            $anchorAllowance = max(4*$medianStep, 4*$baseRate*$duration/3600.0);
            // Keine Freigabe bei dauerhaft geändertem Zählerstand/Reset.
            if ($anchorChange < -$anchorAllowance || $anchorChange > $anchorAllowance) { continue; }
            $episode = [];
            for ($j = $left + 1; $j < $right; $j++) {
                $ts = (int)$rows[$j]['TimeStamp'];
                if ($ts < $start || $ts > $end) { continue; }
                $v = (float)$rows[$j]['Value'];
                $expected = $aV + ($zV-$aV)*($ts-$aTs)/$duration;
                // Achtung: großzügiger Korridor um die plausible Ankerlinie.
                $typicalSeconds = max(60.0, (float)($profile['medianInterval'] ?? 600.0));
                $threshold = max(8*$medianStep, 4*$baseRate*$typicalSeconds/3600.0, 1.0);
                if (abs($v - $expected) > $threshold) {
                    $episode[(string)$ts] = [
                        'TimeStamp'=>$ts, 'Value'=>$v, 'Expected'=>$expected,
                        'Threshold'=>$threshold, 'ReviewOnly'=>true,
                        'Reason'=>'Mehrpunkt-Zähler-Störphase; Anker '.date('d.m H:i',$aTs).' / '.date('d.m H:i',$zTs)
                    ];
                }
            }
            if (count($episode) >= 2) { $result += $episode; }
        }
        return $result;
    }

    /** Detect a short wrong-valued run surrounded by plausible anchors. */
    private function detect(array $rows, array $target, array $profile, int $start, int $end): array
    {
        $bad = [];
        $n = count($rows);
        $maxRun = max(1, min(20, $this->ReadPropertyInteger('MaxErrorSeries')));
        $gap = $this->allowedGap($target, $profile);
        $tol = max(0.0, (float) ($target['NeighborTolerance'] ?? 1));
        $mode = (int) ($target['Mode'] ?? 0);
        for ($i = 1; $i < $n - 1; $i++) {
            $left = $rows[$i - 1];
            $aTs = (int) $left['TimeStamp'];
            $aV = (float) $left['Value'];
            if (!is_finite($aV)) {
                continue;
            }
            $best = [];
            // Start with one suspect, extend to a small burst if needed.
            for ($length = 1; $length <= $maxRun && $i + $length < $n; $length++) {
                $right = $rows[$i + $length];
                $zTs = (int) $right['TimeStamp'];
                $zV = (float) $right['Value'];
                if (!is_finite($zV) || $zTs <= $aTs) {
                    break;
                }
                $ok = true;
                for ($j = $i; $j <= $i + $length; $j++) {
                    $prevTs = (int) $rows[$j - 1]['TimeStamp'];
                    $currTs = (int) $rows[$j]['TimeStamp'];
                    if ($currTs <= $prevTs) {
                        $ok = false;
                        break;
                    }
                }
                if (!$ok) {
                    break;
                }
                // A genuine counter reset or sustained change has no fast return.
                // For counter anchors allow normal accumulated progress, not a fixed neighbor tolerance.
                $anchorLimit = $mode === 0
                    ? max($tol, (float) ($profile['q95'] ?? 0) * max(1, $length) * 1.5)
                    : max($tol, $this->threshold($target, $profile, $zTs - $aTs) * ($length + 1));
                if (abs($zV - $aV) > $anchorLimit) {
                    continue;
                }
                if ($mode === 1 && $zV + $tol < $aV) {
                    continue;
                }
                if ($mode === 2 && $zV - $tol > $aV) {
                    continue;
                }
                $candidate = [];
                for ($j = $i; $j < $i + $length; $j++) {
                    $ts = (int) $rows[$j]['TimeStamp'];
                    $v = (float) $rows[$j]['Value'];
                    if (!is_finite($v) || $ts < $start || $ts > $end) {
                        $candidate = [];
                        break;
                    }
                    $expected = $aV + ($zV - $aV) * ($ts - $aTs) / ($zTs - $aTs);
                    $threshold = $this->threshold($target, $profile, min($ts - $aTs, $zTs - $ts));
                    if (abs($v - $expected) <= $threshold ||
                        ($mode === 1 && $v >= min($aV, $zV) && $v <= max($aV, $zV)) ||
                        ($mode === 2 && $v >= min($aV, $zV) && $v <= max($aV, $zV))) {
                        $candidate = [];
                        break;
                    }
                    // Messwerte: kein zeitbasiertes Löschverbot. Plausible Anker,
                    // Abweichungsschwelle und begrenzte Fehlerfolge sind ausschlaggebend.
                    // Zähler behalten ihre zeitbezogene Prüfung.
                    // The maximum gap is a counter-only safety threshold for automatic deletion.
                    // Long pauses do not hide the candidate from the preview, but need explicit review.
                    $counterLongGap = $mode !== 0 && (($ts - $aTs > $gap) || ($zTs - $ts > $gap));
                    $candidate[] = ['TimeStamp' => $ts, 'Value' => $v, 'Expected' => $expected, 'Threshold' => $threshold, 'ReviewOnly' => $counterLongGap];
                }
                if ($candidate) {
                    $best = $candidate;
                    break; // shortest conclusive burst, avoids swallowing good readings
                }
            }
            if ($best) {
                foreach ($best as $item) {
                    $bad[(string) $item['TimeStamp']] = $item;
                }
                $i += count($best) - 1;
            }
        }
        // Zusätzliche Störphasen-Erkennung, unabhängig von MaxErrorSeries.
        if ($mode !== 0) {
            foreach ($this->detectCounterEpisodes($rows, $target, $profile, $start, $end) as $key => $item) {
                // Ein mehrpunktiger Störabschnitt hat Vorrang vor Einzelwert-Freigaben.
                $bad[$key] = $item;
            }
        }
        return $bad;
    }

    /** Scan normal range; or entire archive since configured ArchiveStartDate (in daily chunks). */
    public function Scan(bool $delete = false, bool $wholeArchive = false, bool $includeReview = false): string
    {
        $archive = $this->archiveID();
        if (!$archive) {
            return 'Kein Archiv gefunden.';
        }
        $profiles = $this->profiles();
        $end = time() - max(1, $this->ReadPropertyInteger('MinAgeMinutes')) * 60;
        if ($wholeArchive) {
            $date = $this->ReadPropertyString('ArchiveStartDate');
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$dt || $dt->format('Y-m-d') !== $date) {
                return 'Ungültiges Archiv-Startdatum. Bitte JJJJ-MM-TT eingeben.';
            }
            $start = $dt->getTimestamp();
        } else {
            $start = $end - max(1, $this->ReadPropertyInteger('LookbackHours')) * 3600;
        }
        if ($start >= $end) {
            return 'Startdatum liegt nach dem Prüfende.';
        }
        $lines = ['Archiv-Watchdog ' . date('d.m.Y H:i:s') . ' (' . ($delete ? 'BESTÄTIGTE BEREINIGUNG' : 'VORSCHAU') . ')',
            'Prüfbereich: ' . date('d.m.Y H:i', $start) . ' bis ' . date('d.m.Y H:i', $end) . '.',
            $delete ? 'Bestätigte Bereinigung: Nur anhand des Messverlaufs erkannte Ausreißer werden gelöscht; der Zeitabstand allein sperrt Messwerte nicht.'
                : 'Vorschau: keine Löschung, keine Reaggregation.'];
        $totalFound = 0;
        $totalDeleted = 0;
        $totalReview = 0;
        $totalSkipped = 0;
        $diagnosis = [];
        foreach ($this->targets() as $t) {
            if (isset($t['Enabled']) && !$t['Enabled']) {
                continue;
            }
            $id = (int) ($t['VariableID'] ?? 0);
            if ($id <= 0 || !IPS_VariableExists($id)) {
                continue;
            }
            $type = (int) IPS_GetVariable($id)['VariableType'];
            if ($type !== 1 && $type !== 2) {
                continue;
            }
            $profile = $profiles[(string) $id] ?? [];
            if ($profile && (int) ($profile['mode'] ?? -1) !== (int) ($t['Mode'] ?? 0)) {
                $lines[] = IPS_GetName($id) . ': Lernprofil passt nicht zum Verlauf; manuelle Fallback-Grenzen verwendet.';
                $profile = [];
            }
            if (!$profile) {
                $lines[] = IPS_GetName($id) . ': kein gültiges Lernprofil; Prüfung mit gespeicherten Fallback-Grenzen.';
            }
            $t = $this->effectiveTarget($t, $profile);
            $gap = $this->allowedGap($t, $profile);
            $overlap = (int) ($t['Mode'] ?? 0) === 0
                ? max(172800, $gap * (max(1, min(20, $this->ReadPropertyInteger('MaxErrorSeries'))) + 2))
                : max(259200, $gap * (max(1, min(20, $this->ReadPropertyInteger('MaxErrorSeries'))) + 2));
            $bad = [];
            $incomplete = false;
            $note = '';
            for ($from = $start; $from < $end; $from += 86400) {
                $to = min($end, $from + 86400);
                try {
                    $rows = AC_GetLoggedValues($archive, $id, max(0, $from - $overlap), $to + $overlap, 10000);
                } catch (Throwable $e) {
                    $incomplete = true;
                    $note = 'Archivfehler: ' . $e->getMessage();
                    break;
                }
                if (count($rows) >= 10000) {
                    $incomplete = true;
                    $note = 'Mindestens 10000 Datenpunkte in einem Prüfblock (keine vollständige Analyse).';
                    break;
                }
                usort($rows, static fn($a, $b) => (int) $a['TimeStamp'] <=> (int) $b['TimeStamp']);
                $detected = $this->detect($rows, $t, $profile, $from, $to);
                foreach ($detected as $key => $item) {
                    $bad[$key] = $item;
                }
            }
            // Fail closed: no partial delete if ANY data block was incomplete.
            if ($incomplete) {
                $lines[] = IPS_GetName($id) . ' (' . $id . '): ' . $note . ' Keine Löschung.';
                continue;
            }
            ksort($bad, SORT_NUMERIC);
            $count = count($bad);
            $totalFound += $count;
            $reviewCount = count(array_filter($bad, static fn($item) => !empty($item['ReviewOnly'])));
            $totalReview += $reviewCount;
            $lines[] = IPS_GetName($id) . ' (' . $id . '): ' . $count . ' erkannte Ausreißer; ' . ((int)($t['Mode'] ?? 0) === 0 ? 'keine harte Zeitgrenze für Messwerte.' : 'Zähler-Maximalabstand ' . round($gap / 60, 1) . ' min.');
            foreach ($bad as $item) {
                if (count($diagnosis) < 300) {
                    $diagnosis[] = $this->detailRow($id, $item,
                        !empty($item['ReviewOnly'])
                            ? ($delete && $includeReview ? 'FREIGEGEBEN: Prüfung nötig; Löschung folgt' : 'ZÄHLER: manuelle Freigabe nötig')
                            : ($delete ? 'ERKANNT – Löschung folgt' : 'ERKANNT (Vorschau)'));
                }
            }
            $diagIndex = [];
            foreach ($diagnosis as $idx => $dr) {
                if ($dr['Variable'] === IPS_GetName($id) . ' (' . $id . ')') {
                    $diagIndex[$dr['Date']] = $idx;
                }
            }
            $shown = 0;
            foreach ($bad as $item) {
                if ($shown++ >= 20) {
                    $lines[] = '  ... weitere ' . ($count - 20) . ' Werte (Details im Debug).';
                    break;
                }
                $lines[] = '  ' . date('d.m.Y H:i:s', $item['TimeStamp']) . ': ' . $item['Value']
                    . ' (erwartet ca. ' . round($item['Expected'], 3) . ', Schwelle ' . round($item['Threshold'], 3) . ')' . (isset($item['Reason']) ? ' [Störphase, manuelle Prüfung]' : '');
            }
            if ($delete && $bad) {
                $deleted = 0;
                $successfulCalls = 0;
                $failed = 0;
                $notFound = 0;
                $remaining = 0;
                foreach ($bad as $item) {
                    if (!empty($item['ReviewOnly']) && !$includeReview) {
                        $totalSkipped++;
                        $di = $diagIndex[date('d.m.Y H:i:s', (int)$item['TimeStamp'])] ?? null;
                        if ($di !== null) { $diagnosis[$di]['Status'] = 'NICHT GELÖSCHT: manuelle Freigabe erforderlich'; }
                        continue;
                    }
                    $ts = (int) $item['TimeStamp'];
                    try {
                        // API returns an INTEGER count, not a boolean success status.
                        $removed = AC_DeleteVariableData($archive, $id, $ts, $ts);
                        $di = $diagIndex[date('d.m.Y H:i:s', $ts)] ?? null;
                        if (!is_int($removed) || $removed < 0) {
                            $failed++;
                            if ($di !== null) { $diagnosis[$di]['Status'] = 'FEHLER: ungültiger Lösch-Rückgabewert'; }
                            $this->SendDebug('Delete', 'Unerwarteter Rückgabewert für ' . $id . '/' . $ts . ': ' . var_export($removed, true), 0);
                            continue;
                        }
                        if ($removed === 0) {
                            if ($di !== null) { $diagnosis[$di]['Status'] = 'NICHT GELÖSCHT: API meldet 0'; }
                            $notFound++;
                            continue;
                        }
                        $deleted += $removed;
                        $successfulCalls++;
                        if ($di !== null) { $diagnosis[$di]['Status'] = 'GELÖSCHT (Prüfung läuft)'; }
                        // Verify the raw record no longer exists; reaggregation cannot delete raw values.
                        $check = AC_GetLoggedValues($archive, $id, max(1, $ts - 1), $ts + 1, 100);
                        foreach ($check as $row) {
                            if ((int) ($row['TimeStamp'] ?? -1) === $ts) {
                                $remaining++;
                                if ($di !== null) { $diagnosis[$di]['Status'] = 'WARNUNG: Rohwert noch vorhanden'; }
                                $this->SendDebug('DeleteVerify', 'Wert verbleibt bei ' . $id . '/' . $ts, 0);
                                break;
                            }
                        }
                    } catch (Throwable $e) {
                        $failed++;
                        $di = $diagIndex[date('d.m.Y H:i:s', $ts)] ?? null;
                        if ($di !== null) { $diagnosis[$di]['Status'] = 'FEHLER: ' . $e->getMessage(); }
                        $this->SendDebug('Delete', 'Fehler bei ' . $id . ' / ' . $ts . ': ' . $e->getMessage(), 0);
                    }
                }
                $totalDeleted += $deleted;
                $lines[] = '  Messwerte: keine zeitbedingte Löschsperre; Zähler: Freigabe bei großen Zeitlücken.';
                $lines[] = '  Tatsächlich gelöschte Datensätze (API): ' . $deleted
                    . '; Löschaufrufe mit >0: ' . $successfulCalls
                    . '; keine Datensätze gefunden: ' . $notFound
                    . '; Fehler: ' . $failed . '.';
                if ($remaining > 0) {
                    $lines[] = '  WARNUNG: Bei ' . $remaining . ' Löschaufrufen sind weiterhin Rohwerte am Zeitstempel auffindbar!';
                }
                // One reaggregation request per variable, and ONLY when records were actually deleted.
                if ($deleted > 0) {
                    try {
                        if (AC_ReAggregateVariable($archive, $id)) {
                            $lines[] = '  Reaggregation ANGEFORDERT (Abschluss asynchron; im IP-Symcon-Meldungsfenster kontrollieren).';
                            $this->SendDebug('ReAggregate', 'Variable ' . $id . ': gestartet nach ' . $deleted . ' gelöschten Datensätzen.', 0);
                        } else {
                            $lines[] = '  WARNUNG: Reaggregation konnte nicht gestartet werden!';
                            $this->SendDebug('ReAggregate', 'Variable ' . $id . ': Rückgabewert false.', 0);
                        }
                    } catch (Throwable $e) {
                        $lines[] = '  WARNUNG: Fehler beim Start der Reaggregation: ' . $e->getMessage();
                        $this->SendDebug('ReAggregate', 'Variable ' . $id . ': ' . $e->getMessage(), 0);
                    }
                } else {
                    $lines[] = '  Keine Reaggregation: Es wurden keine Datensätze gelöscht.';
                }
            }
        }
        $lines[] = 'Gesamt erkannt: ' . $totalFound . '.';
        if ($delete) {
            $lines[] = 'Tatsächlich gelöschte Datensätze (Archiv-API): ' . $totalDeleted
                . '; mangels Freigabe übersprungen: ' . $totalSkipped . '.';
        }
        $lines[] = 'Dauerhafte Zähler-Resets/Offsets werden nicht automatisch bereinigt. Zählerprüfung verwendet erlernte Änderung pro Stunde, sofern vorhanden.';
        $report = implode("\n", $lines);
        $this->saveReport($report, $diagnosis);
        return $report;
    }
}
