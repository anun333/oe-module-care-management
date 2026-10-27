<?php

/**
 * RPM and RTM device periods: 30 days from a start, with the days the device sent data, as
 * staff read them from the device's own system. Like time entries they are never edited: a
 * wrong one is removed, and the removal is kept with who made it and when.
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
use OpenHealthcare\CareManagement\Domain\MonthRules;

final class DevicePeriodRepository
{
    /**
     * Every standing period of some patients, oldest first: set-up goes with an enrollment's
     * first period with enough days, so all of them are read, not only this month's.
     *
     * @param list<int> $pids
     * @return list<array{id: int, pid: int, enrollmentId: int, periodStart: \DateTimeImmutable, days: int, user: string}>
     */
    public function forPatients(array $pids): array
    {
        if ($pids === []) {
            return [];
        }
        $rows = QueryUtils::fetchRecords(
            "SELECT d.`id`, d.`pid`, d.`enrollment_id`, d.`period_start`, d.`days`, CONCAT_WS(' ', u.`fname`, u.`lname`) AS user_name
               FROM `care_mgmt_device_period` AS d LEFT JOIN `users` AS u ON u.`id` = d.`user_id`
              WHERE d.`removed_at` IS NULL AND d.`pid` IN (" . implode(', ', array_fill(0, count($pids), '?')) . ')
              ORDER BY d.`period_start`, d.`id`',
            $pids,
        );
        return array_map(static fn(array $r): array => [
            'id' => (int)$r['id'],
            'pid' => (int)$r['pid'],
            'enrollmentId' => (int)$r['enrollment_id'],
            'periodStart' => new \DateTimeImmutable((string)$r['period_start']),
            'days' => (int)$r['days'],
            'user' => trim((string)($r['user_name'] ?? '')),
        ], $rows);
    }

    /**
     * A standing period of the patient's that a new one starting on $start would overlap: only one
     * practitioner can bill the device codes "during a 30-day period", for RPM or RTM, not both
     * (CY 2024 PFS rule, 88 FR 78883).
     *
     * @param list<array{periodStart: \DateTimeImmutable}> $periods the patient's
     */
    public static function overlapping(array $periods, \DateTimeImmutable $start): ?\DateTimeImmutable
    {
        $start = $start->setTime(0, 0);
        foreach ($periods as $p) {
            $days = (int)$p['periodStart']->setTime(0, 0)->diff($start)->format('%r%a');
            if (abs($days) < MonthRules::PERIOD_DAYS) {
                return $p['periodStart'];
            }
        }
        return null;
    }

    public function add(int $pid, int $enrollmentId, string $periodStart, int $days, int $userId): int
    {
        return (int)QueryUtils::sqlInsert(
            'INSERT INTO `care_mgmt_device_period` (`pid`, `enrollment_id`, `period_start`, `days`, `user_id`) VALUES (?, ?, ?, ?, ?)',
            [$pid, $enrollmentId, $periodStart, $days, $userId],
        );
    }

    public function remove(int $id, int $pid, int $userId): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `care_mgmt_device_period` SET `removed_at` = NOW(), `removed_by` = ? WHERE `id` = ? AND `pid` = ? AND `removed_at` IS NULL',
            [$userId, $id, $pid],
        );
    }
}
