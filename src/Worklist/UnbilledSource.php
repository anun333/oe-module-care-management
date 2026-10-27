<?php

/**
 * The Staff Worklist's "Care management, not billed" tab, which this module adds through the
 * worklist's staff_worklist.collect_sources event: an enrollment whose month, in the last three
 * completed months, met a code with nothing billed for its service, read as the month list reads
 * it (MonthLines). It only reads; the button opens the month list. It is built on openhealthcare-common's
 * Worklist contract (1.5.0), so it loads, and is tested, without the Worklist's classes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Worklist;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\CareManagement\Bootstrap;
use OpenHealthcare\CareManagement\Domain\BillingCheck;
use OpenHealthcare\CareManagement\Domain\Month;
use OpenHealthcare\CareManagement\Domain\UnbilledMonths;
use OpenHealthcare\CareManagement\Labels;
use OpenHealthcare\CareManagement\Repository\EnrollmentRepository;
use OpenHealthcare\CareManagement\Repository\MonthLines;
use OpenHealthcare\Common\Contract\Worklist\ItemKey;
use OpenHealthcare\Common\Contract\Worklist\PatientRef;
use OpenHealthcare\Common\Contract\Worklist\WorkItem;
use OpenHealthcare\Common\Contract\Worklist\WorkSource;

final class UnbilledSource implements WorkSource
{
    /** Its rows' keys: "caremgmt:<pid>:<enrollment id><YYYYMM>". */
    public const ID = 'caremgmt';

    public function __construct(
        private readonly \DateTimeImmutable $now,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function collect(?int $pid = null): array
    {
        $today = $this->now->setTime(0, 0);
        $found = [];
        foreach (UnbilledMonths::previous($today) as $first) {
            $month = Month::of($first);
            foreach ((new MonthLines())->forMonth($month, true, $today) as $line) {
                $e = $line['e'];
                if ($line['check']['status'] === BillingCheck::NOT_BILLED && ($pid === null || $e->pid === $pid)) {
                    $found[] = [$first, $month, $line];
                }
            }
        }
        if ($found === []) {
            return [];
        }
        $pids = array_values(array_unique(array_map(static fn(array $f): int => $f[2]['e']->pid, $found)));
        $patients = [];
        foreach (QueryUtils::fetchRecords('SELECT pd.pid, ' . PatientRef::SQL_COLUMNS . ' FROM patient_data AS pd WHERE pd.pid IN ('
            . implode(', ', array_fill(0, count($pids), '?')) . ')', $pids) as $row) {
            $patients[(int)$row['pid']] = $row;
        }
        $practitioners = (new EnrollmentRepository())->practitioners();
        $items = [];
        foreach ($found as [$first, $month, $line]) {
            $e = $line['e'];
            if (!isset($patients[$e->pid])) {
                continue;
            }
            $met = [];
            foreach (array_filter($line['s']['routes']) as $route => $codes) {
                $met[] = Labels::route($route, $e->service) . ': ' . Labels::codes($codes);
            }
            $facts = [
                sprintf(xl('Clinical staff %1$d min, billing practitioner %2$d min'), (int)$line['s']['staff'], (int)$line['s']['practitioner']),
                xl('Met') . ': ' . implode('; ', $met),
            ];
            if (isset($practitioners[$e->practitionerId])) {
                $facts[] = xl('Billing practitioner') . ': ' . $practitioners[$e->practitionerId];
            }
            $items[] = new WorkItem(
                key: new ItemKey(self::ID, $e->pid, UnbilledMonths::ref($e->id, $first)),
                patient: PatientRef::fromRow($e->pid, $patients[$e->pid], $this->now),
                title: sprintf(xl('%1$s month met, not billed: %2$s'), Labels::service($e->service), xl($first->format('F')) . ' ' . $first->format('Y')),
                detail: implode(' · ', $facts),
                priority: WorkItem::PRIORITY_NORMAL,
                since: $month->last(),
                ownerId: $e->practitionerId > 0 ? $e->practitionerId : null,
                actionLabel: xl('Month list'),
                actionUrl: Bootstrap::MODULE_INSTALLATION_PATH . '/public/index.php?month=' . $month->key(),
                actionMode: 'dialog',
                dismissible: true,
                siteId: null,
                sensitivity: null,
            );
        }
        return $items;
    }
}
