<?php

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Tests;

use OpenHealthcare\CareManagement\Domain\Enrollment;
use OpenHealthcare\CareManagement\Domain\Month;
use OpenHealthcare\CareManagement\Domain\MonthRules;
use OpenHealthcare\CareManagement\Domain\Schedule;
use OpenHealthcare\CareManagement\Domain\Service;
use OpenHealthcare\CareManagement\Repository\DevicePeriodRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RPM and RTM as the CY 2026 final rule sets them (90 FR 49394-99), with the CY 2024 rule's
 * clarifications (88 FR 78882-84).
 */
final class RemoteMonitoringTest extends TestCase
{
    /** @return iterable<string, array{string, int, list<array{code: string, units: int}>}> */
    public static function minutes(): iterable
    {
        yield 'RPM 9 minutes' => [Service::RPM, 9, []];
        yield 'RPM 10: the first 10 minutes' => [Service::RPM, 10, [['code' => '99470', 'units' => 1]]];
        yield 'RPM 19' => [Service::RPM, 19, [['code' => '99470', 'units' => 1]]];
        yield 'RPM 20: the first 20, not added to 99470' => [Service::RPM, 20, [['code' => '99457', 'units' => 1]]];
        yield 'RPM 39' => [Service::RPM, 39, [['code' => '99457', 'units' => 1]]];
        yield 'RPM 40' => [Service::RPM, 40, [['code' => '99457', 'units' => 1], ['code' => '99458', 'units' => 1]]];
        yield 'RPM 65' => [Service::RPM, 65, [['code' => '99457', 'units' => 1], ['code' => '99458', 'units' => 2]]];
        yield 'RTM 10' => [Service::RTM, 10, [['code' => '98979', 'units' => 1]]];
        yield 'RTM 45' => [Service::RTM, 45, [['code' => '98980', 'units' => 1], ['code' => '98981', 'units' => 1]]];
    }

    /** @param list<array{code: string, units: int}> $codes */
    #[DataProvider('minutes')]
    public function testTreatmentManagementByTheCalendarMonthsMinutes(string $service, int $minutes, array $codes): void
    {
        $e = self::enrollment($service);
        // Staff and practitioner minutes both count ("clinical staff/physician/other qualified health care professional time", 88 FR 78882).
        $entries = [self::entry($e->id, '2026-10-05', intdiv($minutes, 2), Service::STAFF, true), self::entry($e->id, '2026-10-20', $minutes - intdiv($minutes, 2), Service::PRACTITIONER)];
        $s = MonthRules::summarize($e, $entries, [$e], self::october());
        self::assertSame($codes, $s['routes'][Service::STAFF]);
        self::assertSame([], $s['blockers']);
        self::assertSame([], $s['notes']);
    }

    public function testTheMinutesNeedALiveConversationInTheMonth(): void
    {
        $e = self::enrollment(Service::RPM);
        $quiet = MonthRules::summarize($e, [self::entry($e->id, '2026-10-05', 25, Service::STAFF)], [$e], self::october());
        self::assertSame([], $quiet['routes'][Service::STAFF]);
        self::assertSame([MonthRules::NO_INTERACTIVE], $quiet['notes'], '90 FR 49397: the codes "require a live, interactive communication"');
        $september = [self::entry($e->id, '2026-09-28', 5, Service::STAFF, true), self::entry($e->id, '2026-10-05', 25, Service::STAFF)];
        self::assertSame([MonthRules::NO_INTERACTIVE], MonthRules::summarize($e, $september, [$e], self::october())['notes'], "September's conversation isn't October's");
        $talked = [self::entry($e->id, '2026-10-05', 20, Service::STAFF), self::entry($e->id, '2026-10-09', 5, Service::STAFF, true)];
        self::assertSame([['code' => '99457', 'units' => 1]], MonthRules::summarize($e, $talked, [$e], self::october())['routes'][Service::STAFF], 'it counts toward the time');
        $short = MonthRules::summarize($e, [self::entry($e->id, '2026-10-05', 6, Service::STAFF)], [$e], self::october());
        self::assertSame([], $short['notes'], 'under 10 minutes: only minutes are missing');
        self::assertSame([4, '99470'], [$short['next'][Service::STAFF], $short['nextCode'][Service::STAFF]]);
        self::assertSame(10, $short['threshold']);
    }

    public function testDeviceDaysByThe30DayPeriodInTheMonthItEnds(): void
    {
        $e = self::enrollment(Service::RPM, startedOn: '2026-08-20');
        $periods = [self::period($e->id, '2026-08-20', 1), self::period($e->id, '2026-09-19', 16), self::period($e->id, '2026-10-19', 9)];
        $october = MonthRules::summarize($e, [], [$e], self::october(), devicePeriods: $periods);
        // Sept 19 + 29 days = Oct 18: 16 days is 99454, and set-up goes with it, the first period with 2 days (90 FR 49399).
        self::assertSame([['code' => '99453', 'units' => 1], ['code' => '99454', 'units' => 1]], $october['routes'][Service::STAFF]);
        self::assertSame(['2026-09-19', '2026-10-19'], array_map(static fn(array $p): string => $p['start']->format('Y-m-d'), $october['periods']));
        self::assertSame([], $october['periods'][1]['codes'], 'ends in November');
        $november = MonthRules::summarize($e, [], [$e], Month::parse('2026-11'), devicePeriods: $periods);
        self::assertSame([['code' => '99445', 'units' => 1]], $november['routes'][Service::STAFF], '2 to 15 days, and no second set-up');
        $september = MonthRules::summarize($e, [], [$e], Month::parse('2026-09'), devicePeriods: $periods);
        self::assertSame([], $september['routes'][Service::STAFF], 'one day of data: no code, no set-up');
    }

    public function testTwoPeriodsEndingInA31DayMonthAndDevicePlusTime(): void
    {
        $e = self::enrollment(Service::RPM, startedOn: '2026-09-02');
        $periods = [self::period($e->id, '2026-09-02', 20), self::period($e->id, '2026-10-02', 18)];
        $s = MonthRules::summarize($e, [self::entry($e->id, '2026-10-07', 22, Service::STAFF, true)], [$e], self::october(), devicePeriods: $periods);
        // Sept 2 to Oct 1 and Oct 2 to Oct 31: each billed once a 30-day period.
        self::assertSame([['code' => '99453', 'units' => 1], ['code' => '99454', 'units' => 2], ['code' => '99457', 'units' => 1]], $s['routes'][Service::STAFF]);
        $other = MonthRules::summarize($e, [], [$e], self::october(), devicePeriods: [self::period(99, '2026-09-20', 20)]);
        self::assertSame([], $other['routes'][Service::STAFF], "another enrollment's period isn't this one's");
    }

    public function testRtmsDeviceCodesFollowWhatItMonitors(): void
    {
        foreach (['respiratory' => ['98984', '98976'], 'musculoskeletal' => ['98985', '98977'], 'cbt' => ['98986', '98978']] as $kind => [$short, $full]) {
            $e = self::enrollment(Service::RTM, startedOn: '2026-09-01', deviceKind: $kind);
            $codes = static fn(int $days): array => array_column(MonthRules::summarize($e, [], [$e], self::october(),
                devicePeriods: [self::period($e->id, '2026-09-01', 5), self::period($e->id, '2026-10-01', $days)])['routes'][Service::STAFF], 'code');
            self::assertSame([$full], $codes(16), $kind);
            self::assertSame([$short], $codes(15), $kind);
            self::assertSame([], $codes(1), $kind);
        }
    }

    public function testOneRemoteMonitoringServiceAtATimeButAlongsideCareManagement(): void
    {
        $rpm = self::enrollment(Service::RPM, id: 1);
        $rtm = self::enrollment(Service::RTM, id: 2, practitionerId: 8, deviceKind: 'musculoskeletal');
        // 88 FR 78883: "RPM and RTM may not be billed together".
        self::assertContains(MonthRules::REMOTE_TWICE, MonthRules::summarize($rpm, [], [$rpm, $rtm], self::october())['blockers']);
        self::assertContains(MonthRules::REMOTE_TWICE, MonthRules::summarize($rtm, [], [$rpm, $rtm], self::october())['blockers']);
        $rpmElsewhere = self::enrollment(Service::RPM, id: 3, practitionerId: 8);
        self::assertContains(MonthRules::REMOTE_TWICE, MonthRules::summarize($rpm, [], [$rpm, $rpmElsewhere], self::october())['blockers'], 'only one practitioner a period');
        // "may be billed with RPM or RTM ... if the time or effort is not counted twice": CCM, PCM, BHI; APCM doesn't list them (89 FR 97895).
        foreach ([Service::CCM, Service::PCM, Service::BHI, Service::APCM] as $i => $service) {
            $other = self::enrollment($service, id: 10 + $i);
            self::assertSame([], MonthRules::summarize($rpm, [], [$rpm, $other], self::october())['blockers'], $service);
            self::assertNotContains(MonthRules::REMOTE_TWICE, MonthRules::summarize($other, [], [$rpm, $other], self::october())['blockers'], $service);
        }
        $stopped = self::enrollment(Service::RTM, id: 4, startedOn: '2026-06-01', stoppedOn: '2026-09-10', deviceKind: 'cbt');
        self::assertSame([], MonthRules::summarize($rpm, [], [$rpm, $stopped], self::october())['blockers'], 'stopped before October');
    }

    public function testRpmNeedsAnEstablishedPatientAndRtmNothingIn2026(): void
    {
        $rpm = self::enrollment(Service::RPM, initiatingNeeded: true);
        $s = MonthRules::summarize($rpm, [], [$rpm], self::october());
        self::assertContains(MonthRules::NO_INITIATING_VISIT, $s['blockers']);
        self::assertSame(3, $s['lookBack']);
        self::assertNull(MonthRules::summarize(self::enrollment(Service::RTM), [], [], self::october())['lookBack']);
        self::assertSame(0, MonthRules::summarize(self::enrollment(Service::RPM, startedOn: '2027-01-04'), [], [], Month::parse('2027-01'),
            schedule: Schedule::forYear(2027, true))['lookBack'], 'as proposed for 2027: an initiating visit for every enrollment');
        self::assertSame(3, MonthRules::summarize(self::enrollment(Service::RPM, startedOn: '2027-01-04'), [], [], Month::parse('2027-01'))['lookBack'],
            "by default 2027 is counted under 2026's rule");
    }

    public function testAPeriodMayNotOverlapAnotherOfThePatients(): void
    {
        $periods = [['periodStart' => new \DateTimeImmutable('2026-09-02')], ['periodStart' => new \DateTimeImmutable('2026-10-02')]];
        self::assertSame('2026-10-02', DevicePeriodRepository::overlapping($periods, new \DateTimeImmutable('2026-10-31'))?->format('Y-m-d'));
        self::assertSame('2026-09-02', DevicePeriodRepository::overlapping($periods, new \DateTimeImmutable('2026-08-04'))?->format('Y-m-d'), 'ends inside the next one');
        self::assertNull(DevicePeriodRepository::overlapping($periods, new \DateTimeImmutable('2026-11-01')), 'the day after the last one ends');
        self::assertNull(DevicePeriodRepository::overlapping($periods, new \DateTimeImmutable('2026-08-03')));
    }

    private static function october(): Month
    {
        return Month::parse('2026-10');
    }

    private static function enrollment(string $service, int $id = 1, int $practitionerId = 7, bool $initiatingNeeded = false, string $startedOn = '2026-09-01',
        ?string $stoppedOn = null, ?string $deviceKind = null): Enrollment
    {
        return new Enrollment($id, 42, $service, $practitionerId, ['I10'], new \DateTimeImmutable('2026-08-25'), true, $initiatingNeeded, null,
            new \DateTimeImmutable($startedOn), $stoppedOn === null ? null : new \DateTimeImmutable($stoppedOn), false,
            $deviceKind ?? ($service === Service::RTM ? 'respiratory' : null));
    }

    /** @return array{enrollmentId: int, servedOn: \DateTimeImmutable, minutes: int, role: string, interactive: bool} */
    private static function entry(int $enrollmentId, string $day, int $minutes, string $role, bool $interactive = false): array
    {
        return ['enrollmentId' => $enrollmentId, 'servedOn' => new \DateTimeImmutable($day), 'minutes' => $minutes, 'role' => $role, 'interactive' => $interactive];
    }

    /** @return array{enrollmentId: int, periodStart: \DateTimeImmutable, days: int} */
    private static function period(int $enrollmentId, string $start, int $days): array
    {
        return ['enrollmentId' => $enrollmentId, 'periodStart' => new \DateTimeImmutable($start), 'days' => $days];
    }
}
