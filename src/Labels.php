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

use OpenHealthcare\CareManagement\Domain\BillingCheck;
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
            Service::COCM => xl('Psychiatric collaborative care (CoCM)'),
            Service::APCM => xl('Advanced primary care management (APCM)'),
            default => $service,
        };
    }

    public static function role(string $role): string
    {
        return $role === Service::PRACTITIONER ? xl('Billing practitioner') : xl('Clinical staff');
    }

    /** What a route's codes are for, on the month's summary. */
    public static function route(string $route, string $service): string
    {
        return match ($route) {
            Service::STAFF => $service === Service::COCM ? xl('Behavioral health care manager') : xl('Clinical staff'),
            Service::PRACTITIONER => xl('Billing practitioner'),
            Service::COMPLEX => xl('Complex CCM'),
            Service::APCM_CODE => xl('APCM, no time requirement'),
            Service::ADD_ON => xl('APCM add-on, no time requirement'),
            default => $route,
        };
    }

    /** What the billing check found, for the month list and its CSV. */
    public static function billing(string $status): string
    {
        return match ($status) {
            BillingCheck::BILLED => xl('Billed as met'),
            BillingCheck::BEYOND => xl('Billed beyond the time logged'),
            BillingCheck::BLOCKED => xl('Billed, though something blocks billing'),
            BillingCheck::NOT_BILLED => xl('Met, not billed'),
            BillingCheck::NOT_BILLED_YET => xl('Met, not billed yet'),
            BillingCheck::NOTHING => '',
            default => $status,
        };
    }

    public static function blocker(string $blocker): string
    {
        return match ($blocker) {
            MonthRules::NO_CONSENT => xl('Consent is not recorded with everything the patient must be told'),
            MonthRules::TOO_FEW_CONDITIONS => xl('Too few conditions: CCM needs two or more chronic conditions; PCM, BHI and CoCM need the condition managed'),
            MonthRules::NO_INITIATING_VISIT => xl('No initiating visit recorded: needed for a new patient, or one not seen in the year before (for APCM, not seen by the billing practitioner in three years)'),
            MonthRules::CCM_AND_PCM => xl('CCM and PCM cannot both be billed by the same practitioner in a month'),
            MonthRules::COCM_AND_BHI => xl('CoCM and general BHI cannot both be billed for the patient in a month'),
            MonthRules::APCM_AND_CCM_PCM => xl('APCM cannot be billed with CCM or PCM by the same practitioner in a month'),
            default => $blocker,
        };
    }

    /** @param list<array{code: string, units: int}> $codes */
    public static function codes(array $codes): string
    {
        return implode(' + ', array_map(static fn(array $c): string => $c['code'] . ($c['units'] > 1 ? ' x' . $c['units'] : ''), $codes));
    }
}
