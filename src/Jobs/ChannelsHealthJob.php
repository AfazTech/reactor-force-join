<?php

namespace AfazTech\ReactorJoin\Jobs;

use AfazTech\ReactorJoin\Contracts\ChannelProviderInterface;
use AfazTech\ReactorJoin\Events;
use AfazTech\ReactorJoin\Models\RequiredChannel;
use Neili\Client;
use Reactor\Contracts\JobInterface;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\EventDispatcher;

/**
 * Periodic health check for required channels.
 *
 * The job fetches the current member count of every active channel and
 * deactivates it once the count reaches its configured target_members
 * value. Schedule the job from your ScheduleKernel, e.g.:
 *
 *   $schedule->job(\AfazTech\ReactorJoin\Jobs\ChannelsHealthJob::class)
 *       ->everyThirtyMinutes();
 *
 * Nothing happens for channels where target_members is 0 (no limit).
 */
class ChannelsHealthJob implements JobInterface
{
    public function __construct(
        private Client $client,
        private ChannelProviderInterface $provider,
        private EventDispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(array $data): void
    {
        foreach ($this->provider->active() as $channel) {
            if ($channel->target_members <= 0) {
                continue;
            }
            $this->checkOne($channel);
        }
    }

    private function checkOne(RequiredChannel $channel): void
    {
        try {
            $response = $this->client->getChatMemberCount($channel->chat_id)->await();
        } catch (\Throwable $e) {
            $this->logger->warning('Channel member count fetch failed', [
                'channel_id' => $channel->id,
                'error'      => $e->getMessage(),
            ]);
            return;
        }

        $count = (int) ($response['result'] ?? 0);
        if ($count < $channel->target_members) {
            return;
        }

        $channel->is_active = false;
        $channel->save();

        $this->dispatcher->dispatch(Events::CHANNEL_DEACTIVATED, $channel->id, 'target_members_reached');

        $this->logger->info('Required channel deactivated (target_members reached)', [
            'channel_id' => $channel->id,
            'count'      => $count,
            'target'     => $channel->target_members,
        ]);
    }
}
