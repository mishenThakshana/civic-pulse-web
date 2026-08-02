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
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('citizen')->after('email');
            $table->string('phone')->nullable()->after('role');
            $table->string('nic_number')->nullable()->after('phone');
            $table->string('nic_photo_front_path')->nullable()->after('nic_number');
            $table->string('nic_photo_back_path')->nullable()->after('nic_photo_front_path');
            $table->string('approval_status')->default('pending')->after('nic_photo_back_path');
            $table->timestamp('approved_at')->nullable()->after('approval_status');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('approved_by');
            $table->boolean('is_active')->default(true)->after('rejection_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn([
                'role', 'phone', 'nic_number', 'nic_photo_front_path', 'nic_photo_back_path',
                'approval_status', 'approved_at', 'approved_by', 'rejection_reason', 'is_active',
            ]);
        });
    }
};
