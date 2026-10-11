<?php

namespace AfazTech\ReactorJoin;

use AfazTech\ReactorJoin\Contracts\ChannelProviderInterface;
use AfazTech\ReactorJoin\Contracts\JoinManagerInterface;
use AfazTech\ReactorJoin\Support\DatabaseChannelProvider;
use AfazTech\ReactorJoin\Support\JoinConfig;
use Reactor\Contracts\ServiceProviderInterface;
use Reactor\Core\Container;
use Reactor\Core\Router;
use Reactor\Enums\MiddlewareMode;

/**
 * Registers join package services and wires the discovery of the
 * package's handlers and middleware.
 *
 * Config binding is provided as an empty default. The host application
 * overrides JoinConfig in its own service provider to customise the
 * message keys, cache TTLs, exemptions and admin list.
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
        // Discover the package's handlers so the JoinCheckCallbackHandler
        // is available without the host having to register it manually.
        if ($container->has(Router::class)) {
            $router = $container->get(Router::class);
            $router->discover(__DIR__ . '/Handlers', 'AfazTech\\ReactorJoin\\Handlers');
        }

        // Inject the package's middleware into the framework's middleware
        // list. The App constructor reads the 'middlewares' binding after
        // running providers, so replacing the binding here is safe.
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
