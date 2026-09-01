<?php

namespace App\Models\Kdtph;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Job title / designation lookup.
 *
 * Table: kdtphdb_new.designation_list
 * Current title is emp_prof.fldDesig (acronym) → this table.
 * employee_list.designation is a stale integer FK; used only as fallback.
 */
class Designation extends Model
{
    /**
     * designation_list has two KDTP rows (29 KDT President, 50 President).
     * The company uses 29 only; emp_prof.fldDesig = KDTP always resolves here.
     */
    public const KDT_PRESIDENT_ID = 29;

    public const KDT_PRESIDENT_ACRONYM = 'KDTP';

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

    /**
     * Resolve a designation_list row from emp_prof.fldDesig (acronym).
     * KDTP always maps to id 29. Unknown / empty / "-" → null.
     *
     * @param  list<string>  $acronyms
     * @return Collection<string, self>
     */
    public static function mapByAcronyms(array $acronyms): Collection
    {
        $acronyms = array_values(array_unique(array_filter(
            $acronyms,
            fn ($acronym): bool => is_string($acronym) && $acronym !== '' && $acronym !== '-',
        )));

        if ($acronyms === []) {
            return collect();
        }

        return static::query()
            ->whereIn('acronym', $acronyms)
            ->get()
            ->groupBy('acronym')
            ->map(function (Collection $group, string $acronym): ?self {
                if (strcasecmp($acronym, self::KDT_PRESIDENT_ACRONYM) === 0) {
                    return $group->firstWhere('id', self::KDT_PRESIDENT_ID) ?? $group->sortBy('id')->first();
                }

                return $group->first();
            })
            ->filter();
    }

    public static function findByAcronym(?string $acronym): ?self
    {
        if ($acronym === null) {
            return null;
        }

        return self::mapByAcronyms([$acronym])->get($acronym);
    }
}
