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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->text('wa_id');
            $table->string('phone_number',15);
            $table->string('name',100)->nullable();
            $table->timestamp('last_message_at',0);
            $table->timestamps();
        });
    }
    /* | Field             | Type      | Notes                                               |
| ----------------- | --------- | --------------------------------------------------- |
| `id`              | bigint PK | auto-increment internal ID                          |
| `wa_id`           | string    | client’s WhatsApp ID (unique per client)            |
| `phone_number`    | string    | actual phone number (optional, for display)         |
| `name`            | string    | client name from payload `contacts[0].profile.name` |
| `last_message_at` | timestamp | latest message received or sent                     |
| `created_at`      | timestamp | standard Laravel timestamps                         |
| `updated_at`      | timestamp | standard Laravel timestamps                         | */

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
