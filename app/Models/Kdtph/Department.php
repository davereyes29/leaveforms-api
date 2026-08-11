<?php

namespace App\Models\Kdtph;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Company department.
 *
 * Table: kdtphdb_new.department_list
 * Groups (`group_list`) belong to a department via `dept_id`.
 */
class Department extends Model
{
    protected $connection = 'kdtphdb_new';

    protected $table = 'department_list';

    public $timestamps = false;

    protected $fillable = [
        'abbreviation',
        'name',
    ];

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class, 'dept_id');
    }
}
