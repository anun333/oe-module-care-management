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

    public function testComplexCcmNeedsTheDecisionMakingAndSixtyMinutes(): void
    {
        $e = self::enrollment(Service::CCM);
        $codes = static fn(int $staff, int $practitioner = 0, bool $complex = true): ?array => MonthRules::summarize($e,
            array_values(array_filter([$staff > 0 ? self::entry(1, '2026-10-05', $staff, Service::STAFF) : null,
                $practitioner > 0 ? self::entry(1, '2026-10-06', $practitioner, Service::PRACTITIONER) : null])),
            [], self::october(), $complex)['routes'][Service::COMPLEX] ?? null;
        $this->assertNull($codes(90, 0, false), 'without the decision-making there is no complex route');
        $this->assertSame([], $codes(59));
        $this->assertSame([['code' => '99487', 'units' => 1]], $codes(60));
        $this->assertSame([['code' => '99487', 'units' => 1]], $codes(89));
        $this->assertSame([['code' => '99487', 'units' => 1], ['code' => '99489', 'units' => 1]], $codes(90));
        $this->assertSame([['code' => '99487', 'units' => 1]], $codes(40, 20), 'the practitioner\'s time counts toward it');
    }

    public function testCollaborativeCareByMonthOfTheEpisode(): void
    {
        $e = self::enrollment(Service::COCM, conditions: ['F32.1']);
        $staff = static fn(int $minutes, bool $later = false, int $practitioner = 0): array => MonthRules::summarize($e,
            array_values(array_filter([self::entry(1, '2026-10-05', $minutes, Service::STAFF),
                $practitioner > 0 ? self::entry(1, '2026-10-06', $practitioner, Service::PRACTITIONER) : null])),
            [], self::october(), false, $later)['routes'][Service::STAFF];
        $this->assertSame([], $staff(29));
        $this->assertSame([['code' => 'G2214', 'units' => 1]], $staff(30), 'the first 30 minutes when 99492 isn\'t met');
        $this->assertSame([['code' => 'G2214', 'units' => 1]], $staff(69));
        $this->assertSame([['code' => '99492', 'units' => 1]], $staff(70));
        $this->assertSame([['code' => '99492', 'units' => 1], ['code' => '99494', 'units' => 1]], $staff(100));
        $this->assertSame([['code' => 'G2214', 'units' => 1]], $staff(59, true));
        $this->assertSame([['code' => '99493', 'units' => 1]], $staff(60, true), 'a later month of the episode');
        $this->assertSame([['code' => '99493', 'units' => 1], ['code' => '99494', 'units' => 2]], $staff(120, true));
        $this->assertSame([['code' => 'G2214', 'units' => 1]], $staff(40, false, 40), 'the practitioner\'s own time is not the care manager\'s');
        $this->assertSame(70, MonthRules::summarize($e, [], [], self::october())['threshold']);
        $this->assertSame(60, MonthRules::summarize($e, [], [], self::october(), false, true)['threshold']);
    }

    public function testApcmByConditionsAndQmbWithNoTime(): void
    {
        $code = static fn(array $conditions, bool $qmb = false): string => MonthRules::summarize(self::enrollment(Service::APCM, conditions: $conditions, qmb: $qmb),
            [], [], self::october())['routes'][Service::APCM_CODE][0]['code'];
        $this->assertSame('G0556', $code([]));
        $this->assertSame('G0556', $code(['I10']));
        $this->assertSame('G0557', $code(['I10', 'E11.9']));
        $this->assertSame('G0558', $code(['I10', 'E11.9'], true));
        $this->assertSame('G0556', $code(['I10'], true), 'G0558 needs two or more conditions too');
        $s = MonthRules::summarize(self::enrollment(Service::APCM), [], [], self::october());
        $this->assertNull($s['threshold']);
        $this->assertSame([], $s['blockers']);
    }

    public function testApcmAddOnsForTheSamePractitioner(): void
    {
        $apcm = self::enrollment(Service::APCM, id: 1);
        $cocm = self::enrollment(Service::COCM, id: 2, conditions: ['F32.1']);
        $bhi = self::enrollment(Service::BHI, id: 3, conditions: ['F41.1']);
        $addOn = static fn(Enrollment $e, array $entries, array $others, bool $later = false): ?array
            => MonthRules::summarize($e, $entries, $others, self::october(), false, $later)['routes'][Service::ADD_ON] ?? null;
        $this->assertSame([['code' => 'G0568', 'units' => 1]], $addOn($cocm, [self::entry(2, '2026-10-05', 10, Service::STAFF)], [$apcm, $cocm]), 'no minutes needed');
        $this->assertSame([['code' => 'G0569', 'units' => 1]], $addOn($cocm, [self::entry(2, '2026-10-05', 10, Service::STAFF)], [$apcm, $cocm], true));
        $this->assertSame([['code' => 'G0570', 'units' => 1]], $addOn($bhi, [self::entry(3, '2026-10-05', 5, Service::STAFF)], [$apcm, $bhi]));
        $this->assertNull($addOn($cocm, [], [$apcm, $cocm]), 'nothing recorded this month, no add-on');
        $other = self::enrollment(Service::APCM, id: 1, practitionerId: 8);
        $this->assertNull($addOn($cocm, [self::entry(2, '2026-10-05', 10, Service::STAFF)], [$other, $cocm]), 'only for the practitioner billing APCM');
    }

    public function testCocmWithBhiAndApcmWithCcmBlock(): void
    {
        $cocm = self::enrollment(Service::COCM, id: 1, conditions: ['F32.1']);
        $bhi = self::enrollment(Service::BHI, id: 2, practitionerId: 9, conditions: ['F41.1']);
        $this->assertContains(MonthRules::COCM_AND_BHI, MonthRules::summarize($cocm, [], [$cocm, $bhi], self::october())['blockers']);
        $this->assertContains(MonthRules::COCM_AND_BHI, MonthRules::summarize($bhi, [], [$cocm, $bhi], self::october())['blockers'], 'whoever bills them');
        $apcm = self::enrollment(Service::APCM, id: 3);
        $ccm = self::enrollment(Service::CCM, id: 4);
        $this->assertContains(MonthRules::APCM_AND_CCM_PCM, MonthRules::summarize($apcm, [], [$apcm, $ccm], self::october())['blockers']);
        $this->assertContains(MonthRules::APCM_AND_CCM_PCM, MonthRules::summarize($ccm, [], [$apcm, $ccm], self::october())['blockers']);
        $ccmElsewhere = self::enrollment(Service::CCM, id: 5, practitionerId: 8);
        $this->assertNotContains(MonthRules::APCM_AND_CCM_PCM, MonthRules::summarize($ccmElsewhere, [], [$apcm, $ccmElsewhere], self::october())['blockers'],
            'another practitioner may bill CCM');
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
        bool $qmb = false,
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
            $qmb,
        );
    }

    /** @return array{enrollmentId: int, servedOn: \DateTimeImmutable, minutes: int, role: string} */
    private static function entry(int $enrollmentId, string $day, int $minutes, string $role): array
    {
        return ['enrollmentId' => $enrollmentId, 'servedOn' => new \DateTimeImmutable($day), 'minutes' => $minutes, 'role' => $role];
    }
}
