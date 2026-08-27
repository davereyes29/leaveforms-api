<?php

namespace App\Enums\Leave;

enum BalanceTransactionReason: string
{
    case LeaveReserved = 'leave_reserved';
    case LeaveConfirmed = 'leave_confirmed';
    case LeaveReservationReleased = 'leave_reservation_released';
    case VlToSlRestore = 'vl_to_sl_restore';
    case VlToSlDeduct = 'vl_to_sl_deduct';
    case MonthlyAccrual = 'monthly_accrual';
    case ManualAdjustment = 'manual_adjustment';
    case LeaveReversed = 'leave_reversed';
    case InitialBalance = 'initial_balance';
    case AnnualEntitlement = 'annual_entitlement';
    case RegularizationEntitlement = 'regularization_entitlement';
}
