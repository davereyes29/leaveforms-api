<?php

namespace App\Models\Leave;

use App\Enums\Leave\DayType;
use App\Enums\Leave\LeaveStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LeaveInfo extends Model
{
    protected $table = 'leave_info';

    protected $fillable = [
        'emp_id',
        'filed_by_emp_id',
        'leave_type_id',
        'duration',
        'paid_days',
        'lwop_days',
        'day_type',
        'start_date',
        'end_date',
        'leave_reason',
        'status',
        'cancellation_reason',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'leave_type_id' => 'integer',
            'emp_id' => 'integer',
            'filed_by_emp_id' => 'integer',
            'duration' => 'decimal:2',
            'paid_days' => 'decimal:2',
            'lwop_days' => 'decimal:2',
            'day_type' => DayType::class,
            'status' => LeaveStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function approvalRequirements(): HasMany
    {
        return $this->hasMany(LeaveApprovalRequirement::class, 'leave_id')
            ->orderBy('display_order');
    }

    public function approvalHistories(): HasMany
    {
        return $this->hasMany(LeaveApprovalHistory::class, 'leave_id');
    }

    public function activityHistories(): HasMany
    {
        return $this->hasMany(LeaveActivityHistory::class, 'leave_id');
    }

    public function balanceTransactions(): HasMany
    {
        return $this->hasMany(LeaveBalanceTransaction::class, 'leave_id');
    }

    public function conversion(): HasOne
    {
        return $this->hasOne(LeaveConversion::class, 'leave_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LeaveInfoAttachment::class, 'leave_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
