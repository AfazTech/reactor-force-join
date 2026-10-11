<?php

namespace AfazTech\ReactorJoin\Contracts;

use AfazTech\ReactorJoin\Models\RequiredChannel;

/**
 * Persistence contract for required channels.
 *
 * The default implementation is DatabaseChannelProvider. Override the
 * container binding in a service provider to swap in a different
 * backend without changing the rest of the package.
 */
interface ChannelProviderInterface
{
    /** @return array<int, RequiredChannel> */
    public function active(): array;

    /** @return array<int, RequiredChannel> */
    public function all(): array;

    public function find(int $id): ?RequiredChannel;

    public function findByChatId(int $chatId): ?RequiredChannel;

    /** @param array<string, mixed> $data */
    public function create(array $data): RequiredChannel;

    public function delete(int $id): bool;

    public function setActive(int $id, bool $active): bool;
}
