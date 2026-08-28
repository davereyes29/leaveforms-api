<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_info_attachment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_id')->constrained('leave_info')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['leave_id', 'sort_order']);
        });

        Schema::create('leave_conversion_attachment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversion_id')->constrained('leave_conversion')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['conversion_id', 'sort_order']);
        });

        if (Schema::hasColumn('leave_info', 'leave_attachment')) {
            foreach (DB::table('leave_info')
                ->whereNotNull('leave_attachment')
                ->where('leave_attachment', '!=', '')
                ->cursor() as $row) {
                DB::table('leave_info_attachment')->insert([
                    'leave_id' => $row->id,
                    'path' => $row->leave_attachment,
                    'original_name' => basename((string) $row->leave_attachment),
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Schema::table('leave_info', function (Blueprint $table) {
                $table->dropColumn('leave_attachment');
            });
        }

        if (Schema::hasColumn('leave_conversion', 'med_cert_attachment')) {
            foreach (DB::table('leave_conversion')
                ->whereNotNull('med_cert_attachment')
                ->where('med_cert_attachment', '!=', '')
                ->cursor() as $row) {
                DB::table('leave_conversion_attachment')->insert([
                    'conversion_id' => $row->id,
                    'path' => $row->med_cert_attachment,
                    'original_name' => basename((string) $row->med_cert_attachment),
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Schema::table('leave_conversion', function (Blueprint $table) {
                $table->dropColumn('med_cert_attachment');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('leave_info', 'leave_attachment')) {
            Schema::table('leave_info', function (Blueprint $table) {
                $table->string('leave_attachment')->nullable()->after('leave_reason');
            });
        }

        if (Schema::hasTable('leave_info_attachment')) {
            foreach (DB::table('leave_info_attachment')->orderBy('id')->cursor() as $row) {
                $current = DB::table('leave_info')->where('id', $row->leave_id)->value('leave_attachment');

                if ($current === null || $current === '') {
                    DB::table('leave_info')->where('id', $row->leave_id)->update([
                        'leave_attachment' => $row->path,
                    ]);
                }
            }
        }

        if (! Schema::hasColumn('leave_conversion', 'med_cert_attachment')) {
            Schema::table('leave_conversion', function (Blueprint $table) {
                $table->string('med_cert_attachment')->nullable()->after('to_leave_type_id');
            });
        }

        if (Schema::hasTable('leave_conversion_attachment')) {
            foreach (DB::table('leave_conversion_attachment')->orderBy('id')->cursor() as $row) {
                $current = DB::table('leave_conversion')->where('id', $row->conversion_id)->value('med_cert_attachment');

                if ($current === null || $current === '') {
                    DB::table('leave_conversion')->where('id', $row->conversion_id)->update([
                        'med_cert_attachment' => $row->path,
                    ]);
                }
            }
        }

        Schema::dropIfExists('leave_conversion_attachment');
        Schema::dropIfExists('leave_info_attachment');
    }
};
