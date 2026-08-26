<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class NameserverSet
{
    /** @return list<string> */
    public static function normalize(array $values, bool $requireMinimum = true): array
    {
        $normalized = array_values(array_map(
            fn ($value) => rtrim(strtolower(trim((string) $value)), '.'),
            $values,
        ));

        if ($requireMinimum && count($normalized) < 2) {
            throw ValidationException::withMessages(['nameservers' => 'At least two nameservers are required.']);
        }

        if (count($normalized) !== count(array_unique($normalized))) {
            throw ValidationException::withMessages(['nameservers' => 'Nameservers must be unique.']);
        }

        foreach ($normalized as $nameserver) {
            if (! self::isValidHostname($nameserver)) {
                throw ValidationException::withMessages(['nameservers' => "{$nameserver} is not a valid hostname."]);
            }
        }

        return $normalized;
    }

    public static function domain(string $domain): string
    {
        $domain = rtrim(strtolower(trim($domain)), '.');
        if (! self::isValidHostname($domain)) {
            throw new \InvalidArgumentException('Invalid domain name returned by provider.');
        }

        return $domain;
    }

    public static function equal(array $left, array $right): bool
    {
        $normalizedLeft = self::normalize($left, false);
        $normalizedRight = self::normalize($right, false);
        sort($normalizedLeft);
        sort($normalizedRight);

        return $normalizedLeft === $normalizedRight;
    }

    private static function isValidHostname(string $value): bool
    {
        if ($value === '' || strlen($value) > 253 || filter_var($value, FILTER_VALIDATE_IP) || str_contains($value, '://')) {
            return false;
        }
        foreach (explode('.', $value) as $label) {
            if ($label === '' || strlen($label) > 63 || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return str_contains($value, '.');
    }
}
