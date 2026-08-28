<?php

namespace App\Models\Kdtph;

use App\Enums\Leave\EmploymentStatus;
use App\Services\Kdtph\EmployeeDesignationResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Active employee profile used across the leave revamp.
 *
 * Table: kdtphdb_new.employee_list
 * Current job title: emp_prof.fldDesig → designation_list (see resolvedJobDesignation()).
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
     * Legacy profile row. fldDesig is the current job-title acronym.
     */
    public function empProf(): HasOne
    {
        return $this->hasOne(EmpProf::class, 'fldEmployeeNum', 'id');
    }

    /**
     * Stale integer FK on employee_list. Prefer resolvedJobDesignation().
     * Named jobDesignation to avoid colliding with the integer attribute `designation`.
     */
    public function jobDesignation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation');
    }

    /**
     * Current title: emp_prof.fldDesig → designation_list (KDTP = id 29).
     * Falls back to employee_list.designation when fldDesig is missing or unknown.
     */
    public function resolvedJobDesignation(): ?Designation
    {
        return app(EmployeeDesignationResolver::class)->forEmployee($this);
    }

    public function resolvedDesignationId(): int
    {
        return (int) ($this->resolvedJobDesignation()?->id ?? $this->designation);
    }

    public function employmentStatus(): EmploymentStatus
    {
        return EmploymentStatus::fromDesignationId($this->resolvedDesignationId());
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeWhereEmploymentStatus(Builder $query, EmploymentStatus $status): Builder
    {
        app(EmployeeDesignationResolver::class)->applyStatus($query, $status);

        return $query;
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeWhereNotEmploymentStatus(Builder $query, EmploymentStatus $status): Builder
    {
        app(EmployeeDesignationResolver::class)->applyNotStatus($query, $status);

        return $query;
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
