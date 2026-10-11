<?php

namespace AfazTech\ReactorJoin\Contracts;

use AfazTech\ReactorJoin\Exceptions\BotNotAdminException;
use AfazTech\ReactorJoin\Exceptions\ChannelNotFoundException;
use AfazTech\ReactorJoin\Exceptions\JoinException;
use AfazTech\ReactorJoin\Models\RequiredChannel;

/**
 * Public API of the join package.
 *
 * All channel-mutating methods validate state against the Telegram Bot
 * API before touching the database, so a misconfigured channel can
 * never end up in the required list.
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
     * Enable or disable a channel without removing it.
     */
    public function setChannelActive(int $id, bool $active): bool;

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
     *
     * Uses the same local-first strategy as missingChannels(): the
     * channel_members row is the source of truth, and a fresh
     * getChatMember call is made only when the row is absent or says
     * the user is not a member.
     */
    public function isMember(int $userId, int $channelId): bool;
}
