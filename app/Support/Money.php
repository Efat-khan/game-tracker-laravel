<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Every taka in CafeTrack passes through here.
 *
 * Money is never a float. The bcmath extension is not assumed to be present, so
 * brick/math does the arithmetic (it falls back to a pure-PHP calculator).
 * Values are stored DECIMAL(10,2) and serialised as strings — "250.00", never
 * 250.0 — because a JSON float cannot represent every 2dp value exactly.
 */
final class Money
{
    public const SCALE = 2;

    /**
     * Coerce anything the DB, a form request or a literal hands us into an exact
     * decimal. Floats go via a string first so we never inherit binary error.
     */
    public static function of(BigDecimal|string|int|float|null $value): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }

        if ($value instanceof BigDecimal) {
            return $value;
        }

        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') ?: '0';
        }

        return BigDecimal::of((string) $value);
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero();
    }

    /**
     * Bankers would round half-to-even; a cash drawer rounds half up. So do we,
     * everywhere, consistently.
     */
    public static function round(BigDecimal|string|int|float|null $value, int $scale = self::SCALE): BigDecimal
    {
        return self::of($value)->toScale($scale, RoundingMode::HalfUp);
    }

    /** The wire format: exactly two decimal places, as a string. */
    public static function str(BigDecimal|string|int|float|null $value): string
    {
        return (string) self::round($value);
    }

    public static function isZero(BigDecimal|string|int|float|null $value): bool
    {
        return self::of($value)->isZero();
    }

    public static function max(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->isGreaterThan($b) ? $a : $b;
    }

    /**
     * Render a percentage with trailing zeros trimmed, so a tier discount reads
     * "5%" and not "5.00%" (§5.3).
     */
    public static function trimPercent(BigDecimal|string|int|float|null $value): string
    {
        $s = (string) self::of($value);

        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s === '' || $s === '-' ? '0' : $s;
    }
}
