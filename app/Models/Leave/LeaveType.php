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

    /**
     * Human-readable leave type name for email copy.
     */
    public function mailLabel(): string
    {
        return match (strtoupper((string) $this->code)) {
            'PTB' => 'PTB Leave',
            default => (string) $this->type,
        };
    }
}
