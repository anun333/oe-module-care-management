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
use OpenHealthcare\CareManagement\Domain\Month;
use OpenHealthcare\CareManagement\Domain\Schedule;
use OpenHealthcare\CareManagement\Domain\Service;

final class EnrollmentRepository
{
    private const COLUMNS = '`id`, `pid`, `service`, `practitioner_id`, `conditions_json`, `consent_on`, `consent_how`, `consent_explained`,
        `consent_by`, `initiating_visit_on`, `started_on`, `stopped_on`, `note`, `qmb`, `device_kind`';

    /**
     * The columns read, with whether an initiating visit is needed, worked out on read under
     * each service's rule for the year the enrollment started (Service::initiatingLookBack), so
     * enrollments saved under an earlier rule read right too: 0.3.7's 1 year for APCM, and before
     * 0.3.9 a visit with any practitioner for the others. What was stored at enrollment stays for
     * an unknown service. $allowProposed counts proposed years too: only tests do, to see a
     * year's change of rule.
     */
    public static function columns(bool $allowProposed = false): string
    {
        $cases = '';
        foreach (Service::ALL as $service) {
            // The rule of each year a start can be counted under, newest first; a run of years with the same rule is one.
            $byYear = [];
            foreach (array_reverse(Schedule::usableYears($allowProposed)) as $year) {
                $byYear[$year] = self::needs(Service::initiatingLookBack($service, $year, $allowProposed));
            }
            $rules = array_values(array_unique($byYear));
            if (count($rules) === 1) {
                $cases .= " WHEN '" . $service . "' THEN " . $rules[0];
                continue;
            }
            $when = '';
            $years = array_keys($byYear);
            foreach ($years as $i => $year) {
                if ($i < count($years) - 1 && $byYear[$year] !== $byYear[$years[$i + 1]]) {
                    $when .= ' WHEN YEAR(`care_mgmt_enrollment`.`started_on`) >= ' . (int)$year . ' THEN ' . $byYear[$year];
                }
            }
            $cases .= " WHEN '" . $service . "' THEN CASE" . $when . ' ELSE ' . end($byYear) . ' END';
        }
        return self::COLUMNS . ', CASE `service`' . $cases . ' ELSE `initiating_needed` END AS `initiating_needed`';
    }

    /**
     * The SQL for whether an enrollment needs an initiating visit under a look-back: none (0), always
     * (1), or no visit in the years before the start, by the billing practitioner where it says so.
     *
     * @param array{years: ?int, byPractitioner: bool} $rule
     */
    private static function needs(array $rule): string
    {
        if ($rule['years'] === null) {
            return '0';
        }
        if ($rule['years'] === 0) {
            return '1';
        }
        return 'NOT EXISTS (SELECT 1 FROM `form_encounter` AS fe WHERE fe.`pid` = `care_mgmt_enrollment`.`pid`'
            . ($rule['byPractitioner'] ? ' AND fe.`provider_id` = `care_mgmt_enrollment`.`practitioner_id`' : '')
            . ' AND fe.`date` < `care_mgmt_enrollment`.`started_on`'
            . ' AND fe.`date` >= DATE_SUB(`care_mgmt_enrollment`.`started_on`, INTERVAL ' . (int)$rule['years'] . ' YEAR))';
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
        ?string $initiatingVisitOn, string $startedOn, string $note, int $userId, bool $qmb = false, ?string $deviceKind = null): int
    {
        return (int)QueryUtils::sqlInsert(
            'INSERT INTO `care_mgmt_enrollment` (`pid`, `service`, `practitioner_id`, `conditions_json`, `consent_on`, `consent_how`, `consent_explained`,
                 `consent_by`, `initiating_needed`, `initiating_visit_on`, `started_on`, `note`, `created_by`, `qmb`, `device_kind`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $service, $practitionerId, json_encode($conditions, JSON_THROW_ON_ERROR), $consentOn, $consentHow, $explained ? 1 : 0,
                $consentOn === null ? null : $userId, $this->needsInitiatingVisit($pid, $startedOn, $service, $practitionerId) ? 1 : 0, $initiatingVisitOn, $startedOn, $note, $userId,
                $service === Service::APCM && $qmb ? 1 : 0, $service === Service::RTM && in_array($deviceKind, Service::RTM_KINDS, true) ? $deviceKind : null],
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

    /** A new initiating visit, without touching the consent: PCM's yearly one (MLN909188), or one recorded late. */
    public function recordInitiatingVisit(int $id, int $pid, string $on): void
    {
        QueryUtils::sqlStatementThrowException(
            'UPDATE `care_mgmt_enrollment` SET `initiating_visit_on` = ? WHERE `id` = ? AND `pid` = ?',
            [$on, $id, $pid],
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
     * Whether an enrollment starting on a date needs an initiating visit: when the service needs
     * one, and the patient wasn't seen within its look-back, by the billing practitioner where the
     * service says so (Service::initiatingLookBack, by the start's year).
     */
    private function needsInitiatingVisit(int $pid, string $day, string $service, int $practitionerId): bool
    {
        $rule = Service::initiatingLookBack($service, (int)substr($day, 0, 4));
        if ($rule['years'] === null || $rule['years'] === 0) {
            return $rule['years'] === 0;
        }
        $sql = 'SELECT COUNT(*) AS n FROM `form_encounter` WHERE `pid` = ? AND `date` < ? AND `date` >= DATE_SUB(?, INTERVAL ' . (int)$rule['years'] . ' YEAR)';
        $binds = [$pid, $day, $day];
        if ($rule['byPractitioner']) {
            $sql .= ' AND `provider_id` = ?';
            $binds[] = $practitionerId;
        }
        $row = QueryUtils::fetchRecords($sql, $binds)[0] ?? ['n' => 0];
        return (int)$row['n'] === 0;
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
            isset($r['device_kind']) && is_string($r['device_kind']) && $r['device_kind'] !== '' ? $r['device_kind'] : null,
        );
    }
}
