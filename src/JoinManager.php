<?php

namespace AfazTech\ReactorJoin;

use AfazTech\ReactorJoin\Contracts\ChannelProviderInterface;
use AfazTech\ReactorJoin\Contracts\JoinManagerInterface;
use AfazTech\ReactorJoin\Exceptions\BotNotAdminException;
use AfazTech\ReactorJoin\Exceptions\ChannelNotFoundException;
use AfazTech\ReactorJoin\Exceptions\JoinException;
use AfazTech\ReactorJoin\Models\ChannelMember;
use AfazTech\ReactorJoin\Models\RequiredChannel;
use AfazTech\ReactorJoin\Support\JoinConfig;
use Carbon\Carbon;
use Neili\Client;
use Neili\Exceptions\PermanentException;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\EventDispatcher;

/**
 * Default JoinManagerInterface implementation.
 *
 * The channel_members table is the single source of truth for
 * membership. Telegram pushes chat_member updates for every change, so
 * ChatMemberSyncMiddleware keeps the table fresh without polling.
 *
 * The Bot API is called only when the local record is missing or says
 * the user is not a member. This keeps normal traffic free of API
 * calls while still allowing a user who re-joined (and whose
 * chat_member update may still be in flight) to pass the check
 * immediately.
 */
class JoinManager implements JoinManagerInterface
{
    private ?int $botId = null;

    public function __construct(
        private Client $client,
        private ChannelProviderInterface $provider,
        private JoinConfig $config,
        private EventDispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function addChannel(string $identifier, array $options = []): RequiredChannel
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new JoinException('Channel identifier is required.');
        }

        $lookup = $this->normalizeIdentifier($identifier);

        try {
            $response = $this->client->getChat($lookup)->await();
        } catch (PermanentException $e) {
            throw new ChannelNotFoundException(
                "Cannot resolve channel '{$identifier}': " . $e->getMessage(),
                0,
                $e
            );
        }

        if (empty($response['ok']) || empty($response['result'])) {
            throw new ChannelNotFoundException("Channel not found: {$identifier}");
        }

        $chat   = $response['result'];
        $chatId = (int) $chat['id'];
        $title  = (string) ($chat['title'] ?? $chat['username'] ?? $identifier);

        $this->assertBotIsAdmin($chatId, $title);

        $link = (string) ($options['link'] ?? '');
        if ($link === '') {
            if (!empty($chat['username'])) {
                $link = 'https://t.me/' . $chat['username'];
            } elseif (!empty($options['invite_link'])) {
                $link = (string) $options['invite_link'];
            } else {
                throw new JoinException(
                    'Cannot determine a link for this channel. '
                    . 'Provide a "link" option.'
                );
            }
        }

        if ($this->provider->findByChatId($chatId) !== null) {
            throw new JoinException("Channel already registered: {$title}");
        }

        return $this->provider->create([
            'chat_id'        => $chatId,
            'title'          => $title,
            'link'           => $link,
            'is_active'      => (bool) ($options['is_active'] ?? true),
            'target_members' => max(0, (int) ($options['target_members'] ?? 0)),
            'max_adds'       => max(0, (int) ($options['max_adds'] ?? 0)),
            'current_adds'   => 0,
        ]);
    }

    public function removeChannel(int $id): bool
    {
        return $this->provider->delete($id);
    }

    public function listChannels(): array
    {
        return $this->provider->all();
    }

    public function listActiveChannels(): array
    {
        return $this->provider->active();
    }

    public function setChannelActive(int $id, bool $active): bool
    {
        return $this->provider->setActive($id, $active);
    }

    public function missingChannels(int $userId): array
    {
        $channels = $this->provider->active();
        $missing  = [];

        foreach ($channels as $channel) {
            $state = $this->checkChannelMembership($userId, $channel);
            if ($state === false) {
                $missing[] = $channel;
            }
        }

        return $missing;
    }

    public function isMember(int $userId, int $channelId): bool
    {
        $channel = $this->provider->find($channelId);
        if ($channel === null) {
            return false;
        }

        $state = $this->checkChannelMembership($userId, $channel);
        return $state === true;
    }

    /**
     * Returns true when member, false when not a member, null when the
     * channel itself is unavailable and has just been deactivated.
     */
    private function checkChannelMembership(int $userId, RequiredChannel $channel): ?bool
    {
        $record = ChannelMember::query()
            ->where('user_id', $userId)
            ->where('channel_id', $channel->id)
            ->first();

        // Fast path: the table already says the user is a member.
        // ChatMemberSyncMiddleware flips this to false the moment the
        // user leaves, so trusting it here is safe.
        if ($record !== null && $record->is_joined) {
            return true;
        }

        // Slow path: either we have no record yet, or the record says
        // the user is not a member. Call the API once to confirm and
        // catch the case where the user re-joined and the chat_member
        // update has not been delivered yet.
        try {
            $response = $this->client->getChatMember($channel->chat_id, $userId)->await();
        } catch (PermanentException $e) {
            if ($this->isChannelUnavailableError($e->getMessage())) {
                $this->deactivateChannel($channel, 'bot_not_admin');
                $this->logger->warning('Required channel deactivated', [
                    'channel_id' => $channel->id,
                    'reason'     => $e->getMessage(),
                ]);
                return null;
            }

            // Any other permanent error (user not found, etc.) is
            // treated as "not a member".
            $this->persistRecord($userId, $channel->id, false);
            return false;
        } catch (\Throwable $e) {
            // Transient errors must not lock a user out of the bot.
            $this->logger->warning('Membership check failed', [
                'user_id'    => $userId,
                'channel_id' => $channel->id,
                'error'      => $e->getMessage(),
            ]);
            return true;
        }

        $member   = is_array($response['result'] ?? null) ? $response['result'] : [];
        $status   = (string) ($member['status'] ?? '');
        $isJoined = $this->statusMeansJoined($status, $member);

        $wasJoined = $record !== null && $record->is_joined;
        $this->persistRecord($userId, $channel->id, $isJoined);

        if ($isJoined && !$wasJoined) {
            $this->handleNewJoin($channel, $userId);
        }

        return $isJoined;
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

    private function persistRecord(int $userId, int $channelId, bool $isJoined): void
    {
        ChannelMember::updateOrCreate(
            ['user_id' => $userId, 'channel_id' => $channelId],
            [
                'is_joined' => $isJoined,
                'joined_at' => $isJoined ? Carbon::now() : null,
            ]
        );
    }

    private function handleNewJoin(RequiredChannel $channel, int $userId): void
    {
        $channel->current_adds = (int) $channel->current_adds + 1;
        $channel->save();

        $this->dispatcher->dispatch(Events::MEMBER_JOINED, $userId, $channel->id);

        if ($channel->max_adds > 0 && $channel->current_adds >= $channel->max_adds) {
            $channel->is_active = false;
            $channel->save();
            $this->dispatcher->dispatch(Events::CHANNEL_DEACTIVATED, $channel->id, 'max_adds_reached');
        }
    }

    private function deactivateChannel(RequiredChannel $channel, string $reason): void
    {
        if (!$channel->is_active) {
            return;
        }
        $channel->is_active = false;
        $channel->save();
        $this->dispatcher->dispatch(Events::CHANNEL_DEACTIVATED, $channel->id, $reason);
    }

    private function isChannelUnavailableError(string $message): bool
    {
        $needles = ['chat not found', 'not enough rights', 'bot is not a member', 'not a member of the chat'];
        $message = strtolower($message);
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function assertBotIsAdmin(int $chatId, string $title): void
    {
        $botId = $this->getBotId();
        if ($botId === 0) {
            throw new BotNotAdminException('Cannot determine the bot user id (getMe failed).');
        }

        try {
            $response = $this->client->getChatMember($chatId, $botId)->await();
        } catch (PermanentException $e) {
            throw new BotNotAdminException(
                "Cannot verify admin status in '{$title}': " . $e->getMessage(),
                0,
                $e
            );
        }

        $status = $response['result']['status'] ?? '';
        if (!in_array($status, ['administrator', 'creator'], true)) {
            throw new BotNotAdminException(
                "Bot must be an administrator in '{$title}' (current status: {$status})."
            );
        }
    }

    private function getBotId(): int
    {
        if ($this->botId !== null) {
            return $this->botId;
        }

        try {
            $response = $this->client->getMe()->await();
            $this->botId = (int) ($response['result']['id'] ?? 0);
        } catch (\Throwable $e) {
            $this->logger->warning('getMe failed', ['error' => $e->getMessage()]);
            $this->botId = 0;
        }

        return $this->botId;
    }

    /**
     * @return int|string
     */
    private function normalizeIdentifier(string $identifier): int|string
    {
        if (str_starts_with($identifier, '@')) {
            return $identifier;
        }

        if (preg_match('#^(?:https?://)?t\.me/(?:joinchat/|\+)?(.+)$#i', $identifier, $m)) {
            $slug = $m[1];
            if (str_starts_with($slug, '+') || str_contains(strtolower($identifier), 'joinchat')) {
                throw new JoinException(
                    'Private invite links cannot be resolved automatically. '
                    . 'Provide the numeric chat_id (e.g., -1001234567890) instead.'
                );
            }
            return '@' . $slug;
        }

        if (is_numeric($identifier)) {
            return (int) $identifier;
        }

        return '@' . $identifier;
    }
}
