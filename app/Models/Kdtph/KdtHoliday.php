<?php

namespace App\Models\Kdtph;

use Illuminate\Database\Eloquent\Model;

/**
 * Company holiday — excluded from leave duration.
 *
 * Table: kdtphdb.kdtholiday
 */
class KdtHoliday extends Model
{
    protected $connection = 'kdtphdb';

    protected $table = 'kdtholiday';

    protected $primaryKey = 'fldID';

    public $timestamps = false;

    protected $fillable = [
        'fldDate',
        'fldHoliday',
        'fldHolidayType',
    ];

    protected function casts(): array
    {
        return [
            'fldDate' => 'date',
            'fldHolidayType' => 'integer',
        ];
    }
}
