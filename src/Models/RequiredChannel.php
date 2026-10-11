<?php

namespace AfazTech\ReactorJoin\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A channel that users must be a member of to use the bot.
 */
class RequiredChannel extends Model
{
    protected $table = 'required_channels';

    protected $fillable = [
        'chat_id',
        'title',
        'link',
        'is_active',
        'target_members',
        'max_adds',
        'current_adds',
    ];

    protected $casts = [
        'chat_id'        => 'integer',
        'is_active'      => 'boolean',
        'target_members' => 'integer',
        'max_adds'       => 'integer',
        'current_adds'   => 'integer',
    ];
}
