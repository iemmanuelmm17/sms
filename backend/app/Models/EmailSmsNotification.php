<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** SMS-received notification email, kept so replies thread back to SMS. */
class EmailSmsNotification extends Model
{
    protected $fillable = ['domain', 'user', 'to_email', 'remote',
        'from_number', 'session_id', 'message_id'];
}
