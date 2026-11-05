<?php
/**
 * Read-only trip and charge analytics. Pure PHP so it can be tested without IP-Symcon.
 * Stored timestamps are Unix seconds; distances km; energy kWh; powers kW.
 */
declare(strict_types=1);

final class RenaultEVAnalytics
{
    public static function emptyState(): array
    {
        return [
            'lastOdo' => null, 'lastSoc' => null, 'trip' => null, 'trips' => [],
            'charge' => null, 'charges' => [], 'lastCharging' => false,
            'lastChargePowerKW' => 0.0, 'lastChargeSample' => 0,
            'lastTotalCounter' => null, 'lastSessionCounter' => null,
        ];
    }

    public static function normalize(array $input): array
    {
        return array_replace(self::emptyState(), $input);
    }

    public static function updateTrip(array $s, ?float $odometer, ?float $soc, int $now, int $idleMinutes, float $capacity): array
    {
        $s = self::normalize($s);
        if ($odometer === null || $odometer < 0) return $s;
        $previous = $s['lastOdo'];
        if ($previous === null) {
            $s['lastOdo'] = $odometer;
            $s['lastSoc'] = $soc;
            return $s;
        }
        $delta = $odometer - (float) $previous;
        // Reject implausible/reset samples rather than inventing journeys.
        if ($delta < -0.05 || $delta > 400.0) {
            $s['lastOdo'] = $odometer;
            $s['lastSoc'] = $soc;
            $s['trip'] = null;
            return $s;
        }
        if ($delta > 0.099) {
            if (!is_array($s['trip'])) {
                $s['trip'] = [
                    'startTime' => $now, 'lastMovingTime' => $now,
                    'startOdo' => (float) $previous, 'endOdo' => $odometer,
                    'startSoc' => $s['lastSoc'], 'endSoc' => $soc,
                    'km' => $delta,
                ];
            } else {
                $s['trip']['endOdo'] = $odometer;
                $s['trip']['endSoc'] = $soc;
                $s['trip']['km'] = max(0.0, $odometer - (float) $s['trip']['startOdo']);
                $s['trip']['lastMovingTime'] = $now;
            }
        } elseif (is_array($s['trip']) && ($now - (int) $s['trip']['lastMovingTime']) >= max(1, $idleMinutes) * 60) {
            $s = self::finishTrip($s, (int) $s['trip']['lastMovingTime'], $capacity);
        }
        $s['lastOdo'] = $odometer;
        $s['lastSoc'] = $soc;
        return $s;
    }

    public static function finishTrip(array $s, int $now, float $capacity): array
    {
        $s = self::normalize($s);
        if (!is_array($s['trip'])) return $s;
        $t = $s['trip'];
        $t['endTime'] = $now;
        unset($t['lastMovingTime']);
        $t['km'] = round((float) $t['km'], 2);
        $usedPct = (is_numeric($t['startSoc']) && is_numeric($t['endSoc']))
            ? max(0.0, (float) $t['startSoc'] - (float) $t['endSoc']) : null;
        $t['kwhEstimated'] = $usedPct === null ? null : round($capacity * $usedPct / 100.0, 3);
        $t['kwh100Estimated'] = ($t['kwhEstimated'] !== null && $t['km'] > 0)
            ? round(100.0 * $t['kwhEstimated'] / $t['km'], 2) : null;
        if ($t['km'] >= 0.1) {
            $s['trips'][] = $t;
            $s['trips'] = array_slice($s['trips'], -500);
        }
        $s['trip'] = null;
        return $s;
    }

    /**
     * Read-only go-e charging session detection.
     * Total counter preferred, session counter secondary, power integration fallback.
     * Optional counters must be in kWh. Boolean state must already be normalized.
     */
    public static function updateCharge(
        array $s, bool $charging, float $powerKW, ?float $totalKwh,
        ?float $sessionKwh, ?float $soc, int $now, float $price
    ): array {
        $s = self::normalize($s);
        $prev = (bool) $s['lastCharging'];
        if ($charging && !$prev) {
            $s['charge'] = [
                'startTime' => $now, 'startSoc' => $soc, 'endSoc' => $soc,
                'kwh' => 0.0, 'measurement' => 'power estimate',
            ];
        }
        if ($charging && is_array($s['charge']) && $prev) {
            $delta = null;
            if ($totalKwh !== null && $s['lastTotalCounter'] !== null) {
                $d = $totalKwh - (float) $s['lastTotalCounter'];
                if ($d >= 0 && $d <= 25) {
                    $delta = $d;
                    $s['charge']['measurement'] = 'total counter';
                }
            }
            if ($delta === null && $sessionKwh !== null && $s['lastSessionCounter'] !== null) {
                $d = $sessionKwh - (float) $s['lastSessionCounter'];
                if ($d >= 0 && $d <= 25) {
                    $delta = $d;
                    $s['charge']['measurement'] = 'session counter';
                }
            }
            if ($delta === null) {
                $dt = max(0, min(900, $now - (int) $s['lastChargeSample']));
                $delta = max(0.0, min(22.0, (float) $s['lastChargePowerKW'])) * $dt / 3600.0;
                $s['charge']['measurement'] = 'power estimate';
            }
            $s['charge']['kwh'] = round((float) $s['charge']['kwh'] + $delta, 5);
            $s['charge']['endSoc'] = $soc;
        }
        if (!$charging && $prev && is_array($s['charge'])) {
            $record = $s['charge'];
            $record['endTime'] = $now;
            $record['endSoc'] = $soc;
            $record['kwh'] = round((float) $record['kwh'], 3);
            $record['costEUR'] = round($record['kwh'] * $price, 2);
            $record['durationMinutes'] = round(max(0, $now - $record['startTime']) / 60.0, 1);
            if ($record['kwh'] >= 0.01) {
                $s['charges'][] = $record;
                $s['charges'] = array_slice($s['charges'], -500);
            }
            $s['charge'] = null;
        }
        $s['lastCharging'] = $charging;
        $s['lastChargeSample'] = $now;
        $s['lastChargePowerKW'] = max(0.0, $powerKW);
        $s['lastTotalCounter'] = $totalKwh;
        $s['lastSessionCounter'] = $sessionKwh;
        return $s;
    }

    public static function summary(array $s, int $now): array
    {
        $s = self::normalize($s);
        $day = date('Y-m-d', $now);
        $month = date('Y-m', $now);
        $kmToday = 0.0; $kmMonth = 0.0; $kmTotal = 0.0; $tripEnergy = 0.0;
        foreach ($s['trips'] as $t) {
            $km = (float) ($t['km'] ?? 0);
            $kmTotal += $km;
            $tripEnergy += (float) ($t['kwhEstimated'] ?? 0);
            $date = date('Y-m-d', (int) ($t['endTime'] ?? 0));
            if ($date === $day) $kmToday += $km;
            if (substr($date, 0, 7) === $month) $kmMonth += $km;
        }
        // Laufende Fahrten sind noch nicht im Archiv, sollen aber in den
        // aktuellen Summen sichtbar sein. Nach Fahrtende erfolgt die
        // Aufnahme ins Archiv; dadurch entsteht keine Doppelzählung.
        if (is_array($s['trip'])) {
            $activeKm = max(0.0, (float) ($s['trip']['km'] ?? 0.0));
            $start = (int) ($s['trip']['startTime'] ?? $now);
            $kmTotal += $activeKm;
            if (date('Y-m-d', $start) === $day) $kmToday += $activeKm;
            if (date('Y-m', $start) === $month) $kmMonth += $activeKm;
            // Ohne Abschluss-SoC keine belastbare Verbrauchsberechnung.
        }
        $kwhTotal = 0.0; $costTotal = 0.0; $kwhMonth = 0.0;
        foreach ($s['charges'] as $c) {
            $kwh = (float) ($c['kwh'] ?? 0);
            $kwhTotal += $kwh;
            $costTotal += (float) ($c['costEUR'] ?? 0);
            if (date('Y-m', (int) ($c['endTime'] ?? 0)) === $month) $kwhMonth += $kwh;
        }
        $lastTrip = end($s['trips']); $lastCharge = end($s['charges']);
        return [
            'kmToday' => round($kmToday, 2), 'kmMonth' => round($kmMonth, 2),
            'kmRecorded' => round($kmTotal, 2), 'tripCount' => count($s['trips']),
            'tripKwh100Estimated' => $kmTotal > 0 ? round($tripEnergy / $kmTotal * 100, 2) : 0.0,
            'chargeCount' => count($s['charges']), 'chargedTotalKwh' => round($kwhTotal, 3),
            'chargedMonthKwh' => round($kwhMonth, 3), 'chargeCostTotalEUR' => round($costTotal, 2),
            'lastTripKM' => (float) ($lastTrip['km'] ?? 0),
            'lastChargeKwh' => (float) ($lastCharge['kwh'] ?? 0),
            'currentTripKM' => (float) ($s['trip']['km'] ?? 0),
            'currentChargeKwh' => (float) ($s['charge']['kwh'] ?? 0),
        ];
    }
}
