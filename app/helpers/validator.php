<?php

namespace App\Helpers;

class Validator
{
    /**
     * Validates Email Format
     * Returns true if valid, false if invalid.
     */
    public static function email($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validates Password Complexity
     * Rule: At least 8 chars, 1 Uppercase, 1 Special Character
     * Returns true if valid, false if invalid.
     */
    public static function password($password)
    {
        return preg_match('/^(?=.*[A-Z])(?=.*[\W_]).{8,}$/', $password);
    }

    /**
     * Validates Phone Number Format
     * Rule: Exactly 10 digits
     * Returns true if valid, false if invalid.
     */
    public static function phone($phone)
    {
        return preg_match('/^[0-9]{10}$/', $phone);
    }
}