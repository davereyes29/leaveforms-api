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
    /** KDT head office — only location exposed by the holidays API for now. */
    public const KDT_LOCATION_ID = 1;

    protected $connection = 'kdtphdb';

    protected $table = 'kdtholiday';

    protected $primaryKey = 'fldID';

    public $timestamps = false;

    protected $fillable = [
        'fldDate',
        'fldHoliday',
        'fldHolidayType',
        'fldLocID',
        'fldLocation',
    ];

    protected function casts(): array
    {
        return [
            'fldDate' => 'date',
            'fldHolidayType' => 'integer',
            'fldLocID' => 'integer',
        ];
    }
}
