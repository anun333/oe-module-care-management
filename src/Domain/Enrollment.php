<?php

/**
 * A patient's enrollment in one care management service: the billing practitioner, the
 * conditions managed, the consent CMS requires, and when it started and stopped.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Domain;

final class Enrollment
{
    /**
     * @param list<string> $conditions the problems managed, as their codes or titles
     * @param bool         $consentComplete the patient was told all four things CMS lists, and agreed
     * @param bool         $initiatingNeeded no visit in the year before the start, so an initiating visit is needed
     * @param bool         $qmb a Qualified Medicare Beneficiary (APCM's G0558)
     */
    public function __construct(
        public readonly int $id,
        public readonly int $pid,
        public readonly string $service,
        public readonly int $practitionerId,
        public readonly array $conditions,
        public readonly ?\DateTimeImmutable $consentOn,
        public readonly bool $consentComplete,
        public readonly bool $initiatingNeeded,
        public readonly ?\DateTimeImmutable $initiatingVisitOn,
        public readonly \DateTimeImmutable $startedOn,
        public readonly ?\DateTimeImmutable $stoppedOn,
        public readonly bool $qmb = false,
    ) {
    }

    /** Active in a calendar month: started by its end, and not stopped before it began (a stop takes effect at the end of its month). */
    public function activeIn(Month $month): bool
    {
        return $this->startedOn->setTime(0, 0) <= $month->last()
            && ($this->stoppedOn === null || $this->stoppedOn->setTime(0, 0) >= $month->first());
    }
}
