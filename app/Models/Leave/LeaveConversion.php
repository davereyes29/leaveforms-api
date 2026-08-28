<?php

namespace App\Models\Leave;

use App\Enums\Leave\ConversionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveConversion extends Model
{
    protected $table = 'leave_conversion';

    protected $fillable = [
        'leave_id',
        'from_leave_type_id',
        'to_leave_type_id',
        'reason',
        'reviewed_by_emp_id',
        'status',
        'remarks',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'leave_id' => 'integer',
            'from_leave_type_id' => 'integer',
            'to_leave_type_id' => 'integer',
            'reviewed_by_emp_id' => 'integer',
            'status' => ConversionStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function leaveInfo(): BelongsTo
    {
        return $this->belongsTo(LeaveInfo::class, 'leave_id');
    }

    public function fromLeaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'from_leave_type_id');
    }

    public function toLeaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'to_leave_type_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LeaveConversionAttachment::class, 'conversion_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
