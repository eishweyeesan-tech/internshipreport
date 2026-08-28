<?php
/**
 * Security & Password Helper
 * Standard strong password validation and security utilities across the system.
 */

if (!function_exists('validate_strong_password')) {
    /**
     * Validate strong password rules:
     * - Minimum 6 characters
     * - At least 1 uppercase letter (A-Z)
     * - At least 1 lowercase letter (a-z)
     * - At least 1 digit (0-9)
     * - At least 1 special character (e.g. @, #, $, !, %, *, &, ?)
     *
     * @param string $password
     * @return string|null Returns error message string if invalid, or null if valid.
     */
    function validate_strong_password($password) {
        if (empty($password)) {
            return 'Password is required.';
        }
        if (strlen($password) < 6) {
            return 'Password must be at least 6 characters long.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return 'Password must contain at least one uppercase letter (A-Z).';
        }
        if (!preg_match('/[a-z]/', $password)) {
            return 'Password must contain at least one lowercase letter (a-z).';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'Password must contain at least one number (0-9).';
        }
        if (!preg_match('/[\W_]/', $password)) {
            return 'Password must contain at least one special character (e.g. @, #, $, !, %).';
        }
        return null;
    }
}

if (!function_exists('validate_gmail_address')) {
    /**
     * Validate email format and require @gmail.com domain
     *
     * @param string $email
     * @return string|null Returns error message string if invalid, or null if valid.
     */
    function validate_gmail_address($email) {
        $email = trim((string)$email);
        if (empty($email)) {
            return 'Email is required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Invalid email format.';
        }
        if (!preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/i', $email)) {
            return 'Email address must be a valid @gmail.com account (e.g. user@gmail.com).';
        }
        return null;
    }
}

if (!function_exists('generate_random_strong_password')) {
    /**
     * Generate a cryptographically secure, readable temporary password
     * Contains at least 1 uppercase, 1 lowercase, 1 digit, and 1 special symbol.
     *
     * @param int $length
     * @return string
     */
    function generate_random_strong_password($length = 8) {
        if ($length < 6) $length = 8;
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghjkmnpqrstuvwxyz';
        $digits = '23456789';
        $symbols = '@#$%&*!';
        
        // Ensure at least one character from each required class
        $pw = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)]
        ];
        
        $all = $upper . $lower . $digits . $symbols;
        for ($i = count($pw); $i < $length; $i++) {
            $pw[] = $all[random_int(0, strlen($all) - 1)];
        }
        
        shuffle($pw);
        return implode('', $pw);
    }
}
