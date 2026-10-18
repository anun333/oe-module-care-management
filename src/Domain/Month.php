<?php

/**
 * A calendar month: care management time is counted per calendar month and never
 * carries over.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CareManagement\Domain;

final class Month
{
    private function __construct(private readonly \DateTimeImmutable $first)
    {
    }

    public static function of(\DateTimeImmutable $day): self
    {
        return new self($day->modify('first day of this month')->setTime(0, 0));
    }

    /** From "YYYY-MM", or null. */
    public static function parse(string $value): ?self
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value . '-01');
        return $day === false || $day->format('Y-m') !== $value ? null : new self($day);
    }

    public function first(): \DateTimeImmutable
    {
        return $this->first;
    }

    public function last(): \DateTimeImmutable
    {
        return $this->first->modify('last day of this month');
    }

    public function contains(\DateTimeImmutable $day): bool
    {
        $d = $day->setTime(0, 0);
        return $d >= $this->first && $d <= $this->last();
    }

    public function key(): string
    {
        return $this->first->format('Y-m');
    }
}
