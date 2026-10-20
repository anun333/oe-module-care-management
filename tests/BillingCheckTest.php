<?php

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Tests;

use OpenHealthcare\CareManagement\Domain\BillingCheck;
use OpenHealthcare\CareManagement\Domain\MonthRules;
use OpenHealthcare\CareManagement\Domain\Service;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BillingCheckTest extends TestCase
{
    /** 40 minutes of CCM staff time: 99490 and one 99439; or, by the practitioner's 30, 99491. */
    private const CCM_40 = [
        Service::STAFF => [['code' => '99490', 'units' => 1], ['code' => '99439', 'units' => 1]],
        Service::PRACTITIONER => [['code' => '99491', 'units' => 1]],
    ];

    /** @return iterable<string, array{array<string, list<array{code: string, units: int}>>, list<string>, array<string, int>, bool, string, array<string, int>}> */
    public static function cases(): iterable
    {
        yield 'billed as met' => [self::CCM_40, [], ['99490' => 1, '99439' => 1], true, BillingCheck::BILLED, []];
        yield 'billed less than met is still within it' => [self::CCM_40, [], ['99490' => 1], true, BillingCheck::BILLED, []];
        yield "the other route's code" => [self::CCM_40, [], ['99491' => 1], true, BillingCheck::BILLED, []];
        yield 'an add-on unit too many' => [self::CCM_40, [], ['99490' => 1, '99439' => 2], true, BillingCheck::BEYOND, ['99439' => 1]];
        yield 'both routes billed: the closer one decides' => [self::CCM_40, [], ['99490' => 1, '99491' => 1], true, BillingCheck::BEYOND, ['99491' => 1]];
        yield 'billed with nothing met' => [[Service::STAFF => [], Service::PRACTITIONER => []], [], ['99490' => 1], true, BillingCheck::BEYOND, ['99490' => 1]];
        yield 'billed though consent is missing' => [self::CCM_40, [MonthRules::NO_CONSENT], ['99490' => 1], true, BillingCheck::BLOCKED, []];
        yield 'met, the month over, nothing billed' => [self::CCM_40, [], [], true, BillingCheck::NOT_BILLED, []];
        yield 'met, the month still running' => [self::CCM_40, [], [], false, BillingCheck::NOT_BILLED_YET, []];
        yield 'met but blocked, nothing billed' => [self::CCM_40, [MonthRules::NO_CONSENT], [], true, BillingCheck::NOTHING, []];
        yield 'nothing met, nothing billed' => [[Service::STAFF => []], [], [], true, BillingCheck::NOTHING, []];
        yield 'a zero-unit line is no billing' => [self::CCM_40, [], ['99490' => 0], true, BillingCheck::NOT_BILLED, []];
    }

    /**
     * @param array<string, list<array{code: string, units: int}>> $routes
     * @param list<string> $blockers
     * @param array<string, int> $billed
     * @param array<string, int> $beyond
     */
    #[DataProvider('cases')]
    public function testCompareWhatWasBilledWithWhatWasMet(array $routes, array $blockers, array $billed, bool $monthOver, string $status, array $beyond): void
    {
        self::assertSame(['status' => $status, 'beyond' => $beyond], BillingCheck::check($routes, $blockers, $billed, $monthOver));
    }

    public function testEachServiceOwnsItsCodesAndNoCodeBelongsToTwo(): void
    {
        self::assertSame(['99490', '99439', '99491', '99437', '99487', '99489'], Service::codes(Service::CCM));
        self::assertSame(['99426', '99427', '99424', '99425'], Service::codes(Service::PCM));
        self::assertSame(['99484', 'G0570'], Service::codes(Service::BHI));
        self::assertSame(['99492', '99493', '99494', 'G2214', 'G0568', 'G0569'], Service::codes(Service::COCM));
        self::assertSame(['G0556', 'G0557', 'G0558'], Service::codes(Service::APCM));
        $all = array_merge(...array_map(Service::codes(...), Service::ALL));
        self::assertSame(count($all), count(array_unique($all)), 'a billed code tells which enrollment it belongs to');
    }

    public function testAPatientsBilledCodesAreSplitByService(): void
    {
        $billed = ['99439' => 1, 'G0570' => 1, '99490' => 1, '99484' => 1, '99213' => 1];
        self::assertSame(['99490' => 1, '99439' => 1], BillingCheck::forService($billed, Service::CCM), 'in the service\'s order, the base code first');
        self::assertSame(['99484' => 1, 'G0570' => 1], BillingCheck::forService($billed, Service::BHI));
        self::assertSame([], BillingCheck::forService($billed, Service::APCM));
    }
}
