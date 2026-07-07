<?php

namespace App\Models\Kdtph;

use Illuminate\Database\Eloquent\Model;

/**
 * Employee department / team group.
 *
 * Table: kdtphdb_new.group_list
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
}
