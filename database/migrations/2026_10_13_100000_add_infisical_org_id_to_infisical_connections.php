<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('infisical_connections', function (Blueprint $table) {
            $table->string('infisical_org_id')->nullable()->after('infisical_project_id');
        });
    }

    public function down(): void
    {
        Schema::table('infisical_connections', function (Blueprint $table) {
            $table->dropColumn('infisical_org_id');
        });
    }
};
