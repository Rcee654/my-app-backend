<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'business_name')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('business_name')->default('')->after('id');
                $table->enum('role', ['admin', 'staff'])->default('admin')->after('password');
                $table->enum('status', ['active', 'inactive'])->default('active')->after('role');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'business_name')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn(['business_name', 'role', 'status']);
            });
        }
    }
};
