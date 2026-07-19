<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * Render minor units as text for email bodies — integer arithmetic only, no
 * float ever (the Money lesson holds even for presentation). Assumes
 * two-decimal currencies, which is all this project sells in; a real system
 * would carry the exponent per currency.
 */
final class MoneyText
{
    private function __construct()
    {
    }

    public static function format(int $amountMinor, string $currency): string
    {
        $sign = $amountMinor < 0 ? '-' : '';
        $abs = abs($amountMinor);

        return sprintf('%s%s%d.%02d', $sign, $currency.' ', intdiv($abs, 100), $abs % 100);
    }
}
