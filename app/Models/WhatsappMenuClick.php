<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Anonymous menu click: only topic, page path and time. Never add IP, user agent or identifiers. */
class WhatsappMenuClick extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
