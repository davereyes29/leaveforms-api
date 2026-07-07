<?php

namespace App\Models\Leave;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    public $timestamps = false;

    protected $table = 'leave_type';

    protected $fillable = [
        'code',
        'type',
    ];

    public function leaveInfos(): HasMany
    {
        return $this->hasMany(LeaveInfo::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }
}
