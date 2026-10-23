# Reactor Force Join

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Reactor](https://img.shields.io/badge/Reactor-Framework-orange.svg)](https://github.com/afaztech/reactor)

Required channel management for [Reactor](https://github.com/afaztech/reactor) Telegram bots.

## Features

- Register required channels by username, URL, or numeric chat id
- Automatic admin verification before a channel is added
- Local membership cache kept in sync via `chat_member` updates
- Fast membership checks that skip the Telegram API on cache hits
- Auto-disable a channel when it reaches a target member count
- Auto-disable a channel after a maximum number of joins driven by the bot
- Per-channel configuration via dedicated setters
- Enable or disable channels independently
- Configurable per-channel member and join limits

## Requirements

- `afaztech/reactor-framework` ^0.5.0

## Installation

```bash
composer require afaztech/reactor-force-join
./reactor migrate
```

## Usage

```php
use AfazTech\ReactorJoin\Contracts\JoinManagerInterface;

$join = $container->get(JoinManagerInterface::class);

// Register a channel and configure its limits.
$channel = $join->addChannel('@my_channel', [
    'target_members' => 5000, // auto-disable at 5k members (0 = no limit)
    'max_adds'       => 1000, // auto-disable after 1k bot joins (0 = disabled)
]);

// Check membership from your own middleware or handler.
$missing = $join->missingChannels($userId);
if ($missing !== []) {
    // prompt the user to join
}

// Adjust limits later.
$join->setChannelTargetMembers($channel->id, 10000);
$join->setChannelMaxAdds($channel->id, 0);
$join->resetChannelCurrentAdds($channel->id);
```

## Documentation

For detailed documentation, see [DOCUMENT.md](DOCUMENT.md).

## Contributing

Contributions are welcome. Please read the contribution guidelines.

## License

This project is licensed under the [MIT License](LICENSE).