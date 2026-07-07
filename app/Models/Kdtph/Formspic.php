<?php

namespace App\Models\Kdtph;

use Illuminate\Database\Eloquent\Model;

/**
 * Person-in-charge (approver) configuration from legacy kdtphdb.
 *
 * Table: kdtphdb.formspic
 */
class Formspic extends Model
{
    protected $connection = 'kdtphdb';

    protected $table = 'formspic';

    protected $primaryKey = 'fldID';

    public $timestamps = false;

    protected $fillable = [
        'fldEmployeeNum',
        'fldRole',
        'fldGroups',
    ];

    protected function casts(): array
    {
        return [
            'fldEmployeeNum' => 'integer',
            'fldRole' => 'integer',
        ];
    }

    /**
     * fldGroups is a PHP-serialized array of group abbreviations, e.g. ["ENV", "BOI"].
     *
     * @return list<string>
     */
    public function groupsArray(): array
    {
        $groups = @unserialize((string) $this->fldGroups, ['allowed_classes' => false]);

        if (! is_array($groups)) {
            return [];
        }

        return array_values(array_filter($groups, fn ($value) => is_string($value) && $value !== ''));
    }
}
