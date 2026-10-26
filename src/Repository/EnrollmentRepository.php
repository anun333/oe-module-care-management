<?php

/**
 * Care management enrollments, and what the enrollment form offers: the patient's
 * active problems and the practitioners who can bill.
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
use OpenHealthcare\CareManagement\Domain\Enrollment;
use OpenHealthcare\CareManagement\Domain\Service;
use OpenHealthcare\CareManagement\Domain\Month;

final class EnrollmentRepository
{
    private const COLUMNS = '`id`, `pid`, `service`, `practitioner_id`, `conditions_json`, `consent_on`, `consent_how`, `consent_explained`,
        `consent_by`, `initiating_visit_on`, `started_on`, `stopped_on`, `note`, `qmb`';

    /**
     * The columns read, with whether an initiating visit is needed, worked out on read under
     * each service's rule (Service::initiatingLookBack), so enrollments saved under an earlier
     * rule read right too: 0.3.7's 1 year for APCM, and before 0.3.9 a visit with any
     * practitioner for the others. What was stored at enrollment stays for an unknown service.
     */
    public static function columns(): string
    {
        $cases = '';
        foreach (Service::ALL as $service) {
            $rule = Service::initiatingLookBack($service);
            $seen = 'SELECT 1 FROM `form_encounter` AS fe WHERE fe.`pid` = `care_mgmt_enrollment`.`pid`'
                . ($rule['byPractitioner'] ? ' AND fe.`provider_id` = `care_mgmt_enrollment`.`practitioner_id`' : '')
                . ' AND fe.`date` < `care_mgmt_enrollment`.`started_on`'
                . ' AND fe.`date` >= DATE_SUB(`care_mgmt_enrollment`.`started_on`, INTERVAL ' . (int)$rule['years'] . ' YEAR)';
            $cases .= " WHEN '" . $service . "' THEN NOT EXISTS ($seen)";
        }
        return self::COLUMNS . ', CASE `service`' . $cases . ' ELSE `initiating_needed` END AS `initiating_needed`';
    }

    /** @return list<Enrollment> newest first */
    public function forPatient(int $pid): array
    {
        return array_map(
            self::enrollment(...),
            QueryUtils::fetchRecords('SELECT ' . self::columns() . ' FROM `care_mgmt_enrollment` WHERE `pid` = ? ORDER BY `started_on` DESC, `id` DESC', [$pid]),
        );
    }

    /** @return list<Enrollment> every patient's enrollments active in the month */
    public function activeIn(Month $month): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT ' . self::columns() . ' FROM `care_mgmt_enrollment`
              WHERE `started_on` <= ? AND (`stopped_on` IS NULL OR `stopped_on` >= ?) ORDER BY `pid`, `id`',
            [$month->last()->format('Y-m-d'), $month->first()->format('Y-m-d')],
        );
        return array_map(self::enrollment(...), $rows);
    }

    /** @return array<int, array{consentHow: string, note: string, conditions: list<array{id: int, title: string, code: string}>}> by id */
    public function details(int $pid): array
    {
        $out = [];
        foreach (QueryUtils::fetchRecords('SELECT `id`, `consent_how`, `note`, `conditions_json` FROM `care_mgmt_enrollment` WHERE `pid` = ?', [$pid]) as $r) {
            $out[(int)$r['id']] = [
                'consentHow' => (string)($r['consent_how'] ?? ''),
                'note' => (string)($r['note'] ?? ''),
                'conditions' => self::conditions((string)$r['conditions_json']),
            ];
        }
        return $out;
    }

    /**
     * @param list<array{id: int, title: string, code: string}> $conditions
     */
    public function add(int $pid, string $service, int $practitionerId, array $conditions, ?string $consentOn, ?string $consentHow, bool $explained,
        ?string $initiatingVisitOn, string $startedOn, string $note, int $userId, bool $qmb = false): int
    {
        return (int)QueryUtils::sqlInsert(
            'INSERT INTO `care_mgmt_enrollment` (`pid`, `service`, `practitioner_id`, `conditions_json`, `consent_on`, `consent_how`, `consent_explained`,
                 `consent_by`, `initiating_needed`, `initiating_visit_on`, `started_on`, `note`, `created_by`, `qmb`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $service, $practitionerId, json_encode($conditions, JSON_THROW_ON_ERROR), $consentOn, $consentHow, $explained ? 1 : 0,
                $consentOn === null ? null : $userId, $this->seenBefore($pid, $startedOn, $service, $practitionerId) ? 0 : 1, $initiatingVisitOn, $startedOn, $note, $userId,
                $service === Service::APCM && $qmb ? 1 : 0],
        );
    }

    public function recordConsent(int $id, int $pid, string $consentOn, string $consentHow, bool $explained, ?string $initiatingVisitOn, int $userId): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `care_mgmt_enrollment` SET `consent_on` = ?, `consent_how` = ?, `consent_explained` = ?, `consent_by` = ?,
                 `initiating_visit_on` = COALESCE(?, `initiating_visit_on`) WHERE `id` = ? AND `pid` = ?',
            [$consentOn, $consentHow, $explained ? 1 : 0, $userId, $initiatingVisitOn, $id, $pid],
        );
    }

    public function stop(int $id, int $pid, string $stoppedOn): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `care_mgmt_enrollment` SET `stopped_on` = ? WHERE `id` = ? AND `pid` = ? AND `stopped_on` IS NULL',
            [$stoppedOn, $id, $pid],
        );
    }

    /**
     * The patient's problems still active, not marked entered in error or refuted.
     *
     * @return list<array{id: int, title: string, code: string}>
     */
    public function problems(int $pid): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT `id`, `title`, `diagnosis` FROM `lists`
              WHERE `pid` = ? AND `type` = 'medical_problem' AND `activity` = 1
                AND (`enddate` IS NULL OR `enddate` >= CURDATE())
                AND COALESCE(`verification`, '') NOT IN ('entered-in-error', 'refuted')
              ORDER BY `title`",
            [$pid],
        );
        return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'code' => (string)($r['diagnosis'] ?? '')], $rows);
    }

    /** @return array<int, string> users who can bill: active, authorized */
    public function practitioners(): array
    {
        $out = [];
        foreach (QueryUtils::fetchRecords("SELECT `id`, `fname`, `lname` FROM `users` WHERE `active` = 1 AND `authorized` = 1 AND COALESCE(`username`, '') <> '' ORDER BY `lname`, `fname`") as $r) {
            $out[(int)$r['id']] = trim($r['fname'] . ' ' . $r['lname']);
        }
        return $out;
    }

    /**
     * Whether the patient was seen recently enough before a date that the service needs no
     * initiating visit: within its look-back, and by the billing practitioner where the
     * service says so (Service::initiatingLookBack).
     */
    private function seenBefore(int $pid, string $day, string $service, int $practitionerId): bool
    {
        $rule = Service::initiatingLookBack($service);
        $sql = 'SELECT COUNT(*) AS n FROM `form_encounter` WHERE `pid` = ? AND `date` < ? AND `date` >= DATE_SUB(?, INTERVAL ' . (int)$rule['years'] . ' YEAR)';
        $binds = [$pid, $day, $day];
        if ($rule['byPractitioner']) {
            $sql .= ' AND `provider_id` = ?';
            $binds[] = $practitionerId;
        }
        $row = QueryUtils::fetchRecords($sql, $binds)[0] ?? ['n' => 0];
        return (int)$row['n'] > 0;
    }

    /** @return list<array{id: int, title: string, code: string}> */
    private static function conditions(string $json): array
    {
        $data = json_decode($json, true);
        $out = [];
        foreach (is_array($data) ? $data : [] as $c) {
            if (is_array($c)) {
                $out[] = ['id' => (int)($c['id'] ?? 0), 'title' => (string)($c['title'] ?? ''), 'code' => (string)($c['code'] ?? '')];
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $r */
    private static function enrollment(array $r): Enrollment
    {
        $date = static fn(mixed $v): ?\DateTimeImmutable => is_string($v) && $v !== '' && !str_starts_with($v, '0000') ? new \DateTimeImmutable($v) : null;
        return new Enrollment(
            (int)$r['id'],
            (int)$r['pid'],
            (string)$r['service'],
            (int)$r['practitioner_id'],
            array_map(static fn(array $c): string => $c['code'] !== '' ? $c['code'] : $c['title'], self::conditions((string)$r['conditions_json'])),
            $date($r['consent_on']),
            (int)$r['consent_explained'] === 1 && in_array($r['consent_how'], ['verbal', 'written'], true),
            (int)$r['initiating_needed'] === 1,
            $date($r['initiating_visit_on']),
            new \DateTimeImmutable((string)$r['started_on']),
            $date($r['stopped_on']),
            (int)($r['qmb'] ?? 0) === 1,
        );
    }
}
