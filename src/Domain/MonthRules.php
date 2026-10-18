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
 *
 * Blocked: consent not recorded with all four things the patient must be told; too few
 * conditions; no initiating visit when one was needed; CCM and PCM by the same
 * practitioner in the same month.
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

    /**
     * @param list<array{enrollmentId: int, servedOn: \DateTimeImmutable, minutes: int, role: string}> $entries the patient's
     * @param list<Enrollment> $others the patient's other enrollments
     * @return array{staff: int, practitioner: int,
     *               routes: array<string, list<array{code: string, units: int}>>,
     *               next: array<string, int>, blockers: list<string>, capped: bool, stopsThisMonth: bool}
     *         routes: by Service::STAFF / PRACTITIONER, the codes met (empty when the threshold isn't);
     *         next: minutes still needed for each route's base code (0 when met)
     */
    public static function summarize(Enrollment $e, array $entries, array $others, Month $month): array
    {
        $minutes = [Service::STAFF => 0, Service::PRACTITIONER => 0];
        foreach ($entries as $entry) {
            if ($entry['enrollmentId'] === $e->id && $month->contains($entry['servedOn']) && isset($minutes[$entry['role']])) {
                $minutes[$entry['role']] += max(0, $entry['minutes']);
            }
        }

        $routes = [];
        $next = [];
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

        return [
            'staff' => $minutes[Service::STAFF],
            'practitioner' => $minutes[Service::PRACTITIONER],
            'routes' => $routes,
            'next' => $next,
            'blockers' => $blockers,
            'capped' => $capped,
            'stopsThisMonth' => $e->stoppedOn !== null && $month->contains($e->stoppedOn),
        ];
    }
}
