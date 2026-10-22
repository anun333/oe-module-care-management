<?php

/**
 * A month's lines: every enrollment active in it, with its minutes and the codes they meet
 * (MonthRules), and, when billing may be seen, what was billed for its service and how that
 * compares (BillingCheck). The month list builds its page and CSV from these, and the Staff
 * Worklist its "met, not billed" tab, so both read the month the same way.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Repository;

use OpenHealthcare\CareManagement\Domain\BillingCheck;
use OpenHealthcare\CareManagement\Domain\Enrollment;
use OpenHealthcare\CareManagement\Domain\Month;
use OpenHealthcare\CareManagement\Domain\MonthRules;

final class MonthLines
{
    /**
     * @param bool $withBilling whether to read what was billed (acct/bill): without it, nothing counts as billed
     * @return list<array{e: Enrollment, s: array<string, mixed>, billable: bool, billed: array<string, int>, check: array{status: string, beyond: array<string, int>}}>
     */
    public function forMonth(Month $month, bool $withBilling, \DateTimeImmutable $today): array
    {
        $enrollments = new EnrollmentRepository();
        $times = new TimeRepository();
        $active = $enrollments->activeIn($month);
        $pids = array_values(array_unique(array_map(static fn(Enrollment $e): int => $e->pid, $active)));
        $entries = $times->inMonth($month, $pids);
        $byPatient = [];
        foreach ($active as $e) {
            $byPatient[$e->pid][] = $e;
        }
        $ids = array_map(static fn(Enrollment $e): int => $e->id, $active);
        $complex = (new MonthRepository())->complex($ids, $month);
        $earlier = $times->hadTimeBefore($ids, $month);
        $billed = $withBilling ? (new BillingRepository())->billedIn($month, $pids) : [];
        $monthOver = $month->last() < $today;
        $lines = [];
        foreach ($active as $e) {
            $s = MonthRules::summarize($e, $entries, $byPatient[$e->pid], $month, $complex[$e->id] ?? false, $earlier[$e->id] ?? false);
            $codesBilled = BillingCheck::forService($billed[$e->pid] ?? [], $e->service);
            $lines[] = ['e' => $e, 's' => $s, 'billable' => $s['blockers'] === [] && array_filter($s['routes']) !== [],
                'billed' => $codesBilled, 'check' => BillingCheck::check($s['routes'], $s['blockers'], $codesBilled, $monthOver)];
        }
        return $lines;
    }
}
