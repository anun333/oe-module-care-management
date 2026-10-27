<?php

/**
 * "Care management, not billed" as the Staff Worklist collects it. The tab is built on openhealthcare-common's
 * Worklist contract, so it's tested here without the Worklist installed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Tests;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\CareManagement\Domain\UnbilledMonths;
use OpenHealthcare\CareManagement\Worklist\UnbilledSource;
use OpenHealthcare\Common\Contract\Worklist\ItemKey;
use OpenHealthcare\Common\Contract\Worklist\WorkSource;
use OpenHealthcare\Common\Contract\Worklist\Worklist;
use PHPUnit\Framework\TestCase;

if (!class_exists(QueryUtils::class)) {
    require_once __DIR__ . '/Stubs/QueryUtils.php';
}

final class UnbilledSourceTest extends TestCase
{
    private const NOW = '2026-10-26 09:00:00';

    protected function setUp(): void
    {
        QueryUtils::$results = [];
        QueryUtils::$calls = [];
    }

    public function testTheTabIsAContractSourceWithItsOwnId(): void
    {
        $source = new UnbilledSource(new \DateTimeImmutable(self::NOW));
        self::assertInstanceOf(WorkSource::class, $source);
        self::assertSame('caremgmt', $source->id());
    }

    public function testNoEnrollmentMeansNoItemsAndNoPatientRead(): void
    {
        self::assertSame([], (new UnbilledSource(new \DateTimeImmutable(self::NOW)))->collect());
        self::assertNotSame([], QueryUtils::$calls, 'the months were read');
        self::assertStringNotContainsString('patient_data', implode(' ', array_column(QueryUtils::$calls, 'sql')));
    }

    public function testEachEnrollmentMonthHasAKeyTheContractTakes(): void
    {
        $september = new \DateTimeImmutable('2026-09-01');
        $key = new ItemKey(UnbilledSource::ID, 7, UnbilledMonths::ref(123456789012, $september));
        self::assertSame('caremgmt:7:123456789012202609', (string)$key);
        self::assertNotSame(UnbilledMonths::ref(12, $september), UnbilledMonths::ref(12, new \DateTimeImmutable('2026-08-01')), 'another month, another key');
    }

    public function testTheTabReachesAWorklistThroughTheContract(): void
    {
        $event = new class (new \DateTimeImmutable(self::NOW)) {
            /** @var list<array{object, string, string}> */
            public array $got = [];

            public function __construct(public readonly \DateTimeImmutable $now)
            {
            }

            public function contribute(object $source, string $label, string $ownerRole): void
            {
                $this->got[] = [$source, $label, $ownerRole];
            }
        };
        self::assertTrue(Worklist::contribute($event, new UnbilledSource(Worklist::now($event)), 'Care management, not billed', 'Billing practitioner'));
        self::assertSame(UnbilledSource::ID, $event->got[0][0]->id());
        self::assertSame(['Care management, not billed', 'Billing practitioner'], [$event->got[0][1], $event->got[0][2]]);
    }
}
