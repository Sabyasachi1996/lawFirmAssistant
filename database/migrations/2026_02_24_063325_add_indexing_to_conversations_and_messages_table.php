<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unique('wa_id');
            $table->index('status');
        });
        Schema::table('messages',function(Blueprint $table){
            $table->unique('whatsapp_message_id');
            $table->index(['conversation_id','created_at'],'msg_history_and_context_fetch_idx');
            $table->index('conversation_id');
            $table->index('direction');
        });
        DB::statement('CREATE INDEX IF NOT EXISTS msg_vector_hnsw_idx ON messages USING hnsw (embedding vector_l2_ops);');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['wa_id']);
            $table->dropIndex(['status']);
        });
        Schema::table('messages',function(Blueprint $table){
            $table->dropUnique(['whatsapp_message_id']);
            $table->dropIndex('msg_history_and_context_fetch_idx');
            $table->dropIndex(['conversation_id']);
            $table->dropIndex(['direction']);
        });
        DB::statement('DROP INDEX IF EXISTS msg_vector_hnsw_idx;');
    }
};
