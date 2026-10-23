<?php

namespace AfazTech\ReactorJoin\Middleware;

use AfazTech\ReactorJoin\Contracts\ChannelProviderInterface;
use AfazTech\ReactorJoin\Contracts\JoinManagerInterface;
use AfazTech\ReactorJoin\Events;
use AfazTech\ReactorJoin\Models\ChannelMember;
use Reactor\Attributes\Middleware;
use Reactor\Attributes\OnUpdate;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\MiddlewareInterface;
use Reactor\Core\EventDispatcher;
use Reactor\Enums\MiddlewareMode;
use Carbon\Carbon;

/**
 * Keeps the channel_members table in sync with the Telegram chat_member
 * stream, and enforces the target_members / max_adds rules on every
 * join.
 *
 * - On chat_member: updates the local membership record of the user
 *   whose status changed, increments the channel's join counter on
 *   transitions from not-joined to joined, dispatches MEMBER_JOINED,
 *   and calls JoinManagerInterface::enforceLimits() so that a channel
 *   that just reached target_members or max_adds is deactivated
 *   immediately — no polling, no scheduled job.
 * - On my_chat_member: deactivates the channel when the bot itself is
 *   removed from it, because membership can no longer be verified.
 *
 * Returns true in both cases to short-circuit the pipeline so that no
 * user-facing handler reacts to these system updates.
 */
#[Middleware(priority: 40, mode: MiddlewareMode::GLOBAL)]
#[OnUpdate('chat_member,my_chat_member')]
class ChatMemberSyncMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ChannelProviderInterface $provider,
        private JoinManagerInterface $joinManager,
        private EventDispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(array $update): bool
    {
        if (isset($update['chat_member']) && is_array($update['chat_member'])) {
            $this->handleChatMember($update['chat_member']);
            return true;
        }

        if (isset($update['my_chat_member']) && is_array($update['my_chat_member'])) {
            $this->handleMyChatMember($update['my_chat_member']);
            return true;
        }

        return false;
    }

    private function handleChatMember(array $data): void
    {
        $chatId = $data['chat']['id'] ?? null;

        // The affected user is the one whose membership changed, not
        // the actor that triggered the change. When an administrator
        // kicks someone, from.id is the admin while
        // new_chat_member.user.id is the victim; using from.id here
        // would corrupt the victim's record.
        $userId    = $data['new_chat_member']['user']['id'] ?? null;
        $newStatus = $data['new_chat_member']['status'] ?? null;

        if ($chatId === null || $userId === null || $newStatus === null) {
            return;
        }

        $channel = $this->provider->findByChatId((int) $chatId);
        if ($channel === null) {
            return;
        }

        $isJoined = $this->statusMeansJoined($newStatus, $data['new_chat_member']);

        $record = ChannelMember::query()
            ->where('user_id', (int) $userId)
            ->where('channel_id', $channel->id)
            ->first();

        $wasJoined = $record !== null && $record->is_joined;

        ChannelMember::updateOrCreate(
            ['user_id' => (int) $userId, 'channel_id' => $channel->id],
            [
                'is_joined' => $isJoined,
                'joined_at' => $isJoined ? Carbon::now() : null,
            ]
        );

        if ($isJoined && !$wasJoined) {
            $channel->current_adds = (int) $channel->current_adds + 1;
            $channel->save();

            $this->dispatcher->dispatch(Events::MEMBER_JOINED, (int) $userId, $channel->id);

            // Immediate enforcement: target_members and max_adds are
            // both evaluated here so a channel that just crossed a
            // threshold is deactivated on this very update.
            $this->joinManager->enforceLimits((int) $channel->id);
        } elseif (!$isJoined && $wasJoined) {
            $this->dispatcher->dispatch(Events::MEMBER_LEFT, (int) $userId, $channel->id);
        }
    }

    private function handleMyChatMember(array $data): void
    {
        $chatId    = $data['chat']['id'] ?? null;
        $newStatus = $data['new_chat_member']['status'] ?? null;

        if ($chatId === null || $newStatus === null) {
            return;
        }

        if (!in_array($newStatus, ['left', 'kicked'], true)) {
            return;
        }

        $channel = $this->provider->findByChatId((int) $chatId);
        if ($channel === null || !$channel->is_active) {
            return;
        }

        $channel->is_active = false;
        $channel->save();
        $this->dispatcher->dispatch(Events::CHANNEL_DEACTIVATED, $channel->id, 'bot_left');

        $this->logger->warning('Required channel deactivated: bot is no longer a member', [
            'channel_id' => $channel->id,
            'chat_id'    => (int) $chatId,
        ]);
    }

    /**
     * Translate a Telegram ChatMember status into a boolean.
     *
     * "restricted" is ambiguous: a restricted user may still be a
     * member of the chat (with limited permissions) or may have been
     * removed. The Bot API exposes the actual truth through the
     * is_member field of ChatMemberRestricted, so we consult it
     * instead of guessing.
     *
     * @param array<string, mixed> $member
     */
    private function statusMeansJoined(string $status, array $member): bool
    {
        return match ($status) {
            'member', 'administrator', 'creator' => true,
            'restricted'                         => (bool) ($member['is_member'] ?? false),
            default                              => false,
        };
    }
}
