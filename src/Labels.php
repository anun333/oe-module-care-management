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
            Service::RPM => xl('Remote physiologic monitoring (RPM)'),
            Service::RTM => xl('Remote therapeutic monitoring (RTM)'),
            default => $service,
        };
    }

    public static function role(string $role): string
    {
        return $role === Service::PRACTITIONER ? xl('Billing practitioner') : xl('Clinical staff');
    }

    /** What RTM monitors. */
    public static function deviceKind(string $kind): string
    {
        return match ($kind) {
            'respiratory' => xl('Breathing (respiratory system)'),
            'musculoskeletal' => xl('Muscles and joints (musculoskeletal system)'),
            'cbt' => xl('Cognitive behavioral therapy'),
            default => $kind,
        };
    }

    /** What a route's codes are for, on the month's summary. */
    public static function route(string $route, string $service): string
    {
        return match ($route) {
            Service::STAFF => match ($service) {
                Service::COCM => xl('Behavioral health care manager'),
                Service::RPM, Service::RTM => xl('Device days and treatment management'),
                default => xl('Clinical staff'),
            },
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

    /**
     * @param string $service the enrollment's, where the wording depends on it
     * @param ?int   $lookBack the enrollment's initiating-visit look-back (MonthRules' summary): 0 when every enrollment needs its own
     */
    public static function blocker(string $blocker, string $service = '', ?int $lookBack = null): string
    {
        return match (true) {
            $blocker === MonthRules::NO_CONSENT && in_array($service, Service::REMOTE, true) => xl('Consent is not recorded'),
            $blocker === MonthRules::NO_INITIATING_VISIT && $lookBack === 0
                => xl('No initiating visit recorded: this service needs one with the billing practitioner when it starts'),
            $blocker === MonthRules::NO_INITIATING_VISIT && $service === Service::RPM
                => xl('No visit with the billing practitioner in the three years before RPM started: RPM is only for an established patient. Record the visit that made them one'),
            default => self::blockerText($blocker),
        };
    }

    private static function blockerText(string $blocker): string
    {
        return match ($blocker) {
            MonthRules::NO_CONSENT => xl('Consent is not recorded with everything the patient must be told'),
            MonthRules::TOO_FEW_CONDITIONS => xl('Too few conditions: CCM needs two or more chronic conditions; PCM, BHI and CoCM need the condition managed'),
            MonthRules::NO_INITIATING_VISIT => xl('No initiating visit recorded: needed for a new patient, or one the billing practitioner has not seen in the year before (for APCM, in three years)'),
            MonthRules::PCM_RENEWAL => xl('A year of PCM is up: CMS asks for another initiating visit to continue. Record its date under Record consent or initiating visit'),
            MonthRules::REMOTE_TWICE => xl('Only one RPM or RTM service can be billed for the patient at a time, and by one practitioner'),
            MonthRules::CCM_AND_PCM => xl('CCM and PCM cannot both be billed by the same practitioner in a month'),
            MonthRules::COCM_AND_BHI => xl('CoCM and general BHI cannot both be billed for the patient in a month'),
            MonthRules::APCM_AND_CCM_PCM => xl('APCM cannot be billed with CCM or PCM by the same practitioner in a month'),
            default => $blocker,
        };
    }

    /** Why a remote monitoring month's minutes meet no code. */
    public static function note(string $note): string
    {
        return match ($note) {
            MonthRules::NO_INTERACTIVE => xl('No time this month is marked as a live conversation with the patient or caregiver: RPM and RTM treatment management need one'),
            default => $note,
        };
    }

    /** @param list<array{code: string, units: int}> $codes */
    public static function codes(array $codes): string
    {
        return implode(' + ', array_map(static fn(array $c): string => $c['code'] . ($c['units'] > 1 ? ' x' . $c['units'] : ''), $codes));
    }
}
