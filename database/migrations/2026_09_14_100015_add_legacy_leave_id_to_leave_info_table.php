<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_info', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_leave_id')->nullable()->after('id');
            $table->unique('legacy_leave_id');
        });
    }

    public function down(): void
    {
        Schema::table('leave_info', function (Blueprint $table) {
            $table->dropUnique(['legacy_leave_id']);
            $table->dropColumn('legacy_leave_id');
        });
    }
};
