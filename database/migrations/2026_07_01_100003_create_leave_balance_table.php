<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('emp_id');
            $table->foreignId('leave_type_id')->constrained('leave_type')->restrictOnDelete();
            $table->decimal('available', 8, 2)->default(0);
            $table->decimal('reserved', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['emp_id', 'leave_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balance');
    }
};
