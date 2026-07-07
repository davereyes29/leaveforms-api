<?php

namespace App\Models\Kdtph;

use Illuminate\Database\Eloquent\Model;

/**
 * Portal login row — maps the userID cookie hash to an employee id.
 *
 * Table: kdtphdb.kdtlogin
 */
class KdtLogin extends Model
{
    protected $connection = 'kdtphdb';

    protected $table = 'kdtlogin';

    protected $primaryKey = 'fldID';

    public $timestamps = false;

    protected $fillable = [
        'fldUser',
        'fldEmployeeNum',
        'fldUserHash',
    ];

    public function getEmployeeId(): int
    {
        return (int) $this->fldEmployeeNum;
    }
}
