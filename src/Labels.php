<?php

/**
 * What the pages call services, roles and blockers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CareManagement;

use OpenHealthcare\CareManagement\Domain\MonthRules;
use OpenHealthcare\CareManagement\Domain\Service;

final class Labels
{
    public static function service(string $service): string
    {
        return match ($service) {
            Service::CCM => xl('Chronic care management (CCM)'),
            Service::PCM => xl('Principal care management (PCM)'),
            Service::BHI => xl('Behavioral health integration (BHI)'),
            default => $service,
        };
    }

    public static function role(string $role): string
    {
        return $role === Service::PRACTITIONER ? xl('Billing practitioner') : xl('Clinical staff');
    }

    public static function blocker(string $blocker): string
    {
        return match ($blocker) {
            MonthRules::NO_CONSENT => xl('Consent is not recorded with all four things the patient must be told'),
            MonthRules::TOO_FEW_CONDITIONS => xl('Too few conditions: CCM needs two or more chronic conditions; PCM and BHI need the condition managed'),
            MonthRules::NO_INITIATING_VISIT => xl('No initiating visit recorded: needed for a new patient or one not seen in the year before'),
            MonthRules::CCM_AND_PCM => xl('CCM and PCM cannot both be billed by the same practitioner in a month'),
            default => $blocker,
        };
    }

    /** @param list<array{code: string, units: int}> $codes */
    public static function codes(array $codes): string
    {
        return implode(' + ', array_map(static fn(array $c): string => $c['code'] . ($c['units'] > 1 ? ' x' . $c['units'] : ''), $codes));
    }
}
