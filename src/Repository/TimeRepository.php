<?php

/**
 * Time logged for care management. Entries are never edited: a wrong one is removed,
 * and the removal is kept with who made it and when.
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

final class TimeRepository
{
    /**
     * Entries still standing in a month, for some patients (all patients when $pids is null).
     *
     * @param list<int>|null $pids
     * @return list<array{id: int, pid: int, enrollmentId: int, servedOn: \DateTimeImmutable, minutes: int, role: string, activity: string, user: string, interactive: bool}>
     *         interactive: the time included a live conversation with the patient or caregiver (RPM and RTM)
     */
    public function inMonth(Month $month, ?array $pids = null): array
    {
        $sql = "SELECT t.`id`, t.`pid`, t.`enrollment_id`, t.`served_on`, t.`minutes`, t.`role`, t.`activity`, t.`interactive`, CONCAT_WS(' ', u.`fname`, u.`lname`) AS user_name
                  FROM `care_mgmt_time` AS t LEFT JOIN `users` AS u ON u.`id` = t.`user_id`
                 WHERE t.`removed_at` IS NULL AND t.`served_on` >= ? AND t.`served_on` <= ?";
        $binds = [$month->first()->format('Y-m-d'), $month->last()->format('Y-m-d')];
        if ($pids !== null) {
            if ($pids === []) {
                return [];
            }
            $sql .= ' AND t.`pid` IN (' . implode(', ', array_fill(0, count($pids), '?')) . ')';
            array_push($binds, ...$pids);
        }
        $sql .= ' ORDER BY t.`served_on`, t.`id`';
        return array_map(static fn(array $r): array => [
            'id' => (int)$r['id'],
            'pid' => (int)$r['pid'],
            'enrollmentId' => (int)$r['enrollment_id'],
            'servedOn' => new \DateTimeImmutable((string)$r['served_on']),
            'minutes' => (int)$r['minutes'],
            'role' => (string)$r['role'],
            'activity' => (string)$r['activity'],
            'user' => trim((string)($r['user_name'] ?? '')),
            'interactive' => (int)($r['interactive'] ?? 0) === 1,
        ], QueryUtils::fetchRecords($sql, $binds));
    }

    /**
     * Which of these CoCM enrollments fall in an episode already under way: the patient had
     * CoCM time, under any of their CoCM enrollments, in the calendar months just before this
     * one, up to Service::COCM_NEW_EPISODE_MONTHS. The episode is the patient's, not the
     * enrollment's, so a patient stopped and enrolled again within 6 months doesn't start a new
     * one (CY 2017 PFS rule, 81 FR 80241). Other services' enrollments are always false.
     *
     * @param list<int> $enrollmentIds
     * @return array<int, bool> enrollment id => in an episode under way
     */
    public function hadTimeBefore(array $enrollmentIds, Month $month): array
    {
        if ($enrollmentIds === []) {
            return [];
        }
        $from = $month->first()->modify('-' . Service::COCM_NEW_EPISODE_MONTHS . ' months')->format('Y-m-d');
        $rows = QueryUtils::fetchRecords(
            'SELECT DISTINCT e.`id` FROM `care_mgmt_enrollment` AS e
               JOIN `care_mgmt_time` AS t ON t.`pid` = e.`pid` AND t.`removed_at` IS NULL AND t.`served_on` >= ? AND t.`served_on` < ?
               JOIN `care_mgmt_enrollment` AS earlier ON earlier.`id` = t.`enrollment_id` AND earlier.`service` = ?
              WHERE e.`service` = ? AND e.`id` IN (' . implode(', ', array_fill(0, count($enrollmentIds), '?')) . ')',
            [$from, $month->first()->format('Y-m-d'), Service::COCM, Service::COCM, ...$enrollmentIds],
        );
        $out = array_fill_keys($enrollmentIds, false);
        foreach ($rows as $r) {
            $out[(int)$r['id']] = true;
        }
        return $out;
    }

    public function add(int $pid, int $enrollmentId, string $servedOn, int $minutes, string $role, string $activity, int $userId, bool $interactive = false): int
    {
        return (int)QueryUtils::sqlInsert(
            'INSERT INTO `care_mgmt_time` (`pid`, `enrollment_id`, `served_on`, `minutes`, `role`, `activity`, `user_id`, `interactive`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $enrollmentId, $servedOn, $minutes, $role, $activity, $userId, $interactive ? 1 : 0],
        );
    }

    public function remove(int $id, int $pid, int $userId): bool
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `care_mgmt_time` SET `removed_at` = NOW(), `removed_by` = ? WHERE `id` = ? AND `pid` = ? AND `removed_at` IS NULL',
            [$userId, $id, $pid],
        );
        return true;
    }
}
