<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_activity_history', function (Blueprint $table) {
            $table->dropForeign(['leave_id']);
        });

        Schema::table('leave_activity_history', function (Blueprint $table) {
            $table->unsignedBigInteger('leave_id')->nullable()->change();
            $table->string('activity_type', 50)->change();
            $table->unsignedBigInteger('subject_emp_id')->nullable()->after('leave_id');
            $table->foreignId('leave_type_id')
                ->nullable()
                ->after('subject_emp_id')
                ->constrained('leave_type')
                ->restrictOnDelete();
            $table->foreign('leave_id')->references('id')->on('leave_info')->cascadeOnDelete();
            $table->index('created_at');
            $table->index('subject_emp_id');
        });

        Schema::create('leave_activity_recipient', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('leave_activity_history')->cascadeOnDelete();
            $table->unsignedBigInteger('emp_id');
            $table->unique(['activity_id', 'emp_id']);
            $table->index('emp_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_activity_recipient');

        Schema::table('leave_activity_history', function (Blueprint $table) {
            $table->dropForeign(['leave_id']);
            $table->dropForeign(['leave_type_id']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['subject_emp_id']);
            $table->dropColumn(['subject_emp_id', 'leave_type_id']);
            $table->unsignedBigInteger('leave_id')->nullable(false)->change();
            $table->string('activity_type', 30)->change();
            $table->foreign('leave_id')->references('id')->on('leave_info')->cascadeOnDelete();
        });
    }
};
