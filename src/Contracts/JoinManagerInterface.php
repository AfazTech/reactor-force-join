<?php

namespace AfazTech\ReactorJoin\Contracts;

use AfazTech\ReactorJoin\Exceptions\BotNotAdminException;
use AfazTech\ReactorJoin\Exceptions\ChannelNotFoundException;
use AfazTech\ReactorJoin\Exceptions\JoinException;
use AfazTech\ReactorJoin\Models\RequiredChannel;

/**
 * Public API of the join package.
 *
 * The manager owns channel configuration and membership state. It does
 * NOT enforce anything by itself — enforcement is the host
 * application's responsibility, using missingChannels() / isMember().
 *
 * A single middleware ships with the package (ChatMemberSyncMiddleware)
 * and its only job is to keep the local cache fresh: it listens to
 * chat_member / my_chat_member updates and calls enforceLimits() after
 * each join so that target_members and max_adds thresholds are checked
 * immediately, without any polling.
 */
interface JoinManagerInterface
{
    /**
     * Register a required channel.
     *
     * @param string $identifier Either "@username", "username",
     *                           "t.me/username", or a numeric chat_id
     *                           like -1001234567890.
     * @param array{
     *     title?: string,
     *     link?: string,
     *     target_members?: int,
     *     max_adds?: int,
     *     is_active?: bool
     * } $options
     *
     * @throws ChannelNotFoundException When the channel cannot be resolved.
     * @throws BotNotAdminException     When the bot is not an admin.
     * @throws JoinException            For duplicate channels or missing link.
     */
    public function addChannel(string $identifier, array $options = []): RequiredChannel;

    /**
     * Remove a channel and its membership records.
     */
    public function removeChannel(int $id): bool;

    /**
     * List every required channel (active and inactive).
     *
     * @return array<int, RequiredChannel>
     */
    public function listChannels(): array;

    /**
     * List only the active required channels.
     *
     * @return array<int, RequiredChannel>
     */
    public function listActiveChannels(): array;

    /**
     * Return a single channel by its primary key, or null.
     */
    public function getChannel(int $id): ?RequiredChannel;

    /**
     * Enable or disable a channel without removing it.
     */
    public function setChannelActive(int $id, bool $active): bool;

    /**
     * Set the target_members threshold for a channel.
     *
     * When the channel reaches this many Telegram members, it is
     * deactivated automatically (subject to enforcement, see the
     * class-level notes). Passing 0 disables the rule.
     *
     * The check runs immediately after the value is saved (one
     * getChatMemberCount API call), so a channel already above the
     * threshold is deactivated at set-time.
     */
    public function setChannelTargetMembers(int $channelId, int $target): bool;

    /**
     * Set the max_adds threshold for a channel.
     *
     * When the bot has driven this many joins to the channel, it is
     * deactivated automatically. Passing 0 disables the rule.
     *
     * The check runs immediately after the value is saved, so a
     * channel whose current_adds is already at or above the new limit
     * is deactivated at set-time.
     */
    public function setChannelMaxAdds(int $channelId, int $max): bool;

    /**
     * Reset the current_adds counter of a channel to zero.
     *
     * Useful when re-enabling a channel whose max_adds rule had
     * already fired, or when starting a new counting period.
     */
    public function resetChannelCurrentAdds(int $channelId): bool;

    /**
     * Evaluate the target_members and max_adds rules for a channel.
     *
     * If either rule triggers, the channel is deactivated and the
     * CHANNEL_DEACTIVATED event is dispatched with the reason
     * ('target_members_reached' or 'max_adds_reached'). Returns true
     * when the channel was deactivated by this call.
     *
     * The middleware calls this after every chat_member join. Host
     * applications may also call it explicitly (e.g. from an admin
     * command that adds members by hand).
     */
    public function enforceLimits(int $channelId): bool;

    /**
     * Return the channels the user still has to join.
     *
     * The local channel_members table is consulted first; the Bot API
     * is only called for channels where the stored state is missing or
     * says the user is not a member.
     *
     * @return array<int, RequiredChannel>
     */
    public function missingChannels(int $userId): array;

    /**
     * Check whether the user is currently a member of the given channel.
     */
    public function isMember(int $userId, int $channelId): bool;
}
