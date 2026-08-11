<?php

namespace App\Models\Kdtph;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Job title / designation lookup.
 *
 * Table: kdtphdb_new.designation_list
 * Employees reference this via employee_list.designation.
 */
class Designation extends Model
{
    protected $connection = 'kdtphdb_new';

    protected $table = 'designation_list';

    public $timestamps = false;

    protected $fillable = [
        'acronym',
        'name',
        'section',
        'priority',
        'show_man_sum',
    ];

    protected function casts(): array
    {
        return [
            'section' => 'integer',
            'priority' => 'integer',
            'show_man_sum' => 'integer',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'designation');
    }
}
