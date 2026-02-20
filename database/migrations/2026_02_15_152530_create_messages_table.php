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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->text('whatsapp_message_id');
            $table->enum('direction',['inbound','outbound']);
            $table->string('type',255);
            $table->text('body');
            $table->string('status',255);
            $table->timestamp('message_timestamp');
            $table->string('failed_reason',255)->nullable();
            $table->timestamp('last_message_at',0);
            $table->timestamps();
            $table->foreign('conversation_id')
            ->references('id')
            ->on('conversations')
            ->cascadeOnDelete()
            ->cascadeOnUpdate();
        });
    }
/*      Field                 | Type      | Notes                                                                  |
| --------------------- | --------- | ---------------------------------------------------------------------- |
| `id`                  | bigint PK | internal auto-increment ID                                             |
| `conversation_id`     | bigint FK | links to `conversations.id`                                            |
| `whatsapp_message_id` | string    | WhatsApp-generated message ID (`messages[].id`) **unique per message** |
| `direction`           | enum      | `'inbound'` or `'outbound'`                                            |
| `type`                | string    | `text`, `image`, etc.                                                  |
| `body`                | text      | message content (`messages[].text.body` or media URL)                  |
| `status`              | string    | `pending`, `accepted`, `sent`, `delivered`, `read`, `failed`           |
| `message_timestamp`   | timestamp | WhatsApp timestamp of the message (`messages[].timestamp`)             |
| `failed_reason`       | string    | optional, if sending fails                                             |
| `created_at`          | timestamp | standard Laravel timestamp                                             |
| `updated_at`          | timestamp | standard Laravel timestamp                                             | */


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
