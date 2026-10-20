<?php

/**
 * Whether a month's care management was billed as the time logged supports: the codes met
 * (MonthRules' routes, which are alternatives: one route is billed) against the codes on the
 * patient's fee sheets for encounters in that month.
 *
 *  - billed:          what was billed is within one route's codes and units;
 *  - billed_beyond:   billed more codes or units than any route met, or codes no route met;
 *  - billed_blocked:  billed, though something blocks billing the month (consent, conditions...);
 *  - not_billed:      the month is over, it met codes with nothing blocking, and nothing was billed;
 *  - not_billed_yet:  the same while the month is still running;
 *  - nothing:         nothing met and nothing billed.
 *
 * It only compares; it bills nothing and changes nothing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Domain;

final class BillingCheck
{
    public const BILLED = 'billed';
    public const BEYOND = 'billed_beyond';
    public const BLOCKED = 'billed_blocked';
    public const NOT_BILLED = 'not_billed';
    public const NOT_BILLED_YET = 'not_billed_yet';
    public const NOTHING = 'nothing';

    /**
     * @param array<string, list<array{code: string, units: int}>> $routes the codes met, by route (MonthRules::summarize)
     * @param list<string> $blockers what blocks billing the month
     * @param array<string, int> $billed code => units billed for this service in the month
     * @param bool $monthOver the month has ended
     * @return array{status: string, beyond: array<string, int>} beyond: the codes and units billed past the closest route
     */
    public static function check(array $routes, array $blockers, array $billed, bool $monthOver): array
    {
        $billed = array_filter($billed, static fn(int $units): bool => $units > 0);
        $met = array_filter($routes);
        if ($billed === []) {
            if ($met === [] || $blockers !== []) {
                return ['status' => self::NOTHING, 'beyond' => []];
            }
            return ['status' => $monthOver ? self::NOT_BILLED : self::NOT_BILLED_YET, 'beyond' => []];
        }
        // The route that covers the most of what was billed; what it doesn't cover is beyond.
        $best = null;
        foreach ($met as $codes) {
            $allowed = [];
            foreach ($codes as $c) {
                $allowed[$c['code']] = ($allowed[$c['code']] ?? 0) + $c['units'];
            }
            $over = [];
            foreach ($billed as $code => $units) {
                $extra = $units - ($allowed[$code] ?? 0);
                if ($extra > 0) {
                    $over[$code] = $extra;
                }
            }
            if ($best === null || array_sum($over) < array_sum($best)) {
                $best = $over;
            }
        }
        $beyond = $best ?? $billed;
        if ($beyond !== []) {
            ksort($beyond);
            return ['status' => self::BEYOND, 'beyond' => $beyond];
        }
        return ['status' => $blockers !== [] ? self::BLOCKED : self::BILLED, 'beyond' => []];
    }

    /**
     * Of a patient's billed codes, those that belong to a service, in the service's own order (a base
     * code before its add-on), as the codes met are listed.
     *
     * @param array<string, int> $billed code => units
     * @return array<string, int>
     */
    public static function forService(array $billed, string $service): array
    {
        $out = [];
        foreach (Service::codes($service) as $code) {
            if (isset($billed[$code])) {
                $out[$code] = $billed[$code];
            }
        }
        return $out;
    }
}
