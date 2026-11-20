<?php
/** ArchiveWatchdog v0.3.02 (Build 5). Archive analysis only; never overwrites live variable values. */
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
        return file_get_contents(__DIR__ . '/form.json');
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
        $gap = max(1, (int) ($target['MaxGapMinutes'] ?? 30)) * 60;
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
                    if ($currTs <= $prevTs || $currTs - $prevTs > $gap) {
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
                    $candidate[] = ['TimeStamp' => $ts, 'Value' => $v, 'Expected' => $expected, 'Threshold' => $threshold];
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
            $gap = max(1, (int) ($t['MaxGapMinutes'] ?? 30)) * 60;
            $overlap = $gap * (max(1, min(20, $this->ReadPropertyInteger('MaxErrorSeries'))) + 2);
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
            $lines[] = IPS_GetName($id) . ' (' . $id . '): ' . $count . ' erkannte Ausreißer (auch kurze Fehlerfolgen).';
            $shown = 0;
            foreach ($bad as $item) {
                if ($shown++ >= 20) {
                    $lines[] = '  ... weitere ' . ($count - 20) . ' Werte (Details im Debug).';
                    break;
                }
                $lines[] = '  ' . date('d.m.Y H:i:s', $item['TimeStamp']) . ': ' . $item['Value']
                    . ' (erwartet ca. ' . round($item['Expected'], 3) . ', Schwelle ' . round($item['Threshold'], 3) . ')';
            }
            if ($delete && $bad) {
                $deleted = 0;
                $successfulCalls = 0;
                $failed = 0;
                $notFound = 0;
                $remaining = 0;
                foreach ($bad as $item) {
                    $ts = (int) $item['TimeStamp'];
                    try {
                        // API returns an INTEGER count, not a boolean success status.
                        $removed = AC_DeleteVariableData($archive, $id, $ts, $ts);
                        if (!is_int($removed) || $removed < 0) {
                            $failed++;
                            $this->SendDebug('Delete', 'Unerwarteter Rückgabewert für ' . $id . '/' . $ts . ': ' . var_export($removed, true), 0);
                            continue;
                        }
                        if ($removed === 0) {
                            $notFound++;
                            continue;
                        }
                        $deleted += $removed;
                        $successfulCalls++;
                        // Verify the raw record no longer exists; reaggregation cannot delete raw values.
                        $check = AC_GetLoggedValues($archive, $id, max(1, $ts - 1), $ts + 1, 100);
                        foreach ($check as $row) {
                            if ((int) ($row['TimeStamp'] ?? -1) === $ts) {
                                $remaining++;
                                $this->SendDebug('DeleteVerify', 'Wert verbleibt bei ' . $id . '/' . $ts, 0);
                                break;
                            }
                        }
                    } catch (Throwable $e) {
                        $failed++;
                        $this->SendDebug('Delete', 'Fehler bei ' . $id . ' / ' . $ts . ': ' . $e->getMessage(), 0);
                    }
                }
                $totalDeleted += $deleted;
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
        $this->SendDebug('Scan', $report, 0);
        return $report;
    }
}
