<?php

/**
 * The Care Management card on the patient dashboard: this month's minutes against each
 * enrollment's threshold, and what blocks billing. Core shows a card only to users who
 * pass its access check, here patients/med, the same as the module's pages.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Card;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Events\Patient\Summary\Card\CardModel;
use OpenHealthcare\CareManagement\Bootstrap;
use OpenHealthcare\CareManagement\Domain\Month;
use OpenHealthcare\CareManagement\Domain\MonthRules;
use OpenHealthcare\CareManagement\Labels;
use OpenHealthcare\CareManagement\Repository\EnrollmentRepository;
use OpenHealthcare\CareManagement\Repository\MonthRepository;
use OpenHealthcare\CareManagement\Repository\TimeRepository;
use OpenHealthcare\Common\OpenEmr;

final class CareManagementCard extends CardModel
{
    public function __construct(int $pid)
    {
        parent::__construct([
            'acl' => ['patients', 'med'],
            'initiallyCollapsed' => false,
            'add' => false,
            'edit' => false,
            'collapse' => true,
            'templateFile' => '@' . Bootstrap::TWIG_NAMESPACE . '/card.html.twig',
            'identifier' => 'care_management',
            'title' => xl('Care Management'),
            'templateVariables' => self::variables($pid),
        ]);
    }

    /** @return array<string, mixed> */
    private static function variables(int $pid): array
    {
        $month = Month::of(new \DateTimeImmutable('today'));
        $enrollments = (new EnrollmentRepository())->forPatient($pid);
        $times = new TimeRepository();
        $entries = $times->inMonth($month, [$pid]);
        $ids = array_map(static fn($e): int => $e->id, $enrollments);
        $complex = (new MonthRepository())->complex($ids, $month);
        $earlier = $times->hadTimeBefore($ids, $month);
        $rows = [];
        foreach ($enrollments as $e) {
            if (!$e->activeIn($month)) {
                continue;
            }
            $s = MonthRules::summarize($e, $entries, $enrollments, $month, $complex[$e->id] ?? false, $earlier[$e->id] ?? false);
            $rows[] = [
                'service' => Labels::service($e->service),
                'minutes' => $s['staff'] + $s['practitioner'],
                'threshold' => $s['threshold'],
                'codes' => implode(' / ', array_map(Labels::codes(...), array_values(array_filter($s['routes'])))),
                'met' => array_filter($s['routes']) !== [],
                'blockers' => array_map(Labels::blocker(...), $s['blockers']),
            ];
        }
        return [
            'rows' => $rows,
            'month' => $month->key(),
            'url' => AclMain::aclCheckCore('patients', 'med', '', 'write')
                ? OpenEmr::webRoot() . Bootstrap::MODULE_INSTALLATION_PATH . '/public/patient.php?pid=' . $pid
                : '',
        ];
    }
}
