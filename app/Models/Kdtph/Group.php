<?php

namespace App\Models\Kdtph;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Employee team / section within a department.
 *
 * Table: kdtphdb_new.group_list (`dept_id` → department_list.id)
 */
class Group extends Model
{
    protected $connection = 'kdtphdb_new';

    protected $table = 'group_list';

    public $timestamps = false;

    protected $fillable = [
        'abbreviation',
        'name',
        'dept_id',
    ];

    protected function casts(): array
    {
        return [
            'dept_id' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'dept_id');
    }
}
