<?php

/**
 * One calendar year's care management codes, minutes and rules, from data/codes.json.
 *
 * Each year's entry is "final" (from that year's physician fee schedule final rule) or
 * "proposed" (from its proposed rule). A proposed year is never used for a month: CMS
 * changes proposals in the final rule, and a month counted under one could hide codes the
 * final rule allows or show codes it doesn't. A month in a year with no final entry is
 * counted under the latest final year before it, and the pages say so, until a release
 * loads that year's final rule. Only tests read a proposed year ($allowProposed).
 *
 * A year's entry may repeat an earlier year ("from") with only its "changes".
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Domain;

final class Schedule
{
    public const FINAL = 'final';
    public const PROPOSED = 'proposed';

    /** @param array<string, mixed> $values the year's entry, with "from" resolved */
    private function __construct(
        public readonly int $year,
        public readonly string $status,
        public readonly string $source,
        private readonly array $values,
    ) {
    }

    /**
     * The schedule a calendar year is counted under: its own entry when final, else the latest
     * final year before it (or, for a year before any entry, the earliest).
     *
     * @param array<string, mixed>|null $data codes.json's contents (null: the module's own)
     */
    public static function forYear(int $year, bool $allowProposed = false, ?array $data = null): self
    {
        $data ??= self::bundled();
        $usable = self::usableYears($allowProposed, $data);
        if ($usable === []) {
            throw new \UnexpectedValueException('codes.json has no final year');
        }
        $picked = $usable[0];
        foreach ($usable as $y) {
            if ($y <= $year) {
                $picked = $y;
            }
        }
        $entry = $data['years'][(string)$picked];
        return new self($picked, (string)$entry['status'], (string)($entry['source'] ?? ''), self::resolve($data['years'], (string)$picked));
    }

    public static function forMonth(Month $month): self
    {
        return self::forYear((int)$month->first()->format('Y'));
    }

    /**
     * The years a month can be counted under, oldest first: the final ones, and the proposed
     * ones too when asked.
     *
     * @param array<string, mixed>|null $data
     * @return list<int>
     */
    public static function usableYears(bool $allowProposed = false, ?array $data = null): array
    {
        $data ??= self::bundled();
        $years = [];
        foreach ($data['years'] as $year => $entry) {
            if ($entry['status'] === self::FINAL || ($allowProposed && $entry['status'] === self::PROPOSED)) {
                $years[] = (int)$year;
            }
        }
        sort($years);
        return $years;
    }

    /** Whether this schedule is the month's own year's final one, or stands in for a year not loaded yet. */
    public function covers(Month $month): bool
    {
        return $this->year === (int)$month->first()->format('Y');
    }

    /**
     * @return array<string, array{base: string, first: int, addOn: ?string, each: ?int, max: ?int, counts: list<string>}>
     * @see Service::routes() for what each field means
     */
    public function routes(string $service): array
    {
        return match ($service) {
            Service::CCM, Service::PCM, Service::BHI => $this->values['routes'][$service],
            // Worked out in MonthRules: CoCM's codes depend on the month of the episode, APCM has no
            // time, and remote monitoring adds the days of data.
            Service::COCM, Service::APCM, Service::RPM, Service::RTM => [],
            default => throw new \InvalidArgumentException("Unknown care management service $service"),
        };
    }

    /** @return array{base: string, first: int, addOn: string, each: int} */
    public function complexCcm(): array
    {
        return $this->values['complexCcm'];
    }

    /**
     * CoCM's base code for the month of the episode: first = the minutes CMS gives it, from = the
     * minutes it is billable from under CPT's time rules (more than half; see codes.json's sources).
     *
     * @return array{base: string, first: int, from: int}
     */
    public function cocmMonth(bool $laterInEpisode): array
    {
        return $this->values['cocm'][$laterInEpisode ? 'laterMonth' : 'firstMonth'];
    }

    /** @return array{code: string, each: int, from: int} from: minutes past the base code's full time at which a unit starts */
    public function cocmAddOn(): array
    {
        return $this->values['cocm']['addOn'];
    }

    /** @return array{code: string, first: int, from: int} */
    public function cocmShort(): array
    {
        return $this->values['cocm']['short'];
    }

    public function apcmAddOn(string $service, bool $laterInEpisode): string
    {
        $addOns = $this->values['apcmAddOns'];
        return $service === Service::BHI ? $addOns['bhi'] : $addOns[$laterInEpisode ? 'cocmLaterMonth' : 'cocmFirstMonth'];
    }

    public function apcmCode(int $conditions, bool $qmb): string
    {
        $codes = $this->values['apcm'];
        if ($conditions >= 2) {
            return $qmb ? $codes['qmbTwoOrMore'] : $codes['twoOrMore'];
        }
        return $codes['oneOrNone'];
    }

    /**
     * RPM's or RTM's codes: set-up (once an episode, with at least its days of data in a 30-day
     * period), the device and data for a 30-day period by kind (RPM has one kind, "any"), 2 to 15
     * days or 16 and more, and treatment management by calendar month.
     *
     * @return array{setup: array{code: string, days: int},
     *               device: array<string, array{short: array{code: string, days: int}, full: array{code: string, days: int}}>,
     *               management: array{base: string, first: int, addOn: string, each: int, short: array{code: string, first: int}}}
     */
    public function remote(string $service): array
    {
        return $this->values['remote'][$service] ?? throw new \InvalidArgumentException("No remote monitoring codes for $service");
    }

    /**
     * Years back a visit with the billing practitioner spares an initiating visit: null when the
     * service needs none, 0 when every enrollment needs its own.
     */
    public function lookBack(string $service): ?int
    {
        $rules = $this->values['rules']['lookBack'];
        if (!array_key_exists($service, $rules)) {
            throw new \InvalidArgumentException("Unknown care management service $service");
        }
        return $rules[$service] === null ? null : (int)$rules[$service];
    }

    /** Calendar months of PCM an initiating visit covers before another is needed. */
    public function pcmRenewalMonths(): int
    {
        return (int)$this->values['rules']['pcmRenewalMonths'];
    }

    /**
     * Every code an enrollment in the service can meet under this year, for matching what was
     * billed. No code belongs to two services.
     *
     * @return list<string>
     */
    public function codes(string $service): array
    {
        $codes = [];
        foreach ($this->routes($service) as $route) {
            $codes[] = $route['base'];
            if ($route['addOn'] !== null) {
                $codes[] = $route['addOn'];
            }
        }
        $more = match ($service) {
            Service::CCM => [$this->complexCcm()['base'], $this->complexCcm()['addOn']],
            Service::BHI => [$this->apcmAddOn(Service::BHI, false)],
            Service::COCM => [$this->cocmMonth(false)['base'], $this->cocmMonth(true)['base'], $this->cocmAddOn()['code'], $this->cocmShort()['code'],
                $this->apcmAddOn(Service::COCM, false), $this->apcmAddOn(Service::COCM, true)],
            Service::APCM => [$this->apcmCode(1, false), $this->apcmCode(2, false), $this->apcmCode(2, true)],
            Service::RPM, Service::RTM => self::remoteCodes($this->remote($service)),
            default => [],
        };
        return array_values(array_unique([...$codes, ...$more]));
    }

    /**
     * @param array<string, mixed> $r
     * @return list<string>
     */
    private static function remoteCodes(array $r): array
    {
        $codes = [$r['setup']['code']];
        foreach ($r['device'] as $kind) {
            $codes[] = $kind['short']['code'];
            $codes[] = $kind['full']['code'];
        }
        return [...$codes, $r['management']['short']['code'], $r['management']['base'], $r['management']['addOn']];
    }

    /**
     * A year's entry with the year it repeats ("from") filled in under its "changes".
     *
     * @param array<string, array<string, mixed>> $years
     * @return array<string, mixed>
     */
    private static function resolve(array $years, string $year, int $depth = 0): array
    {
        $entry = $years[$year] ?? throw new \UnexpectedValueException("codes.json has no year $year");
        if (!isset($entry['from'])) {
            return $entry;
        }
        if ($depth > count($years)) {
            throw new \UnexpectedValueException("codes.json's year $year repeats itself");
        }
        return array_replace_recursive(self::resolve($years, (string)$entry['from'], $depth + 1), $entry['changes'] ?? []);
    }

    /**
     * data/codes.json, read once.
     *
     * @return array<string, mixed>
     */
    public static function bundled(): array
    {
        static $data = null;
        return $data ??= json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/data/codes.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
