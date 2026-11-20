<?php
/** ArchiveWatchdog v0.3.05 (Build 8). Archive analysis only; never overwrites live variable values. */
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
        $gap = $this->allowedGap($target, $profile);
        $pad = (int) ($target['Mode'] ?? 0) === 0 ? max(172800, ($this->ReadPropertyInteger('MaxErrorSeries') + 2) * $gap) : max(7200, ($this->ReadPropertyInteger('MaxErrorSeries') + 2) * $gap);
        try {
            $rows = AC_GetLoggedValues($archive, $id, max(1, $start - $pad), $end + $pad, 10000);
        } catch (Throwable $e) {
            return 'Archiv-Abfrage fehlgeschlagen: ' . $e->getMessage();
        }
        if (count($rows) >= 10000) { return 'Diagnose abgebrochen: Mehr als 9999 Werte im Abfragefenster. Kein vollständiger Datensatz.'; }
        usort($rows, static fn($a, $b) => (int) $a['TimeStamp'] <=> (int) $b['TimeStamp']);
        $detected = $this->detect($rows, $target, $profile, $start, $end);
        $lines[] = $mode === 0
            ? 'Messwert: keine harte Zeitgrenze für die Erkennung. Ab 120 min Abstand je Seite ausschließlich manuelle Prüfung (keine Löschung).'
            : 'Zähler: maximaler Zeitabstand ' . round($gap / 60, 1) . ' min.';
        $lines[] = 'Rohdaten inkl. Nachbarschaft: ' . count($rows) . '; erkannte Ausreißer an diesem Tag: ' . count($detected) . '.';
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
                $status = !empty($detected[(string)$ts]['ReviewOnly']) ? 'ERKANNT – NUR VORSCHAU (große Datenlücke)' : 'ERKANNT (löschbar nach Bestätigung)';
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
            sort($intervals, SORT_NUMERIC);
            $p = [
                'mode' => $mode,
                'q95' => $this->percentile($steps, 0.95),
                'q99' => $this->percentile($steps, 0.99),
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
        $factor = max(1.0, min(20.0, $this->ReadPropertyFloat('SafetyFactor')));
        return max($manual, (float) ($profile['q95'] ?? 0) * $factor * $scale);
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
                    if ($currTs <= $prevTs || ($mode !== 0 && $currTs - $prevTs > $gap)) {
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
                    // Long gaps are still detected for diagnosis, but never deleted automatically.
                    // This is a safety classification, not an exclusion from recognition.
                    $longGap = $mode === 0 && ($ts - $aTs > 7200 || $zTs - $ts > 7200);
                    $candidate[] = ['TimeStamp' => $ts, 'Value' => $v, 'Expected' => $expected, 'Threshold' => $threshold, 'ReviewOnly' => $longGap];
                }
                if ($candidate) {
                    if ($mode === 0) {
                        $prevCandidateTs = $aTs;
                        $review = false;
                        for ($k = $i; $k <= $i + $length; $k++) {
                            $candidateTs = (int) $rows[$k]['TimeStamp'];
                            if ($candidateTs - $prevCandidateTs > 7200) { $review = true; }
                            $prevCandidateTs = $candidateTs;
                        }
                        if ($review) {
                            foreach ($candidate as &$candidateItem) { $candidateItem['ReviewOnly'] = true; }
                            unset($candidateItem);
                        }
                    }
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
        return $bad;
    }

    /** Scan normal range; or entire archive since configured ArchiveStartDate (in daily chunks). */
    public function Scan(bool $delete = false, bool $wholeArchive = false): string
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
        $lines = ['Archiv-Watchdog ' . date('d.m.Y H:i:s') . ' (' . ($delete ? 'LÖSCHMODUS' : 'VORSCHAU') . ')',
            'Prüfbereich: ' . date('d.m.Y H:i', $start) . ' bis ' . date('d.m.Y H:i', $end) . '.'];
        $totalFound = 0;
        $totalDeleted = 0;
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
            if (!$profile || (int) ($profile['mode'] ?? -1) !== (int) ($t['Mode'] ?? 0)) {
                $lines[] = IPS_GetName($id) . ': kein passendes Lernprofil – übersprungen.';
                continue;
            }
            $gap = $this->allowedGap($t, $profile);
            $overlap = (int) ($t['Mode'] ?? 0) === 0
                ? max(172800, $gap * (max(1, min(20, $this->ReadPropertyInteger('MaxErrorSeries'))) + 2))
                : $gap * (max(1, min(20, $this->ReadPropertyInteger('MaxErrorSeries'))) + 2);
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
            $lines[] = IPS_GetName($id) . ' (' . $id . '): ' . $count . ' erkannte Ausreißer (davon ' . $reviewCount . ' mit großer Datenlücke nur zur manuellen Prüfung); ' . ((int)($t['Mode'] ?? 0) === 0 ? 'keine harte Zeitgrenze für Messwerte.' : 'Zähler-Maximalabstand ' . round($gap / 60, 1) . ' min.');
            foreach ($bad as $item) {
                if (count($diagnosis) < 300) {
                    $diagnosis[] = $this->detailRow($id, $item, !empty($item['ReviewOnly']) ? 'NUR VORSCHAU: große Datenlücke' : ($delete ? 'ERKANNT – Löschung folgt' : 'ERKANNT (Vorschau)'));
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
                    . ' (erwartet ca. ' . round($item['Expected'], 3) . ', Schwelle ' . round($item['Threshold'], 3) . ')' . (!empty($item['ReviewOnly']) ? ' [NUR VORSCHAU: große Datenlücke]' : '');
            }
            if ($delete && $bad) {
                $deleted = 0;
                $successfulCalls = 0;
                $failed = 0;
                $notFound = 0;
                $remaining = 0;
                foreach ($bad as $item) {
                    if (!empty($item['ReviewOnly'])) { continue; }
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
                $lines[] = '  Nur zur manuellen Prüfung (nicht gelöscht): ' . $reviewCount . '.';
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
        $lines[] = 'Gesamt erkannt: ' . $totalFound . ($delete ? ', tatsächlich gelöschte Datensätze: ' . $totalDeleted : '') . '.';
        $lines[] = 'Dauerhafte Zähler-Resets/Offsets werden nicht automatisch bereinigt.';
        $report = implode("\n", $lines);
        $this->saveReport($report, $diagnosis);
        return $report;
    }
}
