<?php

namespace App\Models\Kdtph;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Active employee profile used across the leave revamp.
 *
 * Table: kdtphdb_new.employee_list
 */
class Employee extends Model
{
    protected $connection = 'kdtphdb_new';

    protected $table = 'employee_list';

    public $timestamps = false;

    protected $fillable = [
        'surname',
        'firstname',
        'nickname',
        'username',
        'email',
        'group_id',
        'designation',
        'date_hired',
        'emp_status',
    ];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'designation' => 'integer',
            'emp_status' => 'integer',
            'date_hired' => 'date',
            'birthdate' => 'date',
            'resignation_date' => 'date',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function fullName(): string
    {
        return trim("{$this->firstname} {$this->surname}");
    }

    public function isActive(): bool
    {
        return (int) $this->emp_status === 1;
    }
}
