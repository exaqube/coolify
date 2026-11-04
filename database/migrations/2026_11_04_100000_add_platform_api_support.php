<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns and the share pivot for the platform API (`/api/v1/platform`).
     * Additive only: existing rows keep their current behaviour through the defaults.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // Only platform-created teams get a uuid; existing teams stay null.
            $table->string('uuid')->nullable()->unique();
            $table->boolean('is_platform_managed')->default(false);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_service_account')->default(false);
        });

        Schema::table('github_apps', function (Blueprint $table) {
            $table->boolean('is_shareable')->default(false);
        });

        Schema::create('github_app_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('github_app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['github_app_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_app_team');

        Schema::table('github_apps', function (Blueprint $table) {
            $table->dropColumn('is_shareable');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_service_account');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'is_platform_managed']);
        });
    }
};
