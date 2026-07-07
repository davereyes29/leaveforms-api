<?php

namespace App\Models\Leave;

use App\Enums\Leave\BalanceTransactionReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalanceTransaction extends Model
{
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $table = 'leave_balance_transaction';

    protected $fillable = [
        'emp_id',
        'leave_id',
        'leave_type_id',
        'available_delta',
        'reserved_delta',
        'available_after',
        'reserved_after',
        'reason',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'emp_id' => 'integer',
            'leave_id' => 'integer',
            'leave_type_id' => 'integer',
            'available_delta' => 'decimal:2',
            'reserved_delta' => 'decimal:2',
            'available_after' => 'decimal:2',
            'reserved_after' => 'decimal:2',
            'reason' => BalanceTransactionReason::class,
            'created_at' => 'datetime',
        ];
    }

    public function leaveInfo(): BelongsTo
    {
        return $this->belongsTo(LeaveInfo::class, 'leave_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
