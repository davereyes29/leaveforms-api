<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balance_transaction', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('emp_id');
            $table->foreignId('leave_id')->nullable()->constrained('leave_info')->nullOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_type')->restrictOnDelete();
            $table->decimal('available_delta', 8, 2);
            $table->decimal('reserved_delta', 8, 2);
            $table->decimal('available_after', 8, 2);
            $table->decimal('reserved_after', 8, 2);
            $table->string('reason', 40);
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['emp_id', 'created_at']);
            $table->index(['leave_id', 'reason']);
            $table->index(['leave_type_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balance_transaction');
    }
};
