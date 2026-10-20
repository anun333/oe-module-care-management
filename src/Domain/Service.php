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

    public const COMPLEX_CCM = ['base' => '99487', 'first' => 60, 'addOn' => '99489', 'each' => 30];
    public const COCM_FIRST_MONTH = ['base' => '99492', 'first' => 70];
    public const COCM_LATER_MONTH = ['base' => '99493', 'first' => 60];
    public const COCM_ADD_ON = ['code' => '99494', 'each' => 30];
    public const COCM_SHORT = ['code' => 'G2214', 'first' => 30];
    /** Months without CoCM after which a new episode, and a new first month, begins. */
    public const COCM_NEW_EPISODE_MONTHS = 6;

    public const STAFF = 'staff';
    public const PRACTITIONER = 'practitioner';

    /**
     * The routes a month's time can be billed by: for clinical staff time and for the billing
     * practitioner's own. first = minutes for the base code; each = minutes per add-on unit;
     * max = most add-on units a month (null: none stated); counts = whose minutes count.
     *
     * @return array<string, array{base: string, first: int, addOn: ?string, each: ?int, max: ?int, counts: list<string>}>
     */
    public static function routes(string $service): array
    {
        return match ($service) {
            self::CCM => [
                // Practitioner time counts toward the staff codes when it isn't used for 99491 (MLN909188).
                self::STAFF => ['base' => '99490', 'first' => 20, 'addOn' => '99439', 'each' => 20, 'max' => 2, 'counts' => [self::STAFF, self::PRACTITIONER]],
                self::PRACTITIONER => ['base' => '99491', 'first' => 30, 'addOn' => '99437', 'each' => 30, 'max' => null, 'counts' => [self::PRACTITIONER]],
            ],
            self::PCM => [
                self::STAFF => ['base' => '99426', 'first' => 30, 'addOn' => '99427', 'each' => 30, 'max' => null, 'counts' => [self::STAFF]],
                self::PRACTITIONER => ['base' => '99424', 'first' => 30, 'addOn' => '99425', 'each' => 30, 'max' => null, 'counts' => [self::PRACTITIONER]],
            ],
            self::BHI => [
                self::STAFF => ['base' => '99484', 'first' => 20, 'addOn' => null, 'each' => null, 'max' => null, 'counts' => [self::STAFF]],
            ],
            // Both are worked out in MonthRules: CoCM's codes depend on the month of the episode, and APCM has no time.
            self::COCM, self::APCM => [],
            default => throw new \InvalidArgumentException("Unknown care management service $service"),
        };
    }

    /** The APCM code for a patient: by the chronic conditions managed, and whether they are a Qualified Medicare Beneficiary. */
    public static function apcmCode(int $conditions, bool $qmb): string
    {
        if ($conditions >= 2) {
            return $qmb ? 'G0558' : 'G0557';
        }
        return 'G0556';
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
