<?php

/**
 * What was billed: care management codes on the patients' fee sheets, for encounters in a month.
 * It only reads `billing` (active lines) and `form_encounter`.
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
use OpenHealthcare\CareManagement\Domain\Service;

final class BillingRepository
{
    /**
     * Units billed of each care management code, by patient, on encounters dated in the month.
     *
     * @param list<int> $pids
     * @return array<int, array<string, int>> pid => code => units
     */
    public function billedIn(Month $month, array $pids): array
    {
        if ($pids === []) {
            return [];
        }
        $codes = array_values(array_unique(array_merge(...array_map(Service::codes(...), Service::ALL))));
        $rows = QueryUtils::fetchRecords(
            'SELECT b.`pid`, b.`code`, SUM(GREATEST(COALESCE(b.`units`, 1), 1)) AS units
               FROM `billing` AS b
               JOIN `form_encounter` AS fe ON fe.`encounter` = b.`encounter` AND fe.`pid` = b.`pid`
              WHERE b.`activity` = 1 AND b.`code_type` IN (\'CPT4\', \'HCPCS\')
                AND b.`code` IN (' . implode(', ', array_fill(0, count($codes), '?')) . ')
                AND b.`pid` IN (' . implode(', ', array_fill(0, count($pids), '?')) . ')
                AND fe.`date` >= ? AND fe.`date` < ?
              GROUP BY b.`pid`, b.`code`',
            [...$codes, ...$pids, $month->first()->format('Y-m-d'), $month->last()->modify('+1 day')->format('Y-m-d')],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['pid']][(string)$r['code']] = (int)$r['units'];
        }
        return $out;
    }
}
