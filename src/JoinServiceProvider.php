<?php

namespace AfazTech\ReactorJoin;

use AfazTech\ReactorJoin\Contracts\ChannelProviderInterface;
use AfazTech\ReactorJoin\Contracts\JoinManagerInterface;
use AfazTech\ReactorJoin\Support\DatabaseChannelProvider;
use AfazTech\ReactorJoin\Support\JoinConfig;
use Reactor\Contracts\ServiceProviderInterface;
use Reactor\Core\Container;
use Reactor\Enums\MiddlewareMode;

/**
 * Registers join package services and wires the package's middleware.
 *
 * Config binding is provided as an empty default. The host application
 * overrides JoinConfig in its own service provider to customise the
 * message keys, exemptions and admin list.
 *
 * This package ships no handler and no language files: it exposes the
 * JoinManagerInterface API and the two middlewares that implement the
 * core logic (ChatMemberSyncMiddleware for Telegram state, and
 * ForceJoinMiddleware for enforcement).
 *
 * IMPORTANT — join:check callback:
 *   ForceJoinMiddleware sends a "join:check" callback button in its
 *   prompt. Because this package no longer ships a handler, the host
 *   application MUST implement one, e.g.:
 *
 *     #[Callback(data: 'join:check', priority: 200)]
 *     #[OnUpdate('callback_query')]
 *     class JoinCheckHandler extends BaseHandler
 *     {
 *         public function __construct(
 *             Client $client,
 *             LanguageInterface $language,
 *             LoggerInterface $logger,
 *             UserRepositoryInterface $users,
 *             private JoinManagerInterface $joinManager,
 *         ) {
 *             parent::__construct($client, $language, $logger, $users);
 *         }
 *
 *         protected function handle(array $params = []): void
 *         {
 *             $userId = $this->getUserId();
 *             $missing = $this->joinManager->missingChannels($userId);
 *             // Show success or re-render the prompt yourself.
 *         }
 *     }
 *
 *   Alternatively, remove ForceJoinMiddleware from the registration
 *   list below and enforce channel membership from your own middleware
 *   (using JoinManagerInterface::missingChannels()).
 */
class JoinServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        if (!$container->has(JoinConfig::class)) {
            $container->singleton(JoinConfig::class, function () {
                return new JoinConfig();
            });
        }

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
                    $c->get(JoinConfig::class),
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
            [
                'class'          => \AfazTech\ReactorJoin\Middleware\ForceJoinMiddleware::class,
                'priority'       => 30,
                'mode'           => MiddlewareMode::GLOBAL,
                'groups'         => [],
                'allowedUpdates' => ['message', 'callback_query'],
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
