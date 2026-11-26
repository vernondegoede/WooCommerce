<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK;

use InvalidArgumentException;

/**
 * Parts are ids and amounts.
 */
final class IdempotencyKey
{
    private const PREFIX = 'mwc';

    /**
     * @param string $intent Versioned, e.g. 'express.session.v1'.
     * @param array<string, scalar|null> $parts
     * @throws InvalidArgumentException
     */
    public static function for(string $intent, array $parts): string
    {
        $pairs = [];
        foreach ($parts as $name => $value) {
            $pairs[] = [(string) $name, self::normalise((string) $name, $value)];
        }
        usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        $canonical = json_encode([$intent, $pairs], JSON_THROW_ON_ERROR);

        return self::PREFIX . '-' . hash('sha256', $canonical);
    }

    /**
     * Generate a random RFC 4122 UUID v4 suitable for Mollie's Idempotency-Key header.
     */
    public static function random(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Use the caller-supplied idempotency key when present, or generate a fresh UUID v4 so
     * automatic SDK retries of this single network call remain idempotent.
     */
    public static function resolve(?string $idempotencyKey): string
    {
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            return $idempotencyKey;
        }

        return self::random();
    }

    /**
     * @param mixed $value
     */
    private static function normalise(string $name, $value): string
    {
        if (is_bool($value)) {
            return $value ? 'bool:1' : 'bool:0';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value) || is_string($value)) {
            return 'v:' . $value;
        }

        throw new InvalidArgumentException(
            sprintf('Idempotency key part "%s" must be an int, string, bool or null.', $name)
        );
    }
}
