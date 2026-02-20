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
        Schema::table('messages', function (Blueprint $table) {
            $table->string('file_mime_type')->nullable()->after('last_message_at');
            $table->text('file_sha256')->nullable()->after('file_mime_type');
            $table->string('file_id')->nullable()->after('file_sha256');
            $table->text('file_url')->nullable()->after('file_id');
            $table->boolean('is_file_voice')->nullable()->after('file_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages',function(Blueprint $table){
            $table->dropColumn('file_mime_type');
            $table->dropColumn('file_sha256');
            $table->dropColumn('file_id');
            $table->dropColumn('file_url');
            $table->dropColumn('is_file_voice');
        });
    }
};
