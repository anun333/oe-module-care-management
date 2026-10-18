<?php

/**
 * The care management services the module covers, with CMS's codes and monthly time
 * thresholds (MLN909188, the CCM FAQ, MLN909432 and the CY 2025-2026 PFS rules).
 * Each add-on unit needs its full time. 99439's limit of two a month is CPT's, as
 * commonly cited; CMS's booklet and FAQ don't state it.
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
    public const ALL = [self::CCM, self::PCM, self::BHI];

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
            default => throw new \InvalidArgumentException("Unknown care management service $service"),
        };
    }

    /** The fewest conditions a service needs. */
    public static function minimumConditions(string $service): int
    {
        return match ($service) {
            self::CCM => 2,
            self::PCM, self::BHI => 1,
            default => 0,
        };
    }
}
