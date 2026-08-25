<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_admin_reminder', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->unsignedBigInteger('sent_by_emp_id');
            $table->unsignedBigInteger('leave_id')->nullable();
            $table->unsignedBigInteger('target_emp_id')->nullable();
            $table->unsignedInteger('approver_count');
            $table->unsignedInteger('request_count');
            $table->unsignedInteger('email_count');
            $table->dateTime('sent_at');
            $table->timestamps();

            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_admin_reminder');
    }
};
