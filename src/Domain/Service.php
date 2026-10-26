<?php

/**
 * The care management services the module covers, with CMS's codes and monthly time
 * thresholds (MLN909188, the CCM FAQ, MLN909432 and the CY 2025-2026 PFS rules).
 * Each add-on unit needs its full time. 99439's limit of two a month is CPT's, as
 * commonly cited; CMS's booklet and FAQ don't state it.
 *
 * 0.2.0, from CMS's documents as read 2026-10-19:
 *  - complex CCM: 99487 for the first 60 minutes of clinical staff time (the billing
 *    practitioner's counts when not used for 99491), 99489 for each additional 30, in a
 *    month the billing practitioner made moderate or high complexity decisions (MLN909188);
 *  - psychiatric collaborative care (CoCM): 99492 for the first 70 minutes of behavioral
 *    health care manager time in the first month of an episode, 99493 for the first 60 in a
 *    later month, 99494 for each additional 30, and G2214 for the first 30 when the other
 *    threshold isn't met (MLN909432). CMS says only "CPT time rules apply" and publishes no
 *    lower figures, so full minutes are used here. A new episode starts after 6 calendar
 *    months without CoCM (CY 2017 PFS rule, 81 FR 80241);
 *  - APCM: G0556 (one chronic condition or none), G0557 (two or more), G0558 (a Qualified
 *    Medicare Beneficiary with two or more), once a month, with no time requirement
 *    (CY 2025 PFS rule, 89 FR 97868-71), and in 2026 the add-ons G0568 (CoCM, first month),
 *    G0569 (CoCM, later months) and G0570 (general BHI), also with no time requirement, for
 *    the practitioner billing APCM that month (CY 2026 PFS rule, 90 FR 49469-71).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Domain;

final class Service
{
    public const CCM = 'ccm';
    public const PCM = 'pcm';
    public const BHI = 'bhi';
    public const COCM = 'cocm';
    public const APCM = 'apcm';
    public const ALL = [self::CCM, self::PCM, self::BHI, self::COCM, self::APCM];

    /** Routes beyond the staff and practitioner ones: complex CCM, APCM itself, and an APCM add-on. */
    public const COMPLEX = 'complex';
    public const APCM_CODE = 'apcm_code';
    public const ADD_ON = 'add_on';

    /** Months without CoCM after which a new episode, and a new first month, begins. */
    public const COCM_NEW_EPISODE_MONTHS = 6;

    public const STAFF = 'staff';
    public const PRACTITIONER = 'practitioner';

    /**
     * The routes a month's time can be billed by: for clinical staff time and for the billing
     * practitioner's own. first = minutes for the base code; each = minutes per add-on unit;
     * max = most add-on units a month (null: none stated); counts = whose minutes count. CCM's
     * practitioner time counts toward the staff codes when it isn't used for 99491 (MLN909188).
     * The codes and minutes are in data/codes.json, with the CPT notice.
     *
     * @return array<string, array{base: string, first: int, addOn: ?string, each: ?int, max: ?int, counts: list<string>}>
     */
    public static function routes(string $service): array
    {
        return match ($service) {
            self::CCM, self::PCM, self::BHI => self::data()['routes'][$service],
            // Both are worked out in MonthRules: CoCM's codes depend on the month of the episode, and APCM has no time.
            self::COCM, self::APCM => [],
            default => throw new \InvalidArgumentException("Unknown care management service $service"),
        };
    }

    /** @return array{base: string, first: int, addOn: string, each: int} complex CCM's codes and minutes */
    public static function complexCcm(): array
    {
        return self::data()['complexCcm'];
    }

    /** @return array{base: string, first: int} CoCM's code and minutes in the first month of an episode, or in a later one */
    public static function cocmMonth(bool $laterInEpisode): array
    {
        return self::data()['cocm'][$laterInEpisode ? 'laterMonth' : 'firstMonth'];
    }

    /** @return array{code: string, each: int} CoCM's add-on code and minutes per unit */
    public static function cocmAddOn(): array
    {
        return self::data()['cocm']['addOn'];
    }

    /** @return array{code: string, first: int} CoCM's code when the month's threshold isn't met */
    public static function cocmShort(): array
    {
        return self::data()['cocm']['short'];
    }

    /** The APCM add-on a CoCM or BHI enrollment carries: CoCM's by month of the episode, or general BHI's. */
    public static function apcmAddOn(string $service, bool $laterInEpisode): string
    {
        $addOns = self::data()['apcmAddOns'];
        return $service === self::BHI ? $addOns['bhi'] : $addOns[$laterInEpisode ? 'cocmLaterMonth' : 'cocmFirstMonth'];
    }

    /**
     * Every code an enrollment in the service can meet, for matching what was billed: each route's
     * base and add-on codes, complex CCM's, CoCM's by month of the episode, APCM's by conditions, and
     * the APCM add-on a CoCM or BHI enrollment can carry. No code belongs to two services.
     *
     * @return list<string>
     */
    public static function codes(string $service): array
    {
        $codes = [];
        foreach (self::routes($service) as $route) {
            $codes[] = $route['base'];
            if ($route['addOn'] !== null) {
                $codes[] = $route['addOn'];
            }
        }
        $more = match ($service) {
            self::CCM => [self::complexCcm()['base'], self::complexCcm()['addOn']],
            self::BHI => [self::apcmAddOn(self::BHI, false)],
            self::COCM => [self::cocmMonth(false)['base'], self::cocmMonth(true)['base'], self::cocmAddOn()['code'], self::cocmShort()['code'],
                self::apcmAddOn(self::COCM, false), self::apcmAddOn(self::COCM, true)],
            self::APCM => [self::apcmCode(1, false), self::apcmCode(2, false), self::apcmCode(2, true)],
            default => [],
        };
        return array_values(array_unique([...$codes, ...$more]));
    }

    /** The APCM code for a patient: by the chronic conditions managed, and whether they are a Qualified Medicare Beneficiary. */
    public static function apcmCode(int $conditions, bool $qmb): string
    {
        $codes = self::data()['apcm'];
        if ($conditions >= 2) {
            return $qmb ? $codes['qmbTwoOrMore'] : $codes['twoOrMore'];
        }
        return $codes['oneOrNone'];
    }

    /** The CPT notice that goes wherever the module shows CPT numbers. */
    public static function cptNotice(): string
    {
        return self::data()['terms'];
    }

    /**
     * data/codes.json, read once.
     *
     * @return array<string, mixed>
     */
    private static function data(): array
    {
        static $data = null;
        return $data ??= json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/data/codes.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * How far back a visit spares the patient an initiating visit, and whether it has to be
     * the billing practitioner's own. For every service it does (0.3.9; before, a visit with
     * any practitioner counted for all but APCM):
     *  - APCM: 3 years ("All new patients and those who have not been seen by the billing
     *    practitioner within 3 years require an initiating visit", CMS's APCM FAQ, question 1);
     *  - CCM: 1 year ("an initiating visit for new patients or patients who you haven't seen
     *    within the previous 1 year", MLN909188, June 2025, whose "you" is the billing
     *    practitioner);
     *  - BHI and CoCM: 1 year ("only required for new patients or patients not seen within a
     *    year of commencement of BHI services", CMS's BHI FAQ of 2023-12-05, questions 17-18),
     *    by the billing practitioner (the initiating visit "establishes the patient's
     *    relationship with the billing practitioner and makes sure the billing practitioner
     *    assesses the patient before starting BHI services", MLN909432, January 2026);
     *  - PCM: as CCM.
     *
     * @return array{years: int, byPractitioner: bool}
     */
    public static function initiatingLookBack(string $service): array
    {
        return ['years' => $service === self::APCM ? 3 : 1, 'byPractitioner' => true];
    }

    /** The fewest conditions a service needs. */
    public static function minimumConditions(string $service): int
    {
        return match ($service) {
            self::CCM => 2,
            self::PCM, self::BHI, self::COCM => 1,
            default => 0,
        };
    }
}
