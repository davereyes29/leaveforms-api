<?php

namespace App\Models\Leave;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveActivityRecipient extends Model
{
    public $timestamps = false;

    protected $table = 'leave_activity_recipient';

    protected $fillable = [
        'activity_id',
        'emp_id',
    ];

    protected function casts(): array
    {
        return [
            'activity_id' => 'integer',
            'emp_id' => 'integer',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LeaveActivityHistory::class, 'activity_id');
    }
}
