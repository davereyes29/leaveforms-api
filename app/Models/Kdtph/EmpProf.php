<?php

namespace App\Models\Kdtph;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy employee profile (read-only). Current job title lives in fldDesig.
 *
 * Table: kdtphdb.emp_prof
 * Join: fldEmployeeNum = employee_list.id
 */
class EmpProf extends Model
{
    protected $connection = 'kdtphdb';

    protected $table = 'emp_prof';

    protected $primaryKey = 'fldID';

    public $timestamps = false;

    protected $fillable = [
        'fldEmployeeNum',
        'fldDesig',
    ];

    protected function casts(): array
    {
        return [
            'fldEmployeeNum' => 'integer',
        ];
    }
}
