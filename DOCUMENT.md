# Reactor Force Join — Documentation

Complete reference for the `afaztech/reactor-force-join` package.

---

## Table of Contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Core Concepts](#core-concepts)
- [API Reference](#api-reference)
  - [Channel Management](#channel-management)
  - [Channel Limits](#channel-limits)
  - [Membership Checks](#membership-checks)
- [Membership Check Flow](#membership-check-flow)
- [Auto-Deactivation](#auto-deactivation)
- [Middleware](#middleware)
- [Enforcement](#enforcement)
- [Events](#events)
- [Storage](#storage)
- [Error Handling](#error-handling)
- [Examples](#examples)
- [Testing](#testing)

---

## Overview

Reactor Force Join manages required channels for Reactor Telegram bots. It tracks which channels a bot requires users to join, keeps a local cache of user membership state, and auto-deactivates channels when they reach configured limits.

The package is deliberately scoped:

- **Channel management** — register, remove, enable, disable.
- **Membership tracking** — local cache with API fallback, kept fresh by Telegram's `chat_member` push stream.
- **Limit enforcement** — auto-deactivate a channel when it reaches a Telegram member count or a maximum number of bot-driven joins.
- **Membership queries** — `missingChannels()` and `isMember()` for the host application to use.

The package **does not block users**. Blocking (force-join enforcement) is the host application's responsibility: it writes a middleware that calls `missingChannels()` and decides what to do. The package ships exactly one middleware — `ChatMemberSyncMiddleware` — which exists solely to keep the local cache accurate (see [Middleware](#middleware)).

---

## Requirements

- PHP >= 8.1
- `afaztech/reactor-framework` ^0.5.0

The framework provides `Neili\Client`, `DatabaseManagerInterface`, and `LoggerInterface`.

The bot must be an administrator in every required channel so it can call `getChat`, `getChatMember`, and `getChatMemberCount`.

---

## Installation

```bash
composer require afaztech/reactor-force-join
./reactor migrate
```

The migration creates the `required_channels` and `channel_members` tables. The service provider is auto-discovered via Composer metadata.

---

## Configuration

The package has no configurable options. All behavior is driven through the `JoinManagerInterface` API at runtime:

- Add or remove channels.
- Configure per-channel limits.
- Enable or disable channels.
- Query membership.

The one storage-binding override available is `ChannelProviderInterface`:

```php
use AfazTech\ReactorJoin\Contracts\ChannelProviderInterface;

$container->singleton(ChannelProviderInterface::class, function ($c) {
    return new \App\Join\RedisChannelProvider($c->get(\Redis::class));
});
```

| Method | Default | Description |
|--------|---------|-------------|
| `active(): array` | — | All active channels |
| `all(): array` | — | All channels, active and inactive |
| `find(int $id): ?RequiredChannel` | — | By primary key |
| `findByChatId(int $chatId): ?RequiredChannel` | — | By Telegram chat id |
| `create(array $data): RequiredChannel` | — | Insert |
| `delete(int $id): bool` | — | Delete + cascade membership rows |
| `setActive(int $id, bool $active): bool` | — | Toggle `is_active` |

### Accessing the Manager

```php
use AfazTech\ReactorJoin\Contracts\JoinManagerInterface;

$join = $container->get(JoinManagerInterface::class);
```

---

## Core Concepts

### Channels vs. Members

Two tables:

- **`required_channels`** — the list of channels users must be a member of. Each row has a `chat_id` (Telegram id), `title`, `link`, `is_active`, and the two limit fields `target_members` / `max_adds` plus the `current_adds` counter.
- **`channel_members`** — a cache of per-user membership state. Each row is a `(user_id, channel_id)` pair with `is_joined` and `joined_at`.

Membership state lives **only** in `channel_members`. That table is treated as the source of truth for the check flow. Telegram's `chat_member` updates keep it fresh.

### Active vs. Inactive

An **active** channel is currently being checked. An **inactive** channel is ignored by `missingChannels()` and `isMember()` — the user is not required to join it.

Channels transition from active to inactive automatically when a limit triggers, or manually via `setChannelActive()`.

### Limits

Two independent auto-deactivation rules:

- **`target_members`** — evaluated against the channel's Telegram member count (`getChatMemberCount`). `0` disables the rule.
- **`max_adds`** — evaluated against the bot-driven join counter `current_adds`. `0` disables the rule.

Both are enforced **immediately** by `enforceLimits()` — no scheduled job, no polling. See [Auto-Deactivation](#auto-deactivation).

---

## API Reference

### Channel Management

#### `addChannel(string $identifier, array $options = []): RequiredChannel`

Registers a new required channel.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$identifier` | string | `@username`, `username`, `t.me/username`, `https://t.me/username`, or a numeric `chat_id` |
| `$options['link']` | string | Explicit link (overrides auto-resolution) |
| `$options['invite_link']` | string | Legacy alias for `link` |
| `$options['is_active']` | bool | Default `true` |
| `$options['target_members']` | int | Default `0` (disabled) |
| `$options['max_adds']` | int | Default `0` (disabled) |

**Behavior:**

1. Calls `getChat` to resolve the identifier.
2. Calls `getChatMember` to verify the bot is an administrator.
3. Resolves the link: explicit `link` → `invite_link` → public `t.me/{username}` → exported invite link.
4. Refuses to insert if the `chat_id` is already registered.
5. Calls `enforceLimits()` immediately, so a channel that already exceeds its own limits is deactivated at creation time.

**Throws:** `ChannelNotFoundException`, `BotNotAdminException`, `JoinException`.

#### `removeChannel(int $id): bool`

Deletes the channel and every `channel_members` row tied to it.

#### `listChannels(): array`

Returns every channel (active and inactive), ordered by id.

#### `listActiveChannels(): array`

Returns only active channels.

#### `getChannel(int $id): ?RequiredChannel`

Returns a single channel by primary key, or `null`.

#### `setChannelActive(int $id, bool $active): bool`

Toggles a channel's active flag. Does not touch limits or membership data.

---

### Channel Limits

#### `setChannelTargetMembers(int $channelId, int $target): bool`

Sets the Telegram member count threshold. `0` disables the rule.

**Behavior:** saves the value, then calls `enforceLimits()` immediately. If the channel's current Telegram member count is already at or above the new target, the channel is deactivated right now.

#### `setChannelMaxAdds(int $channelId, int $max): bool`

Sets the bot-driven join threshold. `0` disables the rule.

**Behavior:** saves the value, then calls `enforceLimits()` immediately. If `current_adds` is already at or above the new max, the channel is deactivated right now.

#### `resetChannelCurrentAdds(int $channelId): bool`

Resets the `current_adds` counter to `0`. Useful when re-enabling a channel whose `max_adds` rule has already fired, or when starting a new counting period.

#### `enforceLimits(int $channelId): bool`

Evaluates both rules for the channel and deactivates it if either triggers.

Returns `true` when the channel was deactivated by this call, `false` otherwise (already inactive, no rules configured, or limits not met).

**Called automatically by:**

- `ChatMemberSyncMiddleware` after every successful `chat_member` join.
- `setChannelTargetMembers()` and `setChannelMaxAdds()` after the value is saved.
- `addChannel()` after the channel is created.

**Can also be called explicitly** by the host application — e.g. from an admin command that adds members manually, or from a custom scheduled job.

---

### Membership Checks

#### `missingChannels(int $userId): array`

Returns the active channels the user is **not** a member of. An empty array means the user is a member of every required channel (or no channels are configured).

Uses the [local-first strategy](#membership-check-flow): consults `channel_members` first, falls back to `getChatMember` only when the local record is missing or says the user is not a member.

#### `isMember(int $userId, int $channelId): bool`

Returns whether the user is a member of the given channel. Same local-first strategy as `missingChannels()`. Returns `false` when the channel does not exist.

---

## Membership Check Flow

Every call to `missingChannels()` or `isMember()` runs this flow per channel:

```
┌──────────────────────────────────────────────────────┐
│  Read channel_members row for (user_id, channel_id)  │
└──────────────────────────────────────────────────────┘
                        │
        ┌───────────────┴───────────────┐
        ▼                               ▼
   Row exists?                     No row, or
   is_joined = true                is_joined = false
        │                               │
        ▼                               ▼
   Member (fast path,           Call getChatMember API
   no API call)                        │
                                       ▼
                           ┌───────────┴───────────┐
                           ▼                       ▼
                      Permanent error        Success
                           │                       │
                           ▼                       ▼
                  Persist is_joined=false   Persist result
                  Return false              Update current_adds
                                            if new join
                                            Run enforceLimits
                                            Return result
```

Key properties:

- **Fast path on cache hit** — if `channel_members.is_joined = true`, no API call. This is the common case for active users of a bot with required channels.
- **No false positives** — a user who left a channel is caught on the next check because `ChatMemberSyncMiddleware` flipped `is_joined` to `false` the moment Telegram sent the `chat_member` update.
- **Fail-open on transient errors** — if `getChatMember` fails with a transient error (network, 5xx), the check returns `true` (allow) and logs a warning. A transient Telegram outage must not lock every user out of the bot.
- **Auto-deactivation on unavailability** — if `getChatMember` fails because the bot was removed from the channel or lost admin rights, the channel is deactivated with reason `bot_not_admin`.

### When the cache becomes stale

The cache is invalidated by `ChatMemberSyncMiddleware`, which listens to Telegram's `chat_member` updates. This middleware is the **only** middleware the package ships and it is auto-registered. Removing it would allow left users to keep passing the check — see [Middleware](#middleware).

### When the cache is populated

The cache is populated lazily: the first call to `missingChannels()` or `isMember()` for a given `(user_id, channel_id)` pair hits the API and stores the result. Subsequent calls hit the cache.

When a new channel is added via `addChannel()`, no rows are pre-written to `channel_members`. The next check for each user hits the API once and caches the result. This is deliberate: pre-writing `false` for every user × new channel would be O(users) database writes per channel addition, with no security benefit over the lazy approach.

---

## Auto-Deactivation

Two rules, both evaluated in a single pass by `enforceLimits()`:

### `target_members`

Checks the **Telegram** member count of the channel via `getChatMemberCount`. This is the total number of users in the channel, regardless of whether they joined through the bot.

- Trigger when `count >= target_members`
- `0` disables the rule
- Reason dispatched: `target_members_reached`

### `max_adds`

Checks the bot-driven join counter `current_adds`. This counter is incremented only when a user transitions from not-a-member to a-member, detected via the `chat_member` stream.

- Trigger when `current_adds >= max_adds`
- `0` disables the rule
- Reason dispatched: `channel_deactivated` with reason `max_adds_reached`

### When limits are checked

`enforceLimits()` is called from:

- `ChatMemberSyncMiddleware` — after every successful `chat_member` join
- `setChannelTargetMembers()` — immediately after saving
- `setChannelMaxAdds()` — immediately after saving
- `addChannel()` — immediately after creation

No polling, no scheduled job. If the bot is idle and no joins happen, no checks run — which is fine, because neither limit can change without a join.

### Reset after deactivation

After a channel is deactivated by `max_adds`, the counter is preserved. To re-enable the channel and start a new counting period:

```php
$join->resetChannelCurrentAdds($channel->id);
$join->setChannelActive($channel->id, true);
```

---

## Middleware

The package ships **exactly one** middleware:

### `ChatMemberSyncMiddleware`

**Priority:** 40
**Update types:** `chat_member`, `my_chat_member`
**Mode:** GLOBAL

**Role:** keeps `channel_members` in sync with Telegram's membership stream, and enforces limits on every join.

**Behavior on `chat_member`:**

1. Reads the affected user (`new_chat_member.user.id`) — not the actor, since an admin kicking a user must not corrupt the admin's own row.
2. Maps the new status to a boolean via `statusMeansJoined()` — `restricted` users are resolved via the `is_member` flag, not guessed.
3. Updates the `channel_members` row.
4. On transition to joined: increments `current_adds`, dispatches `MEMBER_JOINED`, and calls `enforceLimits()`.
5. On transition to left: dispatches `MEMBER_LEFT`.
6. Returns `true` to short-circuit the pipeline (system updates have no user-facing handler).

**Behavior on `my_chat_member`:**

When the bot itself is removed from a channel (status `left` or `kicked`), the channel is deactivated with reason `bot_left`. Membership can no longer be verified, so the channel must not remain active.

**Why this middleware ships with the package:**

The `channel_members` table is a cache. If it were never invalidated when a user leaves a channel, `missingChannels()` would return a stale `is_joined = true` on the fast path, and a user who left would continue to pass the check. This is a correctness issue, not a policy decision — so the invalidation stays in the package.

**Why no other middleware ships with the package:**

Enforcement (blocking users who are not members) is a policy decision: what message to send, what buttons to show, what language to use, when to allow exceptions. That decision belongs to the host application. See [Enforcement](#enforcement).

---

## Enforcement

The package does **not** block users. To enforce required-channel membership, the host application writes its own middleware.

A minimal example:

```php
<?php

namespace App\Middleware;

use AfazTech\ReactorJoin\Contracts\JoinManagerInterface;
use Neili\Client;
use Reactor\Attributes\Middleware;
use Reactor\Attributes\OnUpdate;
use Reactor\Contracts\MiddlewareInterface;
use Reactor\Enums\MiddlewareMode;

#[Middleware(priority: 30, mode: MiddlewareMode::GLOBAL)]
#[OnUpdate('message,callback_query')]
class MyForceJoinMiddleware implements MiddlewareInterface
{
    public function __construct(
        private JoinManagerInterface $joinManager,
        private Client $client,
    ) {}

    public function handle(array $update): bool
    {
        $userId = $update['message']['from']['id']
            ?? $update['callback_query']['from']['id']
            ?? null;

        if ($userId === null) {
            return false;
        }

        // Optional: exempt /start, /help, join callbacks, admin users.
        $text = $update['message']['text'] ?? '';
        if ($text === '/start' || $text === '/help') {
            return false;
        }

        $missing = $this->joinManager->missingChannels((int) $userId);
        if ($missing === []) {
            return false;
        }

        $rows = [];
        foreach ($missing as $channel) {
            $rows[] = [[
                'text' => '📢 ' . $channel->title,
                'url'  => $channel->link,
            ]];
        }

        $this->client->sendMessage(
            $userId,
            'Please join the required channels to continue.',
            ['inline_keyboard' => $rows],
            ['parse_mode' => 'HTML'],
        )->await();

        return true;
    }
}
```

Register it via the standard middleware discovery (or `#[UseMiddleware]` on specific handlers). The package's own `ChatMemberSyncMiddleware` remains active and independent.

---

## Events

Subscribe via the framework's `EventDispatcher`:

```php
use AfazTech\ReactorJoin\Events;
use Reactor\Core\EventDispatcher;

$dispatcher = $container->get(EventDispatcher::class);

$dispatcher->on(Events::MEMBER_JOINED, function (int $userId, int $channelId) {
    // Analytics, welcome message, ...
});

$dispatcher->on(Events::MEMBER_LEFT, function (int $userId, int $channelId) {
    // React to the user leaving a required channel.
});

$dispatcher->on(Events::CHANNEL_DEACTIVATED, function (int $channelId, string $reason) {
    // $reason ∈ {
    //   'target_members_reached',
    //   'max_adds_reached',
    //   'bot_left',
    //   'bot_not_admin',
    // }
});
```

| Event | Arguments | Fires when |
|-------|-----------|------------|
| `MEMBER_JOINED` | `(int $userId, int $channelId)` | A user transitions to joined (via `chat_member` or the API fallback) |
| `MEMBER_LEFT` | `(int $userId, int $channelId)` | A user transitions to not-joined (via `chat_member`) |
| `CHANNEL_DEACTIVATED` | `(int $channelId, string $reason)` | A channel is deactivated by a limit, bot removal, or admin rights loss |

`JOIN_PASSED` and `JOIN_BLOCKED` constants remain in `Events` for backward compatibility but are not dispatched by the package.

---

## Storage

### `required_channels`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | |
| `chat_id` | bigint, unique | Telegram chat id (negative for channels) |
| `title` | varchar(255) | Fetched from `getChat` |
| `link` | varchar(512) | Used in the join prompt |
| `is_active` | boolean | Inactive channels are skipped by `missingChannels()` |
| `target_members` | unsigned int | `0` = no limit |
| `max_adds` | unsigned int | `0` = disabled |
| `current_adds` | unsigned int | Bot-driven join counter |
| `created_at`, `updated_at` | timestamps | |
| — | index | `is_active` |

### `channel_members`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | |
| `user_id` | bigint | Telegram user id |
| `channel_id` | unsigned bigint | References `required_channels.id` |
| `is_joined` | boolean | Cached membership state |
| `joined_at` | timestamp, nullable | Set when `is_joined` becomes `true` |
| `created_at`, `updated_at` | timestamps | |
| — | unique | `(user_id, channel_id)` |
| — | index | `user_id` |
| — | index | `(channel_id, is_joined)` |

The unique constraint on `(user_id, channel_id)` means each user has exactly one row per channel. Rows are created lazily on the first membership check and updated by `ChatMemberSyncMiddleware`.

---

## Error Handling

| Situation | Behavior |
|-----------|----------|
| Channel identifier cannot be resolved | `ChannelNotFoundException` from `addChannel` |
| Bot is not an admin in the target channel | `BotNotAdminException` from `addChannel` |
| Duplicate `chat_id` | `JoinException` from `addChannel` |
| Private invite link passed as identifier | `JoinException` from `addChannel` |
| Empty identifier | `JoinException` from `addChannel` |
| `getChatMember` fails transiently | `missingChannels` returns "member" (fail-open), warning logged |
| `getChatMember` fails permanently due to bot not being able to read | channel deactivated with `bot_not_admin`, warning logged |
| `getChatMemberCount` fails during `enforceLimits` | rule skipped for this call, warning logged, no exception |
| `setChannelTargetMembers` / `setChannelMaxAdds` on missing channel | returns `false` |
| `resetChannelCurrentAdds` on missing channel | returns `false` |
| `enforceLimits` on missing or inactive channel | returns `false` |

Everything that can fail during normal runtime (API calls) is caught and either fails open (membership check) or is skipped (limit enforcement) with a warning. Only programmer errors (bad identifier at `addChannel`) throw.

---

## Examples

### Register a channel with limits

```php
use AfazTech\ReactorJoin\Exceptions\BotNotAdminException;
use AfazTech\ReactorJoin\Exceptions\ChannelNotFoundException;
use AfazTech\ReactorJoin\Exceptions\JoinException;

try {
    $channel = $join->addChannel('@my_channel', [
        'target_members' => 5000,
        'max_adds'       => 1000,
    ]);
} catch (BotNotAdminException $e) {
    // The bot is not an administrator in the target channel.
} catch (ChannelNotFoundException $e) {
    // The identifier could not be resolved.
} catch (JoinException $e) {
    // Duplicate, private link, or empty identifier.
}
```

### Private channel via numeric chat id

```php
$join->addChannel('-1001234567890', [
    'link'           => 'https://t.me/+AbCdEfGhIjK',
    'target_members' => 500,
]);
```

Explicit `link` avoids the need for the bot to have invite-export rights.

### Adjust limits after the fact

```php
$join->setChannelTargetMembers($channel->id, 10000);
$join->setChannelMaxAdds($channel->id, 0); // disable the bot-join limit
```

### Re-enable after `max_adds` triggered

```php
$join->resetChannelCurrentAdds($channel->id);
$join->setChannelActive($channel->id, true);
```

### Manual membership check in a handler

```php
$missing = $join->missingChannels($userId);

if ($missing !== []) {
    $this->reply(
        'You still need to join ' . count($missing) . ' channel(s).'
    );
    return;
}

// Safe to proceed.
```

### React to limits being triggered

```php
use AfazTech\ReactorJoin\Events;

$dispatcher->on(Events::CHANNEL_DEACTIVATED, function (int $channelId, string $reason) use ($client) {
    if ($reason === 'max_adds_reached') {
        // Notify the operator that the channel has hit its bot-join cap.
        $client->sendMessage($adminId, "Channel #{$channelId} deactivated: bot-join cap reached.")->await();
    }
});
```

### Scheduled audit of member counts

```php
// In your own ScheduleKernel, if you want a periodic re-check in
// addition to the per-join enforcement:
use AfazTech\ReactorJoin\Contracts\JoinManagerInterface;

$schedule->call(function (Container $c) {
    $join = $c->get(JoinManagerInterface::class);
    foreach ($join->listActiveChannels() as $channel) {
        if ((int) $channel->target_members > 0) {
            $join->enforceLimits((int) $channel->id);
        }
    }
})->hourly();
```

---

## Testing

`JoinManagerInterface` is a container-bound singleton. Override it in tests:

```php
use AfazTech\ReactorJoin\Contracts\JoinManagerInterface;

$join = $this->createMock(JoinManagerInterface::class);
$join->method('missingChannels')->willReturn([]);
$container->instance(JoinManagerInterface::class, $join);
```

For integration tests, use a throwaway SQLite database and run the migration before each test class. Because `ChannelProviderInterface` is also container-bound, a mock provider can be substituted to avoid the database entirely:

```php
$provider = $this->createMock(ChannelProviderInterface::class);
$provider->method('active')->willReturn([/* RequiredChannel instances */]);
$container->instance(ChannelProviderInterface::class, $provider);
```

To test `ChatMemberSyncMiddleware` in isolation, dispatch synthetic `chat_member` updates through the framework's `MiddlewareManager` and assert against the `channel_members` table afterward.

---

## License

This project is licensed under the [MIT License](LICENSE).