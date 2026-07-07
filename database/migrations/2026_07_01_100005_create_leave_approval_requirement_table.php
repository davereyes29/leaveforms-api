<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_approval_requirement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_id')->constrained('leave_info')->cascadeOnDelete();
            $table->unsignedTinyInteger('display_order');
            $table->json('allowed_levels')->nullable();
            $table->string('required_approver_type', 20)->nullable();
            $table->boolean('is_satisfied')->default(false);
            $table->dateTime('satisfied_at')->nullable();
            $table->timestamps();

            $table->unique(['leave_id', 'display_order']);
            $table->index(['leave_id', 'is_satisfied']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_approval_requirement');
    }
};
