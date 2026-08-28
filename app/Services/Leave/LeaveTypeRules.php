<?php

namespace App\Services\Leave;

/**
 * Per-type filing rules (duration, attachments, LWOP, who approves).
 */
final class LeaveTypeRules
{
    /**
     * @return list<string>
     */
    public static function filableCodes(): array
    {
        return ['VL', 'SL', 'PTB', 'EL', 'LL', 'ML', 'PL'];
    }

    public static function requiresAttachment(string $code): bool
    {
        return in_array(strtoupper($code), ['SL', 'PTB', 'EL'], true);
    }

    public static function requiresMedCert(string $code): bool
    {
        return in_array(strtoupper($code), ['SL', 'PTB'], true);
    }

    public static function isAdminApproved(string $code): bool
    {
        return in_array(strtoupper($code), ['SL', 'PTB', 'EL', 'LL', 'ML', 'PL'], true);
    }

    public static function usesCalendarDays(string $code): bool
    {
        return in_array(strtoupper($code), ['PTB', 'LL', 'ML'], true);
    }

    public static function isUnpaid(string $code): bool
    {
        return strtoupper($code) === 'LL';
    }

    public static function grantsCreditsOnSubmit(string $code): bool
    {
        return in_array(strtoupper($code), ['ML', 'PL'], true);
    }

    public static function allowsLwop(string $code): bool
    {
        return in_array(strtoupper($code), ['VL', 'SL'], true);
    }

    public static function requiresFullDay(string $code): bool
    {
        return in_array(strtoupper($code), ['PTB', 'EL', 'LL', 'ML', 'PL'], true);
    }

    public static function exactCalendarDays(string $code): ?int
    {
        if (strtoupper($code) !== 'ML') {
            return null;
        }

        return (int) config('leave.parental_leave.ml_days', 105);
    }

    public static function exactWorkdays(string $code): ?int
    {
        if (strtoupper($code) !== 'PL') {
            return null;
        }

        return (int) config('leave.parental_leave.pl_workdays', 7);
    }

    /**
     * @return array{min: int, max: int}|null
     */
    public static function calendarMonthBounds(string $code): ?array
    {
        if (strtoupper($code) !== 'LL') {
            return null;
        }

        return [
            'min' => (int) config('leave.long_leave.min_months', 1),
            'max' => (int) config('leave.long_leave.max_months', 3),
        ];
    }

    public static function isEditable(string $code): bool
    {
        return in_array(strtoupper($code), self::filableCodes(), true);
    }
}
