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
    public const GENDER_MALE = 0;

    public const GENDER_FEMALE = 1;

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
        'gender',
        'date_hired',
        'emp_status',
    ];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'designation' => 'integer',
            'gender' => 'integer',
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

    /**
     * Named jobDesignation to avoid colliding with the integer FK attribute `designation`.
     */
    public function jobDesignation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation');
    }

    public function fullName(): string
    {
        return trim("{$this->firstname} {$this->surname}");
    }

    public function isActive(): bool
    {
        return (int) $this->emp_status === 1;
    }

    public function isMale(): bool
    {
        return (int) $this->gender === self::GENDER_MALE;
    }

    public function isFemale(): bool
    {
        return (int) $this->gender === self::GENDER_FEMALE;
    }
}
