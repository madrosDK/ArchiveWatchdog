<?php
/** ArchiveWatchdog v0.2: conservative archival outlier screening; never changes a live variable. */
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
        $decoded = json_decode($this->ReadPropertyString('Targets'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function profiles(): array
    {
        $decoded = json_decode($this->ReadAttributeString('LearnedProfiles'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if (!$n) {
            return 0.0;
        }
        $pos = ($n - 1) * $p;
        $lo = (int) floor($pos);
        $hi = min($lo + 1, $n - 1);
        return (float) ($sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($pos - $lo));
    }

    /** Learn only on explicit button click. Profiles persist across IP-Symcon restarts. */
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
            try {
                $rows = AC_GetLoggedValues($archive, $id, $start, $end, 10000);
            } catch (Throwable $e) {
                $lines[] = $id . ': Archivfehler: ' . $e->getMessage();
                continue;
            }
            // Avoid silently learning on a truncated period.
            if (count($rows) >= 10000) {
                $lines[] = $id . ': mehr als 9999 Werte, Lernzeitraum verkleinern.';
                continue;
            }
            usort($rows, static fn($a, $b) => (int)$a['TimeStamp'] <=> (int)$b['TimeStamp']);
            $steps = [];
            $intervals = [];
            $reversals = 0;
            $prev = null;
            foreach ($rows as $row) {
                $ts = (int) ($row['TimeStamp'] ?? 0);
                $v = (float) ($row['Value'] ?? NAN);
                if (!is_finite($v)) {
                    continue;
                }
                if ($prev !== null) {
                    $dt = $ts - $prev[0];
                    if ($dt > 0 && $dt <= $gap) {
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
            if (count($steps) < 30) {
                $lines[] = $id . ': zu wenig geeignete Werte (' . count($steps) . ' Schritte, min. 30).';
                continue;
            }
            sort($steps, SORT_NUMERIC);
            sort($intervals, SORT_NUMERIC);
            $profile = [
                'mode' => $mode,
                'q95' => $this->percentile($steps, 0.95),
                'q99' => $this->percentile($steps, 0.99),
                'medianInterval' => max(1.0, $this->percentile($intervals, 0.5)),
                'steps' => count($steps),
                'reversals' => $reversals,
                'learnedAt' => time(),
                'trainingDays' => $days
            ];
            $profiles[(string)$id] = $profile;
            $lines[] = IPS_GetName($id) . ' (' . $id . '): ' . count($steps) . ' Schritte, 95%-Sprung=' . round($profile['q95'], 4)
                . ', 99%-Sprung=' . round($profile['q99'], 4) . ', typische Zeit=' . round($profile['medianInterval'])
                . ' s, Richtungswechsel=' . $reversals . '.';
        }
        $this->WriteAttributeString('LearnedProfiles', json_encode($profiles));
        $report = implode("\n", $lines);
        $this->SendDebug('Learn', $report, 0);
        return $report;
    }

    private function threshold(array $target, array $profile, int $dt): float
    {
        $manual = max(0.001, (float) ($target['Spike'] ?? 3));
        // Robust 95th percentile: avoids letting a few historical faulty spikes inflate the threshold.
        $typical = max(1.0, (float) ($profile['medianInterval'] ?? $dt));
        $scale = min(4.0, max(1.0, sqrt($dt / $typical)));
        $factor = max(1.0, min(20.0, $this->ReadPropertyFloat('SafetyFactor')));
        return max($manual, (float) ($profile['q95'] ?? 0) * $factor * $scale);
    }

    /** Conservative: only an isolated value whose neighbors corroborate a return. */
    public function Scan(bool $delete = false): string
    {
        $archive = $this->archiveID();
        if (!$archive) {
            return 'Kein Archiv gefunden.';
        }
        $profiles = $this->profiles();
        $end = time() - max(1, $this->ReadPropertyInteger('MinAgeMinutes')) * 60;
        $start = $end - max(1, $this->ReadPropertyInteger('LookbackHours')) * 3600;
        $lines = ['Archiv-Watchdog ' . date('d.m.Y H:i:s') . ' (' . ($delete ? 'LÖSCHMODUS' : 'VORSCHAU') . ')'];
        $total = 0;
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
            $mode = (int) ($t['Mode'] ?? 0);
            $profile = $profiles[(string)$id] ?? [];
            if (!$profile || (int) ($profile['mode'] ?? -1) !== $mode) {
                $lines[] = IPS_GetName($id) . ': noch kein passendes Lernprofil – übersprungen (zuerst Lernen ausführen).';
                continue;
            }
            $gap = max(1, (int) ($t['MaxGapMinutes'] ?? 30)) * 60;
            $tolerance = max(0.0, (float) ($t['NeighborTolerance'] ?? 1));
            try {
                $rows = AC_GetLoggedValues($archive, $id, $start - $gap, $end + $gap, 10000);
            } catch (Throwable $e) {
                $lines[] = $id . ': Archivfehler: ' . $e->getMessage();
                continue;
            }
            if (count($rows) >= 10000) {
                $lines[] = $id . ': zu viele Archivwerte, Suchfenster verkleinern.';
                continue;
            }
            usort($rows, static fn($a, $b) => (int)$a['TimeStamp'] <=> (int)$b['TimeStamp']);
            $bad = [];
            for ($i = 1, $n = count($rows) - 1; $i < $n; $i++) {
                $a = $rows[$i - 1];
                $b = $rows[$i];
                $c = $rows[$i + 1];
                $ts = (int)$b['TimeStamp'];
                $d1 = $ts - (int)$a['TimeStamp'];
                $d2 = (int)$c['TimeStamp'] - $ts;
                if ($ts < $start || $ts > $end || $d1 <= 0 || $d2 <= 0 || $d1 > $gap || $d2 > $gap) {
                    continue;
                }
                $v1 = (float)$a['Value'];
                $v2 = (float)$b['Value'];
                $v3 = (float)$c['Value'];
                if (!is_finite($v1) || !is_finite($v2) || !is_finite($v3)) {
                    continue;
                }
                // The two surrounding values must be close, otherwise this may be a real trend or reset.
                $neighborAllowed = max($tolerance, $this->threshold($t, $profile, $d1 + $d2));
                if (abs($v3 - $v1) > $neighborAllowed) {
                    continue;
                }
                $limit = max($this->threshold($t, $profile, $d1), $this->threshold($t, $profile, $d2));
                $expected = $v1 + ($v3 - $v1) * $d1 / ($d1 + $d2);
                if (abs($v2 - $expected) <= $limit || abs($v2 - $v1) <= $limit || abs($v2 - $v3) <= $limit) {
                    continue;
                }
                // Counter: only delete clear temporary backwards/forwards excursions that immediately recover.
                if ($mode === 1 && !($v2 < min($v1, $v3) || $v2 > max($v1, $v3))) {
                    continue;
                }
                if ($mode === 2 && !($v2 > max($v1, $v3) || $v2 < min($v1, $v3))) {
                    continue;
                }
                $bad[] = ['TimeStamp' => $ts, 'Value' => $v2, 'Expected' => $expected, 'Threshold' => $limit];
            }
            $lines[] = IPS_GetName($id) . ' (' . $id . '): ' . count($bad) . ' eindeutige Einzel-Ausreißer.';
            foreach ($bad as $item) {
                $lines[] = '  ' . date('d.m.Y H:i:s', $item['TimeStamp']) . ': ' . $item['Value']
                    . ' (erwartet ca. ' . round($item['Expected'], 3) . ', Schwelle ' . round($item['Threshold'], 3) . ')';
            }
            if ($delete && $bad) {
                $removed = 0;
                foreach ($bad as $item) {
                    $ts = $item['TimeStamp'];
                    $removed += (int) AC_DeleteVariableData($archive, $id, $ts, $ts);
                }
                if ($removed > 0) {
                    AC_ReAggregateVariable($archive, $id);
                }
                $lines[] = '  Entfernte Archivdatensätze: ' . $removed;
            }
            $total += count($bad);
        }
        $lines[] = 'Gesamt: ' . $total . '. Anhaltende Sprünge/Resets werden absichtlich nicht automatisch gelöscht.';
        $report = implode("\n", $lines);
        $this->SendDebug('Scan', $report, 0);
        return $report;
    }
}
