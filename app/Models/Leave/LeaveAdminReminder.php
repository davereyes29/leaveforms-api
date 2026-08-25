<?php

namespace App\Models\Leave;

use App\Enums\Leave\ReminderKind;
use Illuminate\Database\Eloquent\Model;

class LeaveAdminReminder extends Model
{
    protected $table = 'leave_admin_reminder';

    protected $fillable = [
        'kind',
        'sent_by_emp_id',
        'leave_id',
        'target_emp_id',
        'approver_count',
        'request_count',
        'email_count',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ReminderKind::class,
            'sent_by_emp_id' => 'integer',
            'leave_id' => 'integer',
            'target_emp_id' => 'integer',
            'approver_count' => 'integer',
            'request_count' => 'integer',
            'email_count' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
