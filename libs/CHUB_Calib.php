<?php

declare(strict_types=1);

/**
 * Reine Hilfsfunktionen für die Messung der Ladegeschwindigkeit (CHUB_MeasureChargeCurve) und die Ladefenster
 * (CHUB_SetPlanWindows). Ohne Symcon-Aufrufe, damit der Prüfstand sie einzeln prüfen kann.
 */
final class CHUB_Calib
{
    // Stromstufen (A) der Messung. Immer von der kleinsten bis zur größten erlaubten Stufe, nie darüber.
    private const LADDER = [6, 8, 10, 13, 16, 20, 25, 32, 40, 48, 63];

    public static function steps(int $min, int $max): array
    {
        $min = max(6, $min);
        if ($max < $min) {
            return [];
        }
        $out = [$min];
        foreach (self::LADDER as $a) {
            if ($a > $min && $a <= $max) {
                $out[] = $a;
            }
        }
        if (end($out) !== $max) {
            $out[] = $max;
        }
        return array_values(array_unique($out));
    }

    public static function median(array $v): float
    {
        $v = array_values(array_map('floatval', $v));
        if ($v === []) {
            return 0.0;
        }
        sort($v);
        $n = count($v);
        return ($n % 2 === 1) ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2.0;
    }

    /**
     * Stufe erreicht das Limit des Autos: Der Strom stieg um mindestens 20 %, die Leistung aber um weniger als 5 %.
     */
    public static function plateau(float $prevW, float $curW, int $prevA, int $curA): bool
    {
        if ($prevA <= 0 || $curA <= $prevA || $prevW <= 0.0) {
            return false;
        }
        if (($curA - $prevA) / $prevA < 0.20) {
            return false;
        }
        return (($curW - $prevW) / $prevW) < 0.05;
    }

    /**
     * Phasenzahl aus der gemessenen Leistung (Näherung bei 230 V): 1, 2 oder 3, sonst 0 = unbekannt.
     * Nur eine Schätzung aus der Messung, kein Wert, den das Gerät selbst meldet.
     */
    public static function estimatePhases(float $watts, int $amps): int
    {
        if ($watts <= 0.0 || $amps <= 0) {
            return 0;
        }
        $ratio = $watts / ($amps * 230.0);
        if ($ratio >= 0.75 && $ratio <= 1.25) {
            return 1;
        }
        if ($ratio >= 1.6 && $ratio <= 2.3) {
            return 2;
        }
        if ($ratio >= 2.5 && $ratio <= 3.4) {
            return 3;
        }
        return 0;
    }

    /**
     * Ladefenster prüfen und bereinigen: Liste von ['from' => Unixzeit, 'to' => Unixzeit] (optional 'powerW').
     * Akzeptiert ein Array oder einen JSON-String. Ungültige Einträge entfallen, Fenster in der Vergangenheit
     * ebenfalls, höchstens 96, aufsteigend sortiert.
     */
    public static function normalizeWindows($windows, int $now): array
    {
        if (is_string($windows)) {
            $windows = json_decode($windows, true);
        }
        if (!is_array($windows)) {
            return [];
        }
        $out = [];
        foreach ($windows as $w) {
            if (!is_array($w) || !isset($w['from'], $w['to'])) {
                continue;
            }
            $from = (int)$w['from'];
            $to = (int)$w['to'];
            if ($from <= 0 || $to <= $from || $to <= $now || $to - $from > 86400) {
                continue;
            }
            $e = ['from' => $from, 'to' => $to];
            if (isset($w['powerW']) && is_numeric($w['powerW']) && (float)$w['powerW'] > 0) {
                $e['powerW'] = (int)round((float)$w['powerW']);
            }
            $out[] = $e;
        }
        usort($out, fn ($a, $b) => $a['from'] <=> $b['from']);
        return array_slice($out, 0, 96);
    }
}
