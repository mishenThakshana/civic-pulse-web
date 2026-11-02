<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('report_status_history', function (Blueprint $table) {
            $table->foreignId('changed_by_department_id')->nullable()->after('changed_by')->constrained('departments')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('report_status_history', function (Blueprint $table) {
            $table->dropConstrainedForeignId('changed_by_department_id');
        });
    }
};
