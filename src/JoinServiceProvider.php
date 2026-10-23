<?php

namespace AfazTech\ReactorJoin;

use AfazTech\ReactorJoin\Contracts\ChannelProviderInterface;
use AfazTech\ReactorJoin\Contracts\JoinManagerInterface;
use AfazTech\ReactorJoin\Support\DatabaseChannelProvider;
use Reactor\Contracts\ServiceProviderInterface;
use Reactor\Core\Container;
use Reactor\Enums\MiddlewareMode;

/**
 * Registers join package services and wires the package's middleware.
 *
 * This package is API-only for enforcement: it exposes
 * JoinManagerInterface (addChannel, removeChannel, setChannelActive,
 * setChannelTargetMembers, setChannelMaxAdds, enforceLimits,
 * missingChannels, isMember, ...) and does NOT install any middleware
 * that blocks users.
 *
 * The single middleware that ships with the package,
 * ChatMemberSyncMiddleware, exists to keep the local channel_members
 * cache fresh: it listens to Telegram's chat_member / my_chat_member
 * updates and, on every join, calls JoinManagerInterface::enforceLimits()
 * so that the target_members and max_adds thresholds are evaluated on
 * every change instead of on a schedule. Removing it would make the
 * local cache stale — a user who left a required channel would
 * continue to pass missingChannels() because the DB still says
 * is_joined = true — so this middleware is core to the package's
 * correctness, not a policy.
 *
 * Enforcement is the host application's job. A minimal middleware that
 * blocks users who have not joined the required channels:
 *
 *     #[Middleware(priority: 30, mode: MiddlewareMode::GLOBAL)]
 *     #[OnUpdate('message,callback_query')]
 *     class MyForceJoinMiddleware implements MiddlewareInterface
 *     {
 *         public function __construct(
 *             private JoinManagerInterface $joinManager,
 *             private \Neili\Client $client,
 *         ) {}
 *
 *         public function handle(array $update): bool
 *         {
 *             $userId = $update['message']['from']['id']
 *                 ?? $update['callback_query']['from']['id']
 *                 ?? null;
 *             if ($userId === null) {
 *                 return false;
 *             }
 *
 *             $missing = $this->joinManager->missingChannels((int) $userId);
 *             if ($missing === []) {
 *                 return false;
 *             }
 *
 *             // Build and send your own prompt, keyboard, callback
 *             // handler — this package does not ship any of it.
 *             // ... $this->client->sendMessage(...);
 *             return true;
 *         }
 *     }
 */
class JoinServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        if (!$container->has(ChannelProviderInterface::class)) {
            $container->singleton(ChannelProviderInterface::class, function () {
                return new DatabaseChannelProvider();
            });
        }

        if (!$container->has(JoinManagerInterface::class)) {
            $container->singleton(JoinManagerInterface::class, function (Container $c) {
                return new JoinManager(
                    $c->get(\Neili\Client::class),
                    $c->get(ChannelProviderInterface::class),
                    $c->get(\Reactor\Core\EventDispatcher::class),
                    $c->get(\Reactor\Contracts\LoggerInterface::class),
                );
            });
        }
    }

    public function boot(Container $container): void
    {
        $this->registerMiddleware($container);
    }

    private function registerMiddleware(Container $container): void
    {
        $existing = $container->has('middlewares') ? ($container->get('middlewares') ?? []) : [];

        $ours = [
            [
                'class'          => \AfazTech\ReactorJoin\Middleware\ChatMemberSyncMiddleware::class,
                'priority'       => 40,
                'mode'           => MiddlewareMode::GLOBAL,
                'groups'         => [],
                'allowedUpdates' => ['chat_member', 'my_chat_member'],
            ],
        ];

        $known = [];
        foreach ($existing as $row) {
            if (isset($row['class'])) {
                $known[$row['class']] = true;
            }
        }

        foreach ($ours as $row) {
            if (isset($known[$row['class']])) {
                continue;
            }
            $existing[] = $row;
        }

        usort($existing, function (array $a, array $b): int {
            return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
        });

        $container->forgetInstance('middlewares');
        $container->singleton('middlewares', fn () => $existing);
    }
}
