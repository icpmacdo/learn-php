<?php

declare(strict_types=1);

namespace App\Enum;

enum TaskPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * Numeric rank so "sort by priority" means high > medium > low,
     * not alphabetical (which would be high > low > medium).
     */
    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
        };
    }
}
