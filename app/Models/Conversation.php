<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Conversation extends Model
{
    protected $table = 'conversations';
    protected $primaryKey = 'id';
    protected $fillable = [
        'wa_id',
        'phone_number',
        'name',
        'last_message_at',
        'status',
        'metadata',
        'created_at',
        'updated_at'
    ];
    public function messages():HasMany{
        return $this->hasMany(Message::class,'conversation_id','id');
    }
}
