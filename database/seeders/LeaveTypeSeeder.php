<?php

namespace Database\Seeders;

use App\Models\Leave\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    /**
     * Seed leave type lookup rows from LEGACY_NOTES.md mapping.
     */
    public function run(): void
    {
        $types = [
            ['code' => 'VL',    'type' => 'Vacation Leave'],
            ['code' => 'SL',    'type' => 'Sick Leave'],
            ['code' => 'EL',    'type' => 'Emergency Leave'],
            ['code' => 'ML',    'type' => 'Maternity Leave'],
            ['code' => 'PL',    'type' => 'Paternity Leave'],
            ['code' => 'PTB',   'type' => 'Pulmonary Tuberculosis Leave'],
            ['code' => 'LL',    'type' => 'Long Leave'],
            ['code' => 'SPL',   'type' => 'Special Leave'],
        ];

        foreach ($types as $row) {
            LeaveType::query()->updateOrCreate(
                ['code' => $row['code']],
                ['type' => $row['type']],
            );
        }
    }
}
