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
 *  - RPM and RTM: one list of codes, billed together: set-up once an episode, with the first
 *    30-day period that has its days of data; the device code for each 30-day period that ends
 *    in the month, by its days of data; and treatment management by the month's minutes (staff
 *    and practitioner), when some of that time was a live conversation with the patient or
 *    caregiver.
 *
 * Codes, minutes and year-to-year rules come from the month's calendar year (Schedule).
 *
 * Blocked: consent not recorded with all the things the patient must be told; too few
 * conditions; no initiating visit when one was needed; PCM past the months one initiating
 * visit covers; CCM and PCM by the same practitioner in the same month; CoCM and general BHI
 * for the same patient in a month; APCM and CCM or PCM by the same practitioner in a month;
 * a second RPM or RTM enrollment for the patient in a month.
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
    public const PCM_RENEWAL = 'pcm_renewal';
    public const REMOTE_TWICE = 'remote_twice';

    /** Why a remote monitoring month's treatment management minutes meet no code. */
    public const NO_INTERACTIVE = 'no_interactive';

    /** Days in a remote monitoring device period. */
    public const PERIOD_DAYS = 30;

    /**
     * @param list<array{enrollmentId: int, servedOn: \DateTimeImmutable, minutes: int, role: string, interactive?: bool}> $entries the patient's;
     *        interactive: the time included a live conversation with the patient or caregiver (RPM and RTM)
     * @param list<Enrollment> $others the patient's other enrollments
     * @param bool $complexMdm the billing practitioner says they made moderate or high complexity decisions this month (CCM)
     * @param bool $cocmEarlierInEpisode the enrollment had CoCM time in the 6 calendar months before this one
     * @param list<array{enrollmentId: int, periodStart: \DateTimeImmutable, days: int}> $devicePeriods the patient's 30-day remote
     *        monitoring periods with their days of data, all of them (set-up goes with an enrollment's first period with enough days)
     * @param ?Schedule $schedule the year's codes and rules (null: the month's year, Schedule::forMonth)
     * @return array{staff: int, practitioner: int,
     *               routes: array<string, list<array{code: string, units: int}>>,
     *               next: array<string, int>, nextCode: array<string, string>, threshold: ?int, blockers: list<string>, capped: bool, stopsThisMonth: bool,
     *               notes: list<string>, periods: list<array{start: \DateTimeImmutable, end: \DateTimeImmutable, days: int, codes: list<string>}>,
     *               lookBack: ?int, schedule: array{year: int, covers: bool}}
     *         routes: by Service::STAFF / PRACTITIONER / COMPLEX / APCM_CODE / ADD_ON, the codes met (empty when the threshold isn't);
     *         next: minutes still needed for each route's base code (0 when met), nextCode: that base code;
     *         threshold: the minutes the main route's base code needs, or null for a service with no time (APCM);
     *         notes: why time meets no code (NO_INTERACTIVE); periods: an RPM or RTM enrollment's device periods that overlap the month;
     *         lookBack: the initiating visit's look-back for the enrollment's start year (Schedule::lookBack);
     *         schedule: the year counted under, and whether it is the month's own year
     */
    public static function summarize(Enrollment $e, array $entries, array $others, Month $month, bool $complexMdm = false, bool $cocmEarlierInEpisode = false,
        array $devicePeriods = [], ?Schedule $schedule = null): array
    {
        $schedule ??= Schedule::forMonth($month);
        $minutes = [Service::STAFF => 0, Service::PRACTITIONER => 0];
        $interactive = false;
        foreach ($entries as $entry) {
            if ($entry['enrollmentId'] === $e->id && $month->contains($entry['servedOn']) && isset($minutes[$entry['role']])) {
                $minutes[$entry['role']] += max(0, $entry['minutes']);
                $interactive = $interactive || ($entry['interactive'] ?? false);
            }
        }

        $routes = [];
        $next = [];
        $nextCode = [];
        $capped = false;
        foreach ($schedule->routes($e->service) as $route => $r) {
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
            $c = $schedule->complexCcm();
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
            $base = $schedule->cocmMonth($cocmEarlierInEpisode);
            $codes = [];
            if ($time >= $base['first']) {
                $codes[] = ['code' => $base['base'], 'units' => 1];
                $units = intdiv($time - $base['first'], $schedule->cocmAddOn()['each']);
                if ($units > 0) {
                    $codes[] = ['code' => $schedule->cocmAddOn()['code'], 'units' => $units];
                }
            } elseif ($time >= $schedule->cocmShort()['first']) {
                $codes[] = ['code' => $schedule->cocmShort()['code'], 'units' => 1];
            }
            $routes[Service::STAFF] = $codes;
            $next[Service::STAFF] = max(0, $base['first'] - $time);
            $nextCode[Service::STAFF] = $base['base'];
        }
        $notes = [];
        $periods = [];
        if (in_array($e->service, Service::REMOTE, true)) {
            [$routes[Service::STAFF], $periods, $next[Service::STAFF], $nextCode[Service::STAFF], $notes] = self::remote(
                $e, $schedule->remote($e->service), $minutes[Service::STAFF] + $minutes[Service::PRACTITIONER], $interactive, $devicePeriods, $month);
        }
        $apcmBySamePractitioner = false;
        foreach ($others as $o) {
            if ($o->id !== $e->id && $o->service === Service::APCM && $o->practitionerId === $e->practitionerId && $o->activeIn($month)) {
                $apcmBySamePractitioner = true;
            }
        }
        if ($e->service === Service::APCM) {
            $routes[Service::APCM_CODE] = [['code' => $schedule->apcmCode(count($e->conditions), $e->qmb), 'units' => 1]];
            $next[Service::APCM_CODE] = 0;
            $nextCode[Service::APCM_CODE] = $routes[Service::APCM_CODE][0]['code'];
        }
        if (in_array($e->service, [Service::COCM, Service::BHI], true) && $apcmBySamePractitioner && $minutes[Service::STAFF] + $minutes[Service::PRACTITIONER] > 0) {
            $addOn = $schedule->apcmAddOn($e->service, $cocmEarlierInEpisode);
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
        if ($e->service === Service::PCM && self::pcmRenewalDue($e, $month, $schedule->pcmRenewalMonths())) {
            $blockers[] = self::PCM_RENEWAL;
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
        if (in_array($e->service, Service::REMOTE, true) && $sameMonth(static fn(Enrollment $o): bool => in_array($o->service, Service::REMOTE, true))) {
            $blockers[] = self::REMOTE_TWICE;
        }
        return [
            'staff' => $minutes[Service::STAFF],
            'practitioner' => $minutes[Service::PRACTITIONER],
            'routes' => $routes,
            'next' => $next,
            'nextCode' => $nextCode,
            'threshold' => match (true) {
                $e->service === Service::APCM => null,
                $e->service === Service::COCM => $schedule->cocmMonth($cocmEarlierInEpisode)['first'],
                in_array($e->service, Service::REMOTE, true) => $schedule->remote($e->service)['management']['short']['first'],
                default => $schedule->routes($e->service)[Service::STAFF]['first'],
            },
            'blockers' => $blockers,
            'capped' => $capped,
            'stopsThisMonth' => $e->stoppedOn !== null && $month->contains($e->stoppedOn),
            'notes' => $notes,
            'periods' => $periods,
            'lookBack' => ($schedule->year === (int)$e->startedOn->format('Y') ? $schedule : Schedule::forYear((int)$e->startedOn->format('Y')))->lookBack($e->service),
            'schedule' => ['year' => $schedule->year, 'covers' => $schedule->covers($month)],
        ];
    }

    /**
     * An RPM or RTM month's codes, billed together: set-up, the device code of each 30-day period
     * ending in the month, and treatment management.
     *
     * @param array<string, mixed> $r Schedule::remote()
     * @param list<array{enrollmentId: int, periodStart: \DateTimeImmutable, days: int}> $devicePeriods
     * @return array{0: list<array{code: string, units: int}>, 1: list<array{start: \DateTimeImmutable, end: \DateTimeImmutable, days: int, codes: list<string>}>,
     *               2: int, 3: string, 4: list<string>} codes, the periods overlapping the month, minutes to the next management code, that code, notes
     */
    private static function remote(Enrollment $e, array $r, int $time, bool $interactive, array $devicePeriods, Month $month): array
    {
        $mine = array_values(array_filter($devicePeriods, static fn(array $p): bool => $p['enrollmentId'] === $e->id));
        usort($mine, static fn(array $a, array $b): int => $a['periodStart'] <=> $b['periodStart']);
        $setupPeriod = null;
        foreach ($mine as $i => $p) {
            if ($p['days'] >= $r['setup']['days']) {
                $setupPeriod = $i;
                break;
            }
        }
        // RPM has one kind; RTM's codes follow what it monitors.
        $kind = $r['device'][$e->service === Service::RPM ? 'any' : (string)$e->deviceKind] ?? null;
        $counts = [];
        $periods = [];
        foreach ($mine as $i => $p) {
            $start = $p['periodStart']->setTime(0, 0);
            $end = $start->modify('+' . (self::PERIOD_DAYS - 1) . ' days');
            if ($end < $month->first() || $start > $month->last()) {
                continue;
            }
            $codes = [];
            if ($month->contains($end)) {
                if ($i === $setupPeriod) {
                    $codes[] = $r['setup']['code'];
                }
                if ($kind !== null && $p['days'] >= $kind['full']['days']) {
                    $codes[] = $kind['full']['code'];
                } elseif ($kind !== null && $p['days'] >= $kind['short']['days']) {
                    $codes[] = $kind['short']['code'];
                }
                foreach ($codes as $c) {
                    $counts[$c] = ($counts[$c] ?? 0) + 1;
                }
            }
            $periods[] = ['start' => $start, 'end' => $end, 'days' => $p['days'], 'codes' => $codes];
        }
        $m = $r['management'];
        $notes = [];
        if ($time >= $m['short']['first'] && !$interactive) {
            $notes[] = self::NO_INTERACTIVE;
        } elseif ($time >= $m['first']) {
            $counts[$m['base']] = 1;
            $units = intdiv($time - $m['first'], $m['each']);
            if ($units > 0) {
                $counts[$m['addOn']] = $units;
            }
        } elseif ($time >= $m['short']['first']) {
            $counts[$m['short']['code']] = 1;
        }
        [$next, $nextCode] = $time < $m['short']['first'] ? [$m['short']['first'] - $time, $m['short']['code']]
            : ($time < $m['first'] ? [$m['first'] - $time, $m['base']] : [0, $m['base']]);
        $codes = array_map(static fn(string $c, int $u): array => ['code' => $c, 'units' => $u], array_keys($counts), $counts);
        return [$codes, $periods, $next, $nextCode, $notes];
    }

    /**
     * Whether a PCM month is past the calendar months its initiating visit covers. "You can provide
     * PCM services monthly if the patient needs them. After 1 year, we require another initial
     * visit to continue the services" (MLN909188, June 2025, page 10). The year runs from the later
     * of the start and the latest initiating visit recorded by the month's end, by calendar month:
     * 12 months beginning with that one's.
     */
    private static function pcmRenewalDue(Enrollment $e, Month $month, int $months): bool
    {
        $from = $e->startedOn;
        if ($e->initiatingVisitOn !== null && $e->initiatingVisitOn > $from && $e->initiatingVisitOn->setTime(0, 0) <= $month->last()) {
            $from = $e->initiatingVisitOn;
        }
        $elapsed = ((int)$month->first()->format('Y') - (int)$from->format('Y')) * 12 + (int)$month->first()->format('n') - (int)$from->format('n');
        return $elapsed >= $months;
    }
}
