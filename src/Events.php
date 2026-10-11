<?php

namespace AfazTech\ReactorJoin;

/**
 * Event names dispatched by the join package.
 *
 * Consumers subscribe through the framework's EventDispatcher:
 *
 *   $dispatcher->on(\AfazTech\ReactorJoin\Events::MEMBER_LEFT,
 *       function (int $userId, int $channelId) { ... });
 */
final class Events
{
    /**
     * Fired when a user passes the join check with no missing channels.
     * Arguments: (int $userId)
     */
    public const JOIN_PASSED = 'join.passed';

    /**
     * Fired when a user is blocked by the join middleware.
     * Arguments: (int $userId, array $missingChannels)
     */
    public const JOIN_BLOCKED = 'join.blocked';

    /**
     * Fired when a user joins a required channel.
     * Arguments: (int $userId, int $channelId)
     */
    public const MEMBER_JOINED = 'join.member.joined';

    /**
     * Fired when a user leaves a required channel.
     * Arguments: (int $userId, int $channelId)
     */
    public const MEMBER_LEFT = 'join.member.left';

    /**
     * Fired when a required channel is deactivated automatically.
     * Arguments: (int $channelId, string $reason)
     */
    public const CHANNEL_DEACTIVATED = 'join.channel.deactivated';
}
