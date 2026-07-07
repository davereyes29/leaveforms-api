<?php

namespace App\Models\Leave;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveBalance extends Model
{
    protected $table = 'leave_balance';

    protected $fillable = [
        'emp_id',
        'leave_type_id',
        'available',
        'reserved',
    ];

    protected function casts(): array
    {
        return [
            'emp_id' => 'integer',
            'leave_type_id' => 'integer',
            'available' => 'decimal:2',
            'reserved' => 'decimal:2',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LeaveBalanceTransaction::class, 'emp_id', 'emp_id')
            ->whereColumn('leave_balance_transaction.leave_type_id', 'leave_balance.leave_type_id');
    }
}
