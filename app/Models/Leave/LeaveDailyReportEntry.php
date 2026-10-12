<?php

namespace App\Models\Leave;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveDailyReportEntry extends Model
{
    public $timestamps = false;

    protected $table = 'leave_daily_report_entries';

    protected $fillable = [
        'leave_id',
        'dailyreport_id',
        'report_date',
    ];

    protected function casts(): array
    {
        return [
            'leave_id' => 'integer',
            'dailyreport_id' => 'integer',
            'report_date' => 'date',
        ];
    }

    public function leaveInfo(): BelongsTo
    {
        return $this->belongsTo(LeaveInfo::class, 'leave_id');
    }
}
