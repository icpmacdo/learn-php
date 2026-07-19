<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Pure-PHP UUIDv7 generation (RFC 9562): 48-bit unix-millisecond timestamp,
 * then version/variant bits, then 74 random bits.
 *
 * Why hand-rolled: aggregate identity is minted IN the domain (an Order puts
 * its own id into OrderPlaced before any flush), and the domain may import no
 * vendor code — symfony/uid would be a deptrac violation. v7 over v4 because
 * time-ordered ids keep MySQL's clustered PK append-mostly instead of
 * splatter-inserting random values.
 */
final class UuidV7
{
    /** Any RFC 4122/9562 UUID shape (used to validate ids that arrive over HTTP). */
    public const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private function __construct()
    {
    }

    public static function generate(): string
    {
        $milliseconds = (int) floor(microtime(true) * 1000);
        $time = str_pad(dechex($milliseconds), 12, '0', STR_PAD_LEFT);

        $random = random_bytes(10);
        $random[0] = \chr((\ord($random[0]) & 0x0F) | 0x70); // version 7
        $random[2] = \chr((\ord($random[2]) & 0x3F) | 0x80); // variant 10xx

        $hex = $time.bin2hex($random);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * Is this ANY well-formed UUID (not necessarily v7)? Ids we mint are v7,
     * but ids arriving from outside only need to be look-up-able: a
     * well-formed foreign UUID is simply "not found", never a 500.
     */
    public static function isWellFormed(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
