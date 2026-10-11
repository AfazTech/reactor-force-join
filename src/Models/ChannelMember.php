<?php

namespace AfazTech\ReactorJoin\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tracks a user's membership status in a required channel.
 *
 * The unique (user_id, channel_id) pair means each row represents the
 * current state of one user in one channel. Rows are created lazily on
 * the first membership check and updated by the chat_member sync
 * middleware.
 */
class ChannelMember extends Model
{
    protected $table = 'channel_members';

    protected $fillable = [
        'user_id',
        'channel_id',
        'is_joined',
        'joined_at',
    ];

    protected $casts = [
        'user_id'   => 'integer',
        'channel_id' => 'integer',
        'is_joined' => 'boolean',
        'joined_at' => 'datetime',
    ];
}
