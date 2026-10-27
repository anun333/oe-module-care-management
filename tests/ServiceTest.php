<?php

/**
 * Service's rules that aren't month arithmetic: how far back a visit spares the patient an
 * initiating visit, and the codes coming from data/codes.json with the CPT notice.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Tests;

use OpenHealthcare\CareManagement\Domain\Schedule;
use OpenHealthcare\CareManagement\Domain\Service;
use OpenHealthcare\CareManagement\Repository\EnrollmentRepository;
use PHPUnit\Framework\TestCase;

final class ServiceTest extends TestCase
{
    public function testEveryServiceLooksBackAtTheBillingPractitionersOwnVisits(): void
    {
        self::assertSame(['years' => 3, 'byPractitioner' => true], Service::initiatingLookBack(Service::APCM, 2026), 'APCM FAQ, question 1');
        // MLN909188 for CCM (PCM follows it), MLN909432 for BHI and CoCM: a visit with another practitioner doesn't count.
        foreach ([Service::CCM, Service::PCM, Service::BHI, Service::COCM] as $service) {
            self::assertSame(['years' => 1, 'byPractitioner' => true], Service::initiatingLookBack($service, 2026), $service);
        }
        // RPM only for an established patient (88 FR 78883-84; a new patient is one not seen in 3 years, Claims Processing Manual 12, 30.6.7); RTM has no such rule.
        self::assertSame(['years' => 3, 'byPractitioner' => true], Service::initiatingLookBack(Service::RPM, 2026));
        self::assertSame(['years' => null, 'byPractitioner' => true], Service::initiatingLookBack(Service::RTM, 2026));
    }

    public function testA2027StartIsCountedUnder2026UntilTheFinalRuleIsLoaded(): void
    {
        self::assertSame(['years' => 3, 'byPractitioner' => true], Service::initiatingLookBack(Service::RPM, 2027), "the proposed rule's initiating visit isn't used");
        self::assertSame(['years' => null, 'byPractitioner' => true], Service::initiatingLookBack(Service::RTM, 2027));
        // Read as proposed (91 FR 43892): every RPM or RTM enrollment would need its own initiating visit.
        self::assertSame(['years' => 0, 'byPractitioner' => true], Service::initiatingLookBack(Service::RPM, 2027, true));
        self::assertSame(['years' => 0, 'byPractitioner' => true], Service::initiatingLookBack(Service::RTM, 2027, true));
        self::assertSame(['years' => 3, 'byPractitioner' => true], Service::initiatingLookBack(Service::RPM, 2026, true), '2026 starts keep 2026 rules');
    }

    public function testEachServicesNeedIsWorkedOutOnReadUnderItsOwnRule(): void
    {
        $columns = EnrollmentRepository::columns();
        $practitioners = 'fe.`provider_id` = `care_mgmt_enrollment`.`practitioner_id`';
        foreach ([Service::CCM, Service::PCM, Service::BHI, Service::COCM, Service::APCM, Service::RPM] as $service) {
            $years = in_array($service, [Service::APCM, Service::RPM], true) ? 3 : 1;
            self::assertMatchesRegularExpression(
                "/WHEN '$service' THEN NOT EXISTS \\(SELECT 1 FROM `form_encounter` AS fe WHERE [^)]*" . preg_quote($practitioners, '/') . "[^)]*INTERVAL $years YEAR\\)/",
                $columns,
                "$service: the billing practitioner's visits in the last $years year(s), whatever was stored at enrollment",
            );
        }
        self::assertStringContainsString("WHEN 'rtm' THEN 0 ", $columns, 'RTM needs no initiating visit in 2026');
        self::assertStringNotContainsString('YEAR(', $columns, 'one rule for every start year while 2026 is the only final year');
        self::assertStringEndsWith('ELSE `initiating_needed` END AS `initiating_needed`', $columns, 'what was stored, only for a service not known');
    }

    public function testAYearsChangeOfRuleGoesByTheYearTheEnrollmentStarted(): void
    {
        // As release day will have it if the final rule keeps the proposal: 2027 read as if final.
        $columns = EnrollmentRepository::columns(true);
        self::assertMatchesRegularExpression("/WHEN 'rpm' THEN CASE WHEN YEAR\\(`care_mgmt_enrollment`\\.`started_on`\\) >= 2027 THEN 1 ELSE NOT EXISTS \\([^)]*INTERVAL 3 YEAR\\)\\) END WHEN 'rtm'/", $columns);
        self::assertStringContainsString("WHEN 'rtm' THEN CASE WHEN YEAR(`care_mgmt_enrollment`.`started_on`) >= 2027 THEN 1 ELSE 0 END", $columns);
        self::assertMatchesRegularExpression("/WHEN 'ccm' THEN NOT EXISTS \\([^)]*INTERVAL 1 YEAR\\)\\) WHEN 'pcm'/", $columns, "CCM's rule doesn't change, so no CASE by year");
    }

    public function testEveryCodeComesFromTheDataFileWithALabelAndTheCptNotice(): void
    {
        $data = json_decode((string)file_get_contents(dirname(__DIR__) . '/data/codes.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('CPT is a registered trademark of the American Medical Association', Service::cptNotice());
        self::assertSame($data['terms'], Service::cptNotice());
        foreach (Service::ALL as $service) {
            foreach (Service::codes($service) as $code) {
                self::assertArrayHasKey($code, $data['labels'], "$code has a label in our words");
            }
        }
        $s = Schedule::forYear(2026);
        self::assertSame(['base' => '99492', 'first' => 70], $s->cocmMonth(false));
        self::assertSame(['base' => '99493', 'first' => 60], $s->cocmMonth(true));
        self::assertSame('G0570', $s->apcmAddOn(Service::BHI, true), "general BHI's add-on doesn't depend on an episode");
        self::assertSame('G0569', $s->apcmAddOn(Service::COCM, true));
        self::assertSame('G0568', $s->apcmAddOn(Service::COCM, false));
    }
}
