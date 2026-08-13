<?php
/**
 * includes/phone_validation.php
 *
 * Shared Myanmar phone-number validation + normalization for every form
 * that collects a phone number (student profile, supervisor profile,
 * company contact phone).
 *
 * Supported formats (local and international):
 *   09xxxxxxxxx        e.g. 09454104282
 *   +959xxxxxxxxx      e.g. +959454104282
 *   plus common separators (space, hyphen, dot, parentheses, slash)
 *
 * International numbers are normalized to the local "09..." form before
 * saving, which matches the format already stored in the database.
 */

function normalize_phone(string $phone): string
{
    $phone = trim($phone);
    // Strip common formatting characters.
    $phone = str_replace([' ', '-', '.', '(', ')', '/'], '', $phone);

    // +959... (country code 95 + national leading 9) -> local 09...
    if (strpos($phone, '+959') === 0) {
        $phone = '09' . substr($phone, 4);
    }

    return $phone;
}

/**
 * Returns an error message string if $phone is present and invalid,
 * or null when the value is empty (phone fields are optional) or valid.
 */
function phone_validation_error(string $phone): ?string
{
    $phone = trim($phone);

    if ($phone === '') {
        return null;
    }

    // Only phone-number characters are allowed (digits, +, -, spaces, . ( ) /).
    if (!preg_match('/^[0-9+ .()\/-]+$/', $phone)) {
        return 'Phone number can only contain valid phone-number characters.';
    }

    // 09 followed by 7-9 digits (modern mobile numbers are 9 digits after 09;
    // 7-8 also accepted for legacy values already stored in the database).
    if (!preg_match('/^09\d{7,9}$/', normalize_phone($phone))) {
        return 'Please enter a valid phone number.';
    }

    return null;
}
