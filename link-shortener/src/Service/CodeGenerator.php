<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Generates random base62 short codes.
 *
 * Pure logic, no framework dependencies -- this is the unit-test target.
 * random_int() is a CSPRNG, so codes are not guessable or sequential.
 *
 * 62^7 = ~3.5 * 10^12 combinations; collisions are near-impossible at this
 * scale, but LinkCreator still handles them (the handling is the lesson).
 */
class CodeGenerator
{
    public const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    public const LENGTH = 7;

    public function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, 61)];
        }

        return $code;
    }
}
