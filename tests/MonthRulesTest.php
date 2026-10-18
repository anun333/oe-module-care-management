<?php

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Tests;

use OpenHealthcare\CareManagement\Domain\Enrollment;
use OpenHealthcare\CareManagement\Domain\Month;
use OpenHealthcare\CareManagement\Domain\MonthRules;
use OpenHealthcare\CareManagement\Domain\Service;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonthRulesTest extends TestCase
{
    /** @return iterable<string, array{string, int, int, list<array{code: string, units: int}>, list<array{code: string, units: int}>}> */
    public static function thresholds(): iterable
    {
        yield 'CCM 19 staff minutes' => [Service::CCM, 19, 0, [], []];
        yield 'CCM 20' => [Service::CCM, 20, 0, [['code' => '99490', 'units' => 1]], []];
        yield 'CCM 39' => [Service::CCM, 39, 0, [['code' => '99490', 'units' => 1]], []];
        yield 'CCM 40' => [Service::CCM, 40, 0, [['code' => '99490', 'units' => 1], ['code' => '99439', 'units' => 1]], []];
        yield 'CCM 60' => [Service::CCM, 60, 0, [['code' => '99490', 'units' => 1], ['code' => '99439', 'units' => 2]], []];
        yield 'CCM 15 staff + 10 practitioner' => [Service::CCM, 15, 10, [['code' => '99490', 'units' => 1]], []];
        yield 'CCM practitioner 30' => [Service::CCM, 0, 30, [['code' => '99490', 'units' => 1]], [['code' => '99491', 'units' => 1]]];
        yield 'CCM practitioner 60' => [Service::CCM, 0, 60, [['code' => '99490', 'units' => 1], ['code' => '99439', 'units' => 2]], [['code' => '99491', 'units' => 1], ['code' => '99437', 'units' => 1]]];
        yield 'PCM 29 staff' => [Service::PCM, 29, 0, [], []];
        yield 'PCM 30 staff' => [Service::PCM, 30, 0, [['code' => '99426', 'units' => 1]], []];
        yield 'PCM 90 staff' => [Service::PCM, 90, 0, [['code' => '99426', 'units' => 1], ['code' => '99427', 'units' => 2]], []];
        yield 'PCM practitioner time is not staff time' => [Service::PCM, 20, 30, [], [['code' => '99424', 'units' => 1]]];
        yield 'BHI 19' => [Service::BHI, 19, 0, [], []];
        yield 'BHI 45' => [Service::BHI, 45, 0, [['code' => '99484', 'units' => 1]], []];
    }

    /**
     * @param list<array{code: string, units: int}> $staff
     * @param list<array{code: string, units: int}> $practitioner
     */
    #[DataProvider('thresholds')]
    public function testTheCodesEachRouteMeets(string $service, int $staffMinutes, int $practitionerMinutes, array $staff, array $practitioner): void
    {
        $e = self::enrollment($service);
        $entries = [];
        if ($staffMinutes > 0) {
            $entries[] = self::entry($e->id, '2026-10-05', $staffMinutes, Service::STAFF);
        }
        if ($practitionerMinutes > 0) {
            $entries[] = self::entry($e->id, '2026-10-20', $practitionerMinutes, Service::PRACTITIONER);
        }
        $summary = MonthRules::summarize($e, $entries, [], self::october());

        $this->assertSame($staff, $summary['routes'][Service::STAFF]);
        $this->assertSame($practitioner, $summary['routes'][Service::PRACTITIONER] ?? []);
        $this->assertSame([], $summary['blockers']);
    }

    public function test99439IsCappedAtTwoAndSaysSo(): void
    {
        $e = self::enrollment(Service::CCM);
        $summary = MonthRules::summarize($e, [self::entry($e->id, '2026-10-05', 95, Service::STAFF)], [], self::october());
        $this->assertSame([['code' => '99490', 'units' => 1], ['code' => '99439', 'units' => 2]], $summary['routes'][Service::STAFF]);
        $this->assertTrue($summary['capped']);
        $this->assertFalse(MonthRules::summarize($e, [self::entry($e->id, '2026-10-05', 60, Service::STAFF)], [], self::october())['capped']);
    }

    public function testMinutesCountOnlyInTheirOwnMonthAndForTheirOwnEnrollment(): void
    {
        $e = self::enrollment(Service::CCM);
        $entries = [
            self::entry($e->id, '2026-09-30', 30, Service::STAFF),
            self::entry($e->id, '2026-10-01', 6, Service::STAFF),
            self::entry($e->id, '2026-10-14', 9, Service::STAFF),
            self::entry($e->id, '2026-10-31', 4, Service::STAFF),
            self::entry($e->id, '2026-11-01', 30, Service::STAFF),
            self::entry(99, '2026-10-10', 30, Service::STAFF), // the patient's BHI time: never counted as CCM time
        ];
        $summary = MonthRules::summarize($e, $entries, [], self::october());
        $this->assertSame(19, $summary['staff']);
        $this->assertSame([], $summary['routes'][Service::STAFF]);
        $this->assertSame(1, $summary['next'][Service::STAFF], 'one more minute for 99490');
    }

    public function testEachBlocker(): void
    {
        $month = self::october();
        $entries = static fn(Enrollment $e): array => [self::entry($e->id, '2026-10-05', 25, Service::STAFF)];
        $noConsent = self::enrollment(Service::CCM, consentOn: null);
        $partial = self::enrollment(Service::CCM, consentComplete: false);
        $oneCondition = self::enrollment(Service::CCM, conditions: ['E11.9']);
        $noVisit = self::enrollment(Service::CCM, initiatingNeeded: true);
        $visit = self::enrollment(Service::CCM, initiatingNeeded: true, initiatingVisitOn: '2026-09-15');

        $this->assertSame([MonthRules::NO_CONSENT], MonthRules::summarize($noConsent, $entries($noConsent), [], $month)['blockers']);
        $this->assertSame([MonthRules::NO_CONSENT], MonthRules::summarize($partial, $entries($partial), [], $month)['blockers']);
        $this->assertSame([MonthRules::TOO_FEW_CONDITIONS], MonthRules::summarize($oneCondition, $entries($oneCondition), [], $month)['blockers']);
        $this->assertSame([MonthRules::NO_INITIATING_VISIT], MonthRules::summarize($noVisit, $entries($noVisit), [], $month)['blockers']);
        $this->assertSame([], MonthRules::summarize($visit, $entries($visit), [], $month)['blockers']);
        $pcmOneCondition = self::enrollment(Service::PCM, conditions: ['I10']);
        $this->assertSame([], MonthRules::summarize($pcmOneCondition, [], [], $month)['blockers'], 'PCM needs only its principal condition');
    }

    public function testCcmAndPcmByTheSamePractitionerInTheSameMonthBlockBoth(): void
    {
        $month = self::october();
        $ccm = self::enrollment(Service::CCM, id: 1);
        $pcmSame = self::enrollment(Service::PCM, id: 2);
        $pcmOther = self::enrollment(Service::PCM, id: 3, practitionerId: 8);
        $pcmEnded = self::enrollment(Service::PCM, id: 4, stoppedOn: '2026-09-30');
        $bhi = self::enrollment(Service::BHI, id: 5);

        $this->assertSame([MonthRules::CCM_AND_PCM], MonthRules::summarize($ccm, [], [$pcmSame, $bhi], $month)['blockers']);
        $this->assertSame([MonthRules::CCM_AND_PCM], MonthRules::summarize($pcmSame, [], [$ccm], $month)['blockers']);
        $this->assertSame([], MonthRules::summarize($ccm, [], [$pcmOther, $pcmEnded, $bhi], $month)['blockers'], 'another practitioner, an ended PCM and BHI are all allowed');
    }

    public function testAStopTakesEffectAtTheEndOfItsMonth(): void
    {
        $stopped = self::enrollment(Service::CCM, stoppedOn: '2026-10-03');
        $this->assertTrue($stopped->activeIn(self::october()));
        $this->assertFalse($stopped->activeIn(Month::of(new \DateTimeImmutable('2026-11-15'))));
        $this->assertTrue(MonthRules::summarize($stopped, [], [], self::october())['stopsThisMonth']);
        $late = self::enrollment(Service::CCM, startedOn: '2026-11-02');
        $this->assertFalse($late->activeIn(self::october()));
        $this->assertTrue($late->activeIn(Month::of(new \DateTimeImmutable('2026-11-30'))));
    }

    public function testMonthsParseOnlyAsYearAndMonth(): void
    {
        $this->assertSame('2026-02', Month::parse('2026-02')?->key());
        $this->assertSame('2026-02-28', Month::parse('2026-02')?->last()->format('Y-m-d'));
        $this->assertNull(Month::parse('2026-13'));
        $this->assertNull(Month::parse('2026-2'));
        $this->assertNull(Month::parse(''));
    }

    private static function october(): Month
    {
        return Month::of(new \DateTimeImmutable('2026-10-18'));
    }

    /** @param list<string>|null $conditions */
    private static function enrollment(
        string $service,
        int $id = 1,
        int $practitionerId = 7,
        ?array $conditions = null,
        ?string $consentOn = '2026-09-01',
        bool $consentComplete = true,
        bool $initiatingNeeded = false,
        ?string $initiatingVisitOn = null,
        string $startedOn = '2026-09-01',
        ?string $stoppedOn = null,
    ): Enrollment {
        return new Enrollment(
            $id,
            42,
            $service,
            $practitionerId,
            $conditions ?? ['E11.9', 'I10'],
            $consentOn === null ? null : new \DateTimeImmutable($consentOn),
            $consentComplete,
            $initiatingNeeded,
            $initiatingVisitOn === null ? null : new \DateTimeImmutable($initiatingVisitOn),
            new \DateTimeImmutable($startedOn),
            $stoppedOn === null ? null : new \DateTimeImmutable($stoppedOn),
        );
    }

    /** @return array{enrollmentId: int, servedOn: \DateTimeImmutable, minutes: int, role: string} */
    private static function entry(int $enrollmentId, string $day, int $minutes, string $role): array
    {
        return ['enrollmentId' => $enrollmentId, 'servedOn' => new \DateTimeImmutable($day), 'minutes' => $minutes, 'role' => $role];
    }
}
