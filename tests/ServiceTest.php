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

use OpenHealthcare\CareManagement\Domain\Service;
use OpenHealthcare\CareManagement\Repository\EnrollmentRepository;
use PHPUnit\Framework\TestCase;

final class ServiceTest extends TestCase
{
    public function testEveryServiceLooksBackAtTheBillingPractitionersOwnVisits(): void
    {
        self::assertSame(['years' => 3, 'byPractitioner' => true], Service::initiatingLookBack(Service::APCM), 'APCM FAQ, question 1');
        // MLN909188 for CCM (PCM follows it), MLN909432 for BHI and CoCM: a visit with another practitioner doesn't count.
        foreach ([Service::CCM, Service::PCM, Service::BHI, Service::COCM] as $service) {
            self::assertSame(['years' => 1, 'byPractitioner' => true], Service::initiatingLookBack($service), $service);
        }
    }

    public function testEachServicesNeedIsWorkedOutOnReadUnderItsOwnRule(): void
    {
        $columns = EnrollmentRepository::columns();
        $practitioners = 'fe.`provider_id` = `care_mgmt_enrollment`.`practitioner_id`';
        foreach (Service::ALL as $service) {
            $years = $service === Service::APCM ? 3 : 1;
            self::assertMatchesRegularExpression(
                "/WHEN '$service' THEN NOT EXISTS \\(SELECT 1 FROM `form_encounter` AS fe WHERE [^)]*" . preg_quote($practitioners, '/') . "[^)]*INTERVAL $years YEAR\\)/",
                $columns,
                "$service: the billing practitioner's visits in the last $years year(s), whatever was stored at enrollment",
            );
        }
        self::assertStringEndsWith('ELSE `initiating_needed` END AS `initiating_needed`', $columns, 'what was stored, only for a service not known');
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
        self::assertSame(['base' => '99492', 'first' => 70], Service::cocmMonth(false));
        self::assertSame(['base' => '99493', 'first' => 60], Service::cocmMonth(true));
        self::assertSame('G0570', Service::apcmAddOn(Service::BHI, true), "general BHI's add-on doesn't depend on an episode");
        self::assertSame('G0569', Service::apcmAddOn(Service::COCM, true));
        self::assertSame('G0568', Service::apcmAddOn(Service::COCM, false));
    }
}
