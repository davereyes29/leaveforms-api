<?php

namespace App\Models\Leave;

use App\Enums\Leave\RequiredApproverType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LeaveApprovalRequirement extends Model
{
    protected $table = 'leave_approval_requirement';

    protected $fillable = [
        'leave_id',
        'display_order',
        'allowed_levels',
        'required_approver_type',
        'is_satisfied',
        'satisfied_at',
    ];

    protected function casts(): array
    {
        return [
            'leave_id' => 'integer',
            'display_order' => 'integer',
            'allowed_levels' => 'array',
            'required_approver_type' => RequiredApproverType::class,
            'is_satisfied' => 'boolean',
            'satisfied_at' => 'datetime',
        ];
    }

    public function leaveInfo(): BelongsTo
    {
        return $this->belongsTo(LeaveInfo::class, 'leave_id');
    }

    public function history(): HasOne
    {
        return $this->hasOne(LeaveApprovalHistory::class, 'requirement_id');
    }
}
