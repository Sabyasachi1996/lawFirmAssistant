<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $table = 'messages';
    protected $primaryKey = 'id';
    protected $fillable = [
        'conversation_id',
        'whatsapp_message_id',
        'direction',
        'type',
        'body',
        'status',
        'message_timestamp',
        'failed_reason',
        'file_mime_type',
        'file_sha256',
        'file_id',
        'file_url',
        'is_file_voice',
        'embedding',
        'created_at',
        'updated_at'
    ];
    public function conversation():BelongsTo{
        return $this->belongsTo(Conversation::class,'conversation_id','id');
    }
}
