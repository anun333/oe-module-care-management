<?php

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Tests;

use OpenHealthcare\CareManagement\Domain\Month;
use OpenHealthcare\CareManagement\Domain\Schedule;
use OpenHealthcare\CareManagement\Domain\Service;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase
{
    public function testAMonthIsCountedUnderItsOwnYearOnlyWhenThatYearIsFinal(): void
    {
        $october = Schedule::forMonth(Month::parse('2026-10'));
        self::assertSame(2026, $october->year);
        self::assertSame(Schedule::FINAL, $october->status);
        self::assertTrue($october->covers(Month::parse('2026-10')));

        // 2027's entry is the proposed rule: never used for a month, so January 2027 is counted under 2026 and says so.
        $january = Schedule::forMonth(Month::parse('2027-01'));
        self::assertSame(2026, $january->year);
        self::assertFalse($january->covers(Month::parse('2027-01')));
        self::assertSame([2026], Schedule::usableYears());
        self::assertSame([2026, 2027], Schedule::usableYears(true));

        self::assertSame(2026, Schedule::forYear(2019)->year, 'a year before any entry: the earliest');
    }

    public function testTheProposed2027EntryChangesOnlyRemoteMonitoringsInitiatingVisit(): void
    {
        $proposed = Schedule::forYear(2027, true);
        self::assertSame(2027, $proposed->year);
        self::assertSame(Schedule::PROPOSED, $proposed->status);
        self::assertStringContainsString('FR 2026-14327, 91 FR 43842', $proposed->source);
        $final = Schedule::forYear(2026);
        foreach (Service::ALL as $service) {
            self::assertSame($final->codes($service), $proposed->codes($service), "$service: the proposed rule changes no code");
            $expected = in_array($service, Service::REMOTE, true) ? 0 : $final->lookBack($service);
            self::assertSame($expected, $proposed->lookBack($service), $service);
        }
        self::assertSame($final->remote(Service::RPM), $proposed->remote(Service::RPM), 'nor any day or minute');
        self::assertSame($final->cocmMonth(false), $proposed->cocmMonth(false), "CoCM's minutes stay");
        self::assertSame($final->pcmRenewalMonths(), $proposed->pcmRenewalMonths());
    }

    public function testAYearMadeFinalIsUsedForItsMonthsAndRepeatsTheYearItNames(): void
    {
        $data = Schedule::bundled();
        $data['years']['2027']['status'] = Schedule::FINAL;
        $data['years']['2027']['changes']['cocm']['laterMonth']['from'] = 50;
        $s = Schedule::forYear(2027, false, $data);
        self::assertSame(2027, $s->year);
        self::assertSame(['base' => '99493', 'first' => 60, 'from' => 50], $s->cocmMonth(true), 'its change');
        self::assertSame(['base' => '99492', 'first' => 70, 'from' => 36], $s->cocmMonth(false), "the rest is 2026's");
        self::assertSame(0, $s->lookBack(Service::RPM));
        self::assertSame(2027, Schedule::forYear(2031, false, $data)->year, 'a later year: the latest final one');
        self::assertSame(2026, Schedule::forYear(2026, false, $data)->year);
    }

    public function testAYearThatRepeatsItselfIsRefused(): void
    {
        $data = Schedule::bundled();
        $data['years']['2026']['from'] = '2027';
        $data['years']['2027']['status'] = Schedule::FINAL;
        $this->expectException(\UnexpectedValueException::class);
        Schedule::forYear(2027, false, $data);
    }

    public function testRemoteMonitoringCodesAsTheCy2026FinalRuleSetThem(): void
    {
        $s = Schedule::forYear(2026);
        $rpm = $s->remote(Service::RPM);
        // 90 FR 49397: 2 to 15 days or 16 to 30, "not additive"; 10 minutes or 20, then each further 20.
        self::assertSame(['short' => ['code' => '99445', 'days' => 2], 'full' => ['code' => '99454', 'days' => 16]], $rpm['device']['any']);
        self::assertSame(['base' => '99457', 'first' => 20, 'addOn' => '99458', 'each' => 20, 'short' => ['code' => '99470', 'first' => 10]], $rpm['management']);
        // 90 FR 49399: set-up "now requires 2 days of monitoring in a 30-day period ... as opposed to 16".
        self::assertSame(['code' => '99453', 'days' => 2], $rpm['setup']);
        $rtm = $s->remote(Service::RTM);
        self::assertSame(Service::RTM_KINDS, array_keys($rtm['device']));
        // 98984 and 98985 "are based on the existing codes 98976 and 98977" (90 FR 49398): breathing and muscles and joints.
        self::assertSame(['98984', '98976'], [$rtm['device']['respiratory']['short']['code'], $rtm['device']['respiratory']['full']['code']]);
        self::assertSame(['98985', '98977'], [$rtm['device']['musculoskeletal']['short']['code'], $rtm['device']['musculoskeletal']['full']['code']]);
        self::assertSame(['98986', '98978'], [$rtm['device']['cbt']['short']['code'], $rtm['device']['cbt']['full']['code']]);
        self::assertSame('98975', $rtm['setup']['code']);
        self::assertSame(12, $s->pcmRenewalMonths(), 'MLN909188: "After 1 year, we require another initial visit"');
    }
}
