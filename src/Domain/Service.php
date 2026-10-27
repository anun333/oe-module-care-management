<?php

/**
 * The care management services the module covers. Their codes, minutes and days, and the
 * rules that change from year to year, are in data/codes.json by calendar year (Schedule),
 * read from MLN909188, the CCM FAQ, MLN909432 and the CY 2017-2026 PFS rules.
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
 *    threshold isn't met (MLN909432). A new episode starts after 6 calendar months without
 *    CoCM (CY 2017 PFS rule, 81 FR 80241). From 0.4.0 CoCM follows "CPT time rules", as
 *    MLN909432 says (pages 7 and 13): a unit counts once more than half of it is done, so
 *    99492 from 36 minutes, 99493 from 31, 99494 from 16 past the base code's full time and
 *    G2214 from 16 (data/codes.json, with the quotes);
 *  - APCM: G0556 (one chronic condition or none), G0557 (two or more), G0558 (a Qualified
 *    Medicare Beneficiary with two or more), once a month, with no time requirement
 *    (CY 2025 PFS rule, 89 FR 97868-71), and in 2026 the add-ons G0568 (CoCM, first month),
 *    G0569 (CoCM, later months) and G0570 (general BHI), also with no time requirement, for
 *    the practitioner billing APCM that month (CY 2026 PFS rule, 90 FR 49469-71).
 *
 * 0.4.0, remote monitoring as the CY 2026 final rule set it (90 FR 49394-99):
 *  - RPM and RTM device and data for a 30-day period: 99445 (RPM) or 98984, 98985, 98986 (RTM of
 *    breathing, muscles and joints, cognitive behavioral therapy) for 2 to 15 days, 99454 or
 *    98976, 98977, 98978 for 16 or more. "The 2 to 15 day codes ... and 16 to 30 day codes ...
 *    are not additive ... Billing practitioners would only bill for one of those codes for the
 *    appropriate number of days of data transmission per 30 days" (90 FR 49397);
 *  - treatment management by calendar month: 99470 or 98979 for the first 10 minutes, 99457 or
 *    98980 for the first 20, "also not additive", and 99458 or 98981 for each further 20 "after
 *    either 99457 or 98980 is billed" (90 FR 49397). The codes "require a live, interactive
 *    communication with the patient/caregiver" (90 FR 49397, adopting CPT's words), so the
 *    month needs time logged as one;
 *  - set-up, 99453 or 98975, once an episode, which now "requires 2 days of monitoring in a
 *    30-day period for reporting, as opposed to 16" (90 FR 49399);
 *  - "only one practitioner can bill" the device codes "during a 30-day period", and RPM and RTM
 *    "may not be billed together"; either may be billed with CCM, BHI or PCM "if the time or
 *    effort is not counted twice" (CY 2024 PFS rule, 88 FR 78883), and APCM doesn't exclude them
 *    (89 FR 97895, Table 26);
 *  - RPM only for an established patient (88 FR 78883-84): a "new patient" is one who "has not
 *    received any professional services ... from the physician or physician group practice
 *    (same physician specialty) within the previous 3 years" (Medicare Claims Processing
 *    Manual, chapter 12, 30.6.7). The module counts only the billing practitioner's visits, as
 *    for every other service. RTM has no such rule in 2026 (88 FR 78884).
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
    /** Remote physiologic and remote therapeutic monitoring (0.4.0). */
    public const RPM = 'rpm';
    public const RTM = 'rtm';
    public const ALL = [self::CCM, self::PCM, self::BHI, self::COCM, self::APCM, self::RPM, self::RTM];
    public const REMOTE = [self::RPM, self::RTM];

    /** What RTM monitors, which picks its device codes; RPM's codes don't depend on it. */
    public const RTM_KINDS = ['respiratory', 'musculoskeletal', 'cbt'];

    /** Routes beyond the staff and practitioner ones: complex CCM, APCM itself, and an APCM add-on. */
    public const COMPLEX = 'complex';
    public const APCM_CODE = 'apcm_code';
    public const ADD_ON = 'add_on';

    /** Months without CoCM after which a new episode, and a new first month, begins. */
    public const COCM_NEW_EPISODE_MONTHS = 6;

    public const STAFF = 'staff';
    public const PRACTITIONER = 'practitioner';

    /**
     * Every code an enrollment in the service can meet, under any year a month can be counted
     * under, for matching what was billed. The year's codes are in data/codes.json
     * (Schedule::codes). No code belongs to two services.
     *
     * @return list<string>
     */
    public static function codes(string $service): array
    {
        $codes = [];
        foreach (Schedule::usableYears() as $year) {
            array_push($codes, ...Schedule::forYear($year)->codes($service));
        }
        return array_values(array_unique($codes));
    }

    /** The CPT notice that goes wherever the module shows CPT numbers. */
    public static function cptNotice(): string
    {
        return Schedule::bundled()['terms'];
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
     *  - PCM: as CCM;
     *  - RPM: 3 years, as an established patient (see above); RTM: none in 2026.
     *
     * The years are by the calendar year the enrollment starts in (Schedule::lookBack): null
     * when no initiating visit is needed, 0 when every enrollment needs its own. $allowProposed
     * reads a proposed year too: only tests and the final-rule check do.
     *
     * @return array{years: ?int, byPractitioner: bool}
     */
    public static function initiatingLookBack(string $service, int $startYear, bool $allowProposed = false): array
    {
        return ['years' => Schedule::forYear($startYear, $allowProposed)->lookBack($service), 'byPractitioner' => true];
    }

    /** The fewest conditions a service needs. */
    public static function minimumConditions(string $service): int
    {
        return match ($service) {
            self::CCM => 2,
            self::PCM, self::BHI, self::COCM => 1,
            // APCM's code counts the conditions; CMS sets no minimum for RPM or RTM, which may be for an acute condition.
            default => 0,
        };
    }
}
