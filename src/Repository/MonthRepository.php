<?php

/**
 * What the billing practitioner records about a month of a CCM enrollment: that they made
 * moderate or high complexity medical decisions in it, which complex CCM (99487) needs
 * (MLN909188: it "cannot be subcontracted to any other individual").
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\CareManagement\Domain\Month;

final class MonthRepository
{
    /**
     * @param list<int> $enrollmentIds
     * @return array<int, bool> enrollment id => complex decision-making recorded for the month
     */
    public function complex(array $enrollmentIds, Month $month): array
    {
        if ($enrollmentIds === []) {
            return [];
        }
        $rows = QueryUtils::fetchRecords(
            'SELECT `enrollment_id` FROM `care_mgmt_month` WHERE `month_start` = ? AND `complex_mdm` = 1
               AND `enrollment_id` IN (' . implode(', ', array_fill(0, count($enrollmentIds), '?')) . ')',
            [$month->first()->format('Y-m-d'), ...$enrollmentIds],
        );
        $out = array_fill_keys($enrollmentIds, false);
        foreach ($rows as $r) {
            $out[(int)$r['enrollment_id']] = true;
        }
        return $out;
    }

    public function setComplex(int $enrollmentId, int $pid, Month $month, bool $on, int $userId): void
    {
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO `care_mgmt_month` (`enrollment_id`, `pid`, `month_start`, `complex_mdm`, `recorded_by`, `recorded_at`) VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE `complex_mdm` = VALUES(`complex_mdm`), `recorded_by` = VALUES(`recorded_by`), `recorded_at` = NOW()',
            [$enrollmentId, $pid, $month->first()->format('Y-m-d'), $on ? 1 : 0, $userId],
        );
    }
}
