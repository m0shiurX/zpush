<?php

namespace App\Enums;

enum PunchType: int
{
    case CheckIn = 0;
    case CheckOut = 1;
    case BreakOut = 2;
    case BreakIn = 3;
    case OvertimeIn = 4;
    case OvertimeOut = 5;

    /**
     * Get the display label for the punch type.
     */
    public function label(): string
    {
        return match ($this) {
            self::CheckIn => 'Check In',
            self::CheckOut => 'Check Out',
            self::BreakOut => 'Break Out',
            self::BreakIn => 'Break In',
            self::OvertimeIn => 'OT In',
            self::OvertimeOut => 'OT Out',
        };
    }

    /**
     * Get the color for the punch type badge.
     */
    public function color(): string
    {
        return match ($this) {
            self::CheckIn, self::BreakIn, self::OvertimeIn => 'success',
            self::CheckOut, self::BreakOut, self::OvertimeOut => 'secondary',
        };
    }
}
