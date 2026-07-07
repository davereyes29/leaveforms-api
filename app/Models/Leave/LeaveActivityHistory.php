<?php

namespace App\Models\Leave;

use App\Enums\Leave\ActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveActivityHistory extends Model
{
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $table = 'leave_activity_history';

    protected $fillable = [
        'leave_id',
        'performed_by_emp_id',
        'activity_type',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'leave_id' => 'integer',
            'performed_by_emp_id' => 'integer',
            'activity_type' => ActivityType::class,
            'created_at' => 'datetime',
        ];
    }

    public function leaveInfo(): BelongsTo
    {
        return $this->belongsTo(LeaveInfo::class, 'leave_id');
    }
}
