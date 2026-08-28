<?php

namespace App\Models\Leave;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveInfoAttachment extends Model
{
    protected $table = 'leave_info_attachment';

    protected $fillable = [
        'leave_id',
        'path',
        'original_name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'leave_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function leaveInfo(): BelongsTo
    {
        return $this->belongsTo(LeaveInfo::class, 'leave_id');
    }

    /**
     * @return array{id: int, name: string, url: string}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'url' => url("/api/leaves/{$this->leave_id}/attachments/{$this->id}"),
        ];
    }
}
