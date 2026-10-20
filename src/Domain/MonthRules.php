<?php

/**
 * One enrollment's calendar month: the minutes logged, the codes they meet by each
 * route, and what blocks billing them.
 *
 *  - CCM: clinical staff time (with the practitioner's, when not used for 99491) meets
 *    99490 at 20 minutes and 99439 for each further full 20, at most twice; the
 *    practitioner's own time meets 99491 at 30 and 99437 for each further 30. The two
 *    routes can't both be billed in a month.
 *  - PCM: staff time meets 99426 at 30 and 99427 for each further 30; the practitioner's,
 *    99424 and 99425.
 *  - BHI: staff time meets 99484 at 20.
 *  - Complex CCM, in a month the billing practitioner says they made moderate or high
 *    complexity decisions: the same time as 99490 meets 99487 at 60 and 99489 for each further
 *    full 30. It's a route of its own, billed instead of the others.
 *  - CoCM: the care manager's time meets 99492 at 70 in the first month of an episode, 99493
 *    at 60 in a later one, 99494 for each further full 30, or G2214 at 30 when neither is met.
 *  - APCM: its code each month, with no time; for a CoCM or BHI enrollment with time logged
 *    and the same practitioner billing APCM, the APCM add-on (G0568, G0569 or G0570).
 *
 * Blocked: consent not recorded with all the things the patient must be told; too few
 * conditions; no initiating visit when one was needed; CCM and PCM by the same
 * practitioner in the same month; CoCM and general BHI for the same patient in a month;
 * APCM and CCM or PCM by the same practitioner in a month.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Domain;

final class MonthRules
{
    public const NO_CONSENT = 'no_consent';
    public const TOO_FEW_CONDITIONS = 'too_few_conditions';
    public const NO_INITIATING_VISIT = 'no_initiating_visit';
    public const CCM_AND_PCM = 'ccm_and_pcm';
    public const COCM_AND_BHI = 'cocm_and_bhi';
    public const APCM_AND_CCM_PCM = 'apcm_and_ccm_pcm';

    /**
     * @param list<array{enrollmentId: int, servedOn: \DateTimeImmutable, minutes: int, role: string}> $entries the patient's
     * @param list<Enrollment> $others the patient's other enrollments
     * @param bool $complexMdm the billing practitioner says they made moderate or high complexity decisions this month (CCM)
     * @param bool $cocmEarlierInEpisode the enrollment had CoCM time in the 6 calendar months before this one
     * @return array{staff: int, practitioner: int,
     *               routes: array<string, list<array{code: string, units: int}>>,
     *               next: array<string, int>, nextCode: array<string, string>, threshold: ?int, blockers: list<string>, capped: bool, stopsThisMonth: bool}
     *         routes: by Service::STAFF / PRACTITIONER / COMPLEX / APCM_CODE / ADD_ON, the codes met (empty when the threshold isn't);
     *         next: minutes still needed for each route's base code (0 when met), nextCode: that base code;
     *         threshold: the minutes the main route's base code needs, or null for a service with no time (APCM)
     */
    public static function summarize(Enrollment $e, array $entries, array $others, Month $month, bool $complexMdm = false, bool $cocmEarlierInEpisode = false): array
    {
        $minutes = [Service::STAFF => 0, Service::PRACTITIONER => 0];
        foreach ($entries as $entry) {
            if ($entry['enrollmentId'] === $e->id && $month->contains($entry['servedOn']) && isset($minutes[$entry['role']])) {
                $minutes[$entry['role']] += max(0, $entry['minutes']);
            }
        }

        $routes = [];
        $next = [];
        $nextCode = [];
        $capped = false;
        foreach (Service::routes($e->service) as $route => $r) {
            $time = 0;
            foreach ($r['counts'] as $role) {
                $time += $minutes[$role];
            }
            $codes = [];
            if ($time >= $r['first']) {
                $codes[] = ['code' => $r['base'], 'units' => 1];
                if ($r['addOn'] !== null && $r['each'] !== null) {
                    $units = intdiv($time - $r['first'], $r['each']);
                    if ($r['max'] !== null && $units > $r['max']) {
                        $units = $r['max'];
                        $capped = true;
                    }
                    if ($units > 0) {
                        $codes[] = ['code' => $r['addOn'], 'units' => $units];
                    }
                }
            }
            $routes[$route] = $codes;
            $next[$route] = max(0, $r['first'] - $time);
            $nextCode[$route] = $r['base'];
        }
        if ($e->service === Service::CCM && $complexMdm) {
            $c = Service::COMPLEX_CCM;
            $time = $minutes[Service::STAFF] + $minutes[Service::PRACTITIONER];
            $routes[Service::COMPLEX] = $time >= $c['first'] ? array_values(array_filter([
                ['code' => $c['base'], 'units' => 1],
                ['code' => $c['addOn'], 'units' => intdiv($time - $c['first'], $c['each'])],
            ], static fn(array $x): bool => $x['units'] > 0)) : [];
            $next[Service::COMPLEX] = max(0, $c['first'] - $time);
            $nextCode[Service::COMPLEX] = $c['base'];
        }
        if ($e->service === Service::COCM) {
            // The care manager's time; CMS doesn't say the practitioner's own counts.
            $time = $minutes[Service::STAFF];
            $base = $cocmEarlierInEpisode ? Service::COCM_LATER_MONTH : Service::COCM_FIRST_MONTH;
            $codes = [];
            if ($time >= $base['first']) {
                $codes[] = ['code' => $base['base'], 'units' => 1];
                $units = intdiv($time - $base['first'], Service::COCM_ADD_ON['each']);
                if ($units > 0) {
                    $codes[] = ['code' => Service::COCM_ADD_ON['code'], 'units' => $units];
                }
            } elseif ($time >= Service::COCM_SHORT['first']) {
                $codes[] = ['code' => Service::COCM_SHORT['code'], 'units' => 1];
            }
            $routes[Service::STAFF] = $codes;
            $next[Service::STAFF] = max(0, $base['first'] - $time);
            $nextCode[Service::STAFF] = $base['base'];
        }
        $apcmBySamePractitioner = false;
        foreach ($others as $o) {
            if ($o->id !== $e->id && $o->service === Service::APCM && $o->practitionerId === $e->practitionerId && $o->activeIn($month)) {
                $apcmBySamePractitioner = true;
            }
        }
        if ($e->service === Service::APCM) {
            $routes[Service::APCM_CODE] = [['code' => Service::apcmCode(count($e->conditions), $e->qmb), 'units' => 1]];
            $next[Service::APCM_CODE] = 0;
            $nextCode[Service::APCM_CODE] = $routes[Service::APCM_CODE][0]['code'];
        }
        if (in_array($e->service, [Service::COCM, Service::BHI], true) && $apcmBySamePractitioner && $minutes[Service::STAFF] + $minutes[Service::PRACTITIONER] > 0) {
            $addOn = $e->service === Service::BHI ? 'G0570' : ($cocmEarlierInEpisode ? 'G0569' : 'G0568');
            $routes[Service::ADD_ON] = [['code' => $addOn, 'units' => 1]];
            $next[Service::ADD_ON] = 0;
            $nextCode[Service::ADD_ON] = $addOn;
        }

        $blockers = [];
        if ($e->consentOn === null || !$e->consentComplete) {
            $blockers[] = self::NO_CONSENT;
        }
        if (count($e->conditions) < Service::minimumConditions($e->service)) {
            $blockers[] = self::TOO_FEW_CONDITIONS;
        }
        if ($e->initiatingNeeded && $e->initiatingVisitOn === null) {
            $blockers[] = self::NO_INITIATING_VISIT;
        }
        if (in_array($e->service, [Service::CCM, Service::PCM], true)) {
            $other = $e->service === Service::CCM ? Service::PCM : Service::CCM;
            foreach ($others as $o) {
                if ($o->id !== $e->id && $o->service === $other && $o->practitionerId === $e->practitionerId && $o->activeIn($month)) {
                    $blockers[] = self::CCM_AND_PCM;
                    break;
                }
            }
        }

        $sameMonth = static fn(callable $test): bool => array_reduce($others, static fn(bool $found, Enrollment $o): bool
            => $found || ($o->id !== $e->id && $o->activeIn($month) && $test($o)), false);
        if (($e->service === Service::COCM && $sameMonth(static fn(Enrollment $o): bool => $o->service === Service::BHI))
            || ($e->service === Service::BHI && $sameMonth(static fn(Enrollment $o): bool => $o->service === Service::COCM))) {
            $blockers[] = self::COCM_AND_BHI;
        }
        if (($e->service === Service::APCM && $sameMonth(static fn(Enrollment $o): bool => in_array($o->service, [Service::CCM, Service::PCM], true) && $o->practitionerId === $e->practitionerId))
            || (in_array($e->service, [Service::CCM, Service::PCM], true) && $apcmBySamePractitioner)) {
            $blockers[] = self::APCM_AND_CCM_PCM;
        }
        return [
            'staff' => $minutes[Service::STAFF],
            'practitioner' => $minutes[Service::PRACTITIONER],
            'routes' => $routes,
            'next' => $next,
            'nextCode' => $nextCode,
            'threshold' => match (true) {
                $e->service === Service::APCM => null,
                $e->service === Service::COCM => ($cocmEarlierInEpisode ? Service::COCM_LATER_MONTH : Service::COCM_FIRST_MONTH)['first'],
                default => Service::routes($e->service)[Service::STAFF]['first'],
            },
            'blockers' => $blockers,
            'capped' => $capped,
            'stopsThisMonth' => $e->stoppedOn !== null && $month->contains($e->stoppedOn),
        ];
    }
}
