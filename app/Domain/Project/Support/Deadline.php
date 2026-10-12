<?php

namespace App\Domain\Project\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Human deadline label ("Due in 12 days", "Overdue 3 days") from a due date.
 * A display helper only, not a deadline engine.
 */
final readonly class Deadline
{
    private function __construct(
        public string $label,
        public string $tone,
    ) {}

    public static function for(CarbonInterface $due, bool $done = false): self
    {
        if ($done) {
            return new self('Selesai', 'zinc');
        }

        $days = (int) Date::today()->diffInDays($due->copy()->startOfDay(), false);

        return match (true) {
            $days < 0 => new self('Terlambat '.abs($days).' hari', 'red'),
            $days === 0 => new self('Jatuh tempo hari ini', 'amber'),
            $days <= 7 => new self($days.' hari lagi', 'amber'),
            default => new self($days.' hari lagi', 'zinc'),
        };
    }
}
