<?php

namespace App\Models\Leave;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveConversionAttachment extends Model
{
    protected $table = 'leave_conversion_attachment';

    protected $fillable = [
        'conversion_id',
        'path',
        'original_name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'conversion_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(LeaveConversion::class, 'conversion_id');
    }

    /**
     * @return array{id: int, name: string, url: string}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'url' => url("/api/conversions/{$this->conversion_id}/attachments/{$this->id}"),
        ];
    }
}
