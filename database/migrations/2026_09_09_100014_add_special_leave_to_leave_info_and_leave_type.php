<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_info', function (Blueprint $table) {
            $table->string('assignment_type', 40)->nullable()->after('leave_reason');
            $table->string('special_leave_reason', 60)->nullable()->after('assignment_type');
        });

        DB::table('leave_type')->updateOrInsert(
            ['code' => 'SPL'],
            ['type' => 'Special Leave'],
        );
    }

    public function down(): void
    {
        Schema::table('leave_info', function (Blueprint $table) {
            $table->dropColumn(['assignment_type', 'special_leave_reason']);
        });

        DB::table('leave_type')->where('code', 'SPL')->delete();
    }
};
