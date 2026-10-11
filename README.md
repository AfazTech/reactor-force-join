# Reactor Force Join

Force-join (mandatory channel subscription) middleware for **[Reactor](https://github.com/afaztech/reactor-framework)** bots.

> **Built specifically for the Reactor PHP framework.**
> Requires `afaztech/reactor-framework ^0.5.0` and will not work standalone.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Framework](https://img.shields.io/badge/reactor-%5E0.5.0-orange.svg)](https://github.com/afaztech/reactor-framework)

---

## Overview

Reactor Force Join blocks users from using your bot until they have joined every channel you mark as required. Membership state lives in the `channel_members` table and is kept fresh by Telegram's own `chat_member` push updates — no polling, no in-memory cache, no per-message API calls.

Three auto-deactivation rules keep the required list healthy over time:

| Rule | Trigger |
| --- | --- |
| `target_members` | Channel reaches a configured member count. `0` = no limit. |
| `max_adds` | The bot has driven N new joins through the channel. `0` = disabled. |
| `bot_left` | The bot itself is removed from the channel, so membership can no longer be verified. |

### How membership is checked

- Every time a user interacts, `ForceJoinMiddleware` reads the local `channel_members` row.
- If the row says the user is a member, the check passes without any API call.
- If the row is missing or says the user is **not** a member, a single `getChatMember` call confirms the state and the result is persisted.
- Whenever the user joins, leaves, or is kicked, Telegram pushes a `chat_member` update and the row is updated immediately.

The database *is* the cache. There is no additional layer to invalidate, and Telegram itself guarantees freshness through its push stream.

---

## Requirements

- PHP >= 8.1
- `afaztech/reactor-framework` ^0.5.0
- A Reactor application with the queue worker configured (only for the optional `ChannelsHealthJob`)
- The bot must be an administrator in every required channel

---

## Installation

```bash
composer require afaztech/reactor-force-join
php reactor.php migrate
```

The service provider is auto-discovered through Composer metadata (`extra.reactor.providers`), and the migration is picked up automatically from `extra.reactor.migrations_namespace`.

---

## Configuration

The package is configured programmatically through the fluent `JoinConfig` value object. Bind a customised instance in your application's service provider — it runs after the package provider, so your binding wins.

```php
// app/Providers/AppServiceProvider.php

namespace App\Providers;

use Reactor\Core\Container;
use Reactor\Contracts\ServiceProviderInterface;
use AfazTech\ReactorJoin\Support\JoinConfig;

class AppServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(JoinConfig::class, function () {
            return (new JoinConfig())
                ->messageKey('force_join_message')
                ->checkButtonKey('join_check_button')
                ->successKey('join_success')
                ->stillMissingKey('join_still_missing')
                ->exemptCommands(['/start', '/help', '/lang'])
                ->exemptCallbackPrefixes(['join:', 'lang:'])
                ->adminUserIds([123456789])
                ->checkInGroups(false);
        });
    }

    public function boot(Container $container): void {}
}
```

### Available setters

| Method | Default | Description |
| --- | --- | --- |
| `messageKey(string)` | `force_join_message` | Translation key for the join prompt. |
| `checkButtonKey(string)` | `join_check_button` | Translation key for the "I've joined" button. |
| `successKey(string)` | `join_success` | Translation key for the success toast. |
| `stillMissingKey(string)` | `join_still_missing` | Translation key for the "still missing" toast. |
| `exemptCommands(array)` | `['/start', '/help']` | Commands that bypass the check. |
| `exemptCallbackPrefixes(array)` | `['join:', 'lang:']` | Callback data prefixes that bypass the check. |
| `adminUserIds(array)` | `[]` | Telegram user IDs that bypass the check. |
| `checkInGroups(bool)` | `false` | Run the check in group chats as well. |

---

## Language keys

Add these keys to every `lang/*.json` file:

```json
{
    "force_join_message": "To use this bot, please join the channels below.",
    "join_check_button": "I've joined",
    "join_success": "Thanks! You can use the bot now.",
    "join_still_missing": "You haven't joined all channels yet."
}
```

The prompt itself always uses the recipient's stored language. `ForceJoinMiddleware` skips users who have not picked a language yet, which guarantees the language selection flow (e.g. the skeleton's `LanguageSelectionHandler`) completes before the join check kicks in.

---

## Usage

```php
use AfazTech\ReactorJoin\Contracts\JoinManagerInterface;

$join = $container->get(JoinManagerInterface::class);
```

### Add a channel

```php
use AfazTech\ReactorJoin\Exceptions\BotNotAdminException;
use AfazTech\ReactorJoin\Exceptions\ChannelNotFoundException;
use AfazTech\ReactorJoin\Exceptions\JoinException;

try {
    $channel = $join->addChannel('@my_channel', [
        'link'           => 'https://t.me/my_channel',
        'target_members' => 5000,  // auto-deactivate at 5k members (0 = no limit)
        'max_adds'       => 0,     // 0 = disabled
    ]);
} catch (BotNotAdminException $e) {
    // The bot is not an administrator in the target channel.
} catch (ChannelNotFoundException $e) {
    // The identifier could not be resolved to a Telegram chat.
} catch (JoinException $e) {
    // Duplicate channel, missing link, or private invite link.
}
```

`addChannel` accepts several identifier formats:

| Input | Example |
| --- | --- |
| Public username | `@my_channel` or `my_channel` |
| Public URL | `t.me/my_channel` or `https://t.me/my_channel` |
| Numeric chat ID | `-1001234567890` (required for private channels) |

Before touching the database, `addChannel` calls `getChat` to resolve the channel and `getChatMember` to verify the bot's admin status, so a misconfigured channel can never end up in the required list.

### Remove a channel

```php
$join->removeChannel($channel->id);
```

This removes the channel and every membership record associated with it.

### List channels

```php
$all    = $join->listChannels();        // active + inactive
$active = $join->listActiveChannels();  // only active
```

### Enable / disable a channel

```php
$join->setChannelActive($channel->id, false); // disable
$join->setChannelActive($channel->id, true);  // re-enable
```

Disabled channels are skipped by the middleware but their membership records are preserved.

### Manual membership check

```php
use AfazTech\ReactorJoin\Models\RequiredChannel;

$missing = $join->missingChannels($userId); // RequiredChannel[]
$isIn    = $join->isMember($userId, $channelId); // bool
```

Both methods use the same local-first strategy: the `channel_members` row is consulted first, and a `getChatMember` call is made only when the row is missing or says the user is not a member.

---

## Auto-deactivation and health job

Two of the three deactivation rules are evaluated inline (no scheduler required):

- **`max_adds`** — triggered the moment the counter reaches its limit, either from `ChatMemberSyncMiddleware` or `JoinManager`.
- **`bot_left`** — triggered the moment `my_chat_member` reports the bot itself was removed from the channel.

The `target_members` rule requires periodically polling the Bot API. Schedule `ChannelsHealthJob` from your `ScheduleKernel`:

```php
use AfazTech\ReactorJoin\Jobs\ChannelsHealthJob;
use Reactor\Core\Schedule\Schedule;

public function schedule(Schedule $schedule): void
{
    $schedule->job(ChannelsHealthJob::class)->everyThirtyMinutes();
}
```

Nothing happens for channels where `target_members` is `0`.

---

## Events

Subscribe with the framework's `EventDispatcher`. Every event is dispatched from the middleware or the manager the moment the underlying state changes.

```php
use AfazTech\ReactorJoin\Events;
use Reactor\Core\EventDispatcher;

$dispatcher = $container->get(EventDispatcher::class);

$dispatcher->on(Events::MEMBER_JOINED, function (int $userId, int $channelId) {
    // Track analytics, send a thank-you message, etc.
});

$dispatcher->on(Events::MEMBER_LEFT, function (int $userId, int $channelId) {
    // React to the user leaving a required channel.
});

$dispatcher->on(Events::CHANNEL_DEACTIVATED, function (int $channelId, string $reason) {
    // $reason ∈ {
    //   'target_members_reached',
    //   'max_adds_reached',
    //   'bot_left',
    //   'bot_not_admin'
    // }
});

$dispatcher->on(Events::JOIN_PASSED, function (int $userId) {
    // The user passed the check and can now use the bot.
});

$dispatcher->on(Events::JOIN_BLOCKED, function (int $userId, array $missingChannels) {
    // The user was stopped by the middleware.
});
```

---

## Middleware priority

The package registers two global middlewares. Both are injected into the framework's middleware list automatically by `JoinServiceProvider::boot()`; no manual registration is required.

| Class | Priority | Update types |
| --- | --- | --- |
| `ChatMemberSyncMiddleware` | 40 | `chat_member`, `my_chat_member` |
| `ForceJoinMiddleware` | 30 | `message`, `callback_query` |

`ForceJoinMiddleware` intentionally runs at a **lower priority** than the skeleton's `SyncUserMiddleware` (priority 50), so the user record exists before the check executes. It also skips users without a stored language, letting the language selection flow complete first.

`ChatMemberSyncMiddleware` returns `true` in both branches, short-circuiting the pipeline so no user-facing handler reacts to Telegram's system updates.

---

## Custom storage

Override the `ChannelProviderInterface` binding to swap the storage layer while keeping the public API unchanged:

```php
use AfazTech\ReactorJoin\Contracts\ChannelProviderInterface;

$container->singleton(ChannelProviderInterface::class, function ($c) {
    return new \App\Join\RedisChannelProvider($c->get(\Redis::class));
});
```

The interface exposes seven methods:

```php
interface ChannelProviderInterface
{
    public function active(): array;                              // RequiredChannel[]
    public function all(): array;                                 // RequiredChannel[]
    public function find(int $id): ?RequiredChannel;
    public function findByChatId(int $chatId): ?RequiredChannel;
    public function create(array $data): RequiredChannel;
    public function delete(int $id): bool;
    public function setActive(int $id, bool $active): bool;
}
```

---

## Database schema

Two tables are created by the package migration.

### `required_channels`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint, PK | |
| `chat_id` | bigint, unique | Telegram chat ID (negative for channels). |
| `title` | varchar(255) | Channel title, fetched from `getChat`. |
| `link` | varchar(512) | `https://t.me/...` URL used in the prompt keyboard. |
| `is_active` | boolean | Inactive channels are skipped by the middleware. |
| `target_members` | unsigned int | `0` = no auto-deactivation. |
| `max_adds` | unsigned int | `0` = disabled. |
| `current_adds` | unsigned int | Incremented on each new join. |
| `created_at`, `updated_at` | timestamps | |

### `channel_members`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint, PK | |
| `user_id` | bigint | Telegram user ID. |
| `channel_id` | unsigned bigint | References `required_channels.id`. |
| `is_joined` | boolean | Current membership state. |
| `joined_at` | timestamp, nullable | Set when `is_joined` becomes true. |
| `created_at`, `updated_at` | timestamps | |
| — | unique | `(user_id, channel_id)` |
| — | index | `user_id` |
| — | index | `(channel_id, is_joined)` |

---

## Testing locally

To develop against a local checkout, add a path repository in your application's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "../reactor-force-join",
        "options": { "symlink": true }
    }
],
"require": {
    "afaztech/reactor-force-join": "@dev"
}
```

The framework must also be a path repository or a tagged release of `^0.5.0`. When the framework is checked out locally, add a `branch-alias` to its `composer.json` so `dev-main` is treated as `0.5.x-dev`:

```json
"extra": {
    "branch-alias": {
        "dev-main": "0.5.x-dev"
    }
}
```

---

## License

MIT. See [LICENSE](LICENSE)