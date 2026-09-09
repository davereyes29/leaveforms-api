<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_info', function (Blueprint $table) {
            $table->boolean('quarterly_vl_excess_notified')->default(false)->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('leave_info', function (Blueprint $table) {
            $table->dropColumn('quarterly_vl_excess_notified');
        });
    }
};
