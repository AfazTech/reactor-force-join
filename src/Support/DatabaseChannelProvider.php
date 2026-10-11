<?php

namespace AfazTech\ReactorJoin\Support;

use AfazTech\ReactorJoin\Contracts\ChannelProviderInterface;
use AfazTech\ReactorJoin\Models\ChannelMember;
use AfazTech\ReactorJoin\Models\RequiredChannel;

/**
 * Default ChannelProviderInterface implementation backed by Eloquent.
 */
class DatabaseChannelProvider implements ChannelProviderInterface
{
    public function active(): array
    {
        return RequiredChannel::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function all(): array
    {
        return RequiredChannel::query()->orderBy('id')->get()->all();
    }

    public function find(int $id): ?RequiredChannel
    {
        return RequiredChannel::find($id);
    }

    public function findByChatId(int $chatId): ?RequiredChannel
    {
        return RequiredChannel::query()->where('chat_id', $chatId)->first();
    }

    public function create(array $data): RequiredChannel
    {
        return RequiredChannel::create($data);
    }

    public function delete(int $id): bool
    {
        $channel = RequiredChannel::find($id);
        if (!$channel) {
            return false;
        }

        ChannelMember::query()->where('channel_id', $channel->id)->delete();
        return (bool) $channel->delete();
    }

    public function setActive(int $id, bool $active): bool
    {
        $channel = RequiredChannel::find($id);
        if (!$channel) {
            return false;
        }
        $channel->is_active = $active;
        return (bool) $channel->save();
    }
}
