<?php

namespace App\Models\Leave;

use App\Enums\Leave\ApproverType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveApprovalHistory extends Model
{
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $table = 'leave_approval_history';

    protected $fillable = [
        'leave_id',
        'requirement_id',
        'approver_emp_id',
        'approval_level',
        'approver_type',
        'is_approved',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'leave_id' => 'integer',
            'requirement_id' => 'integer',
            'approver_emp_id' => 'integer',
            'approval_level' => 'integer',
            'approver_type' => ApproverType::class,
            'is_approved' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function leaveInfo(): BelongsTo
    {
        return $this->belongsTo(LeaveInfo::class, 'leave_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(LeaveApprovalRequirement::class, 'requirement_id');
    }
}
