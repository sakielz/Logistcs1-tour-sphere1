<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $schemaFile = base_path('database/supabase_schema.sql');
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            if (!empty($sql)) {
                DB::unprepared($sql);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep intact for safety
    }
};
