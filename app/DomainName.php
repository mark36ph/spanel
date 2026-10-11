<?php
declare(strict_types=1);

/**
 * Strict ASCII DNS hostname validation for public website names.
 * Internationalized names should be converted to IDNA ASCII (punycode)
 * before they reach this class.
 */
final class DomainName
{
    public static function normalize(string $input): ?string
    {
        $domain = strtolower(rtrim(trim($input), '.'));
        if ($domain === '' || strlen($domain) > 253
            || filter_var($domain, FILTER_VALIDATE_IP) !== false
            || preg_match('/[^a-z0-9.-]/', $domain)) {
            return null;
        }

        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return null;
        }
        foreach ($labels as $label) {
            if (strlen($label) < 1 || strlen($label) > 63
                || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label)) {
                return null;
            }
        }
        return $domain;
    }
}
