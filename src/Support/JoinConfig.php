<?php

namespace AfazTech\ReactorJoin\Support;

/**
 * Fluent configuration for the join package.
 *
 * Bind a configured instance in the host application's service
 * provider to customise message keys, exemptions and admin list:
 *
 *   $container->singleton(JoinConfig::class, fn () => (new JoinConfig())
 *       ->messageKey('force_join_message')
 *       ->exemptCommands(['/start', '/help', '/lang'])
 *       ->adminUserIds([123456789])
 *   );
 *
 * Membership state is persisted in the channel_members table and kept
 * in sync by Telegram's chat_member updates, so no in-memory cache is
 * required. The database itself is the single source of truth.
 */
final class JoinConfig
{
    private string $messageKey = 'force_join_message';
    private string $checkButtonKey = 'join_check_button';
    private string $successKey = 'join_success';
    private string $stillMissingKey = 'join_still_missing';
    private string $openMenuButtonKey = 'join_open_menu';

    /** @var array<int, string> */
    private array $exemptCommands = ['/start', '/help'];

    /** @var array<int, string> */
    private array $exemptCallbackPrefixes = ['join:', 'lang:'];

    /** @var array<int, int> */
    private array $adminUserIds = [];

    private bool $checkInGroups = false;

    public function messageKey(string $key): self
    {
        $this->messageKey = $key;
        return $this;
    }

    public function checkButtonKey(string $key): self
    {
        $this->checkButtonKey = $key;
        return $this;
    }

    public function successKey(string $key): self
    {
        $this->successKey = $key;
        return $this;
    }

    public function stillMissingKey(string $key): self
    {
        $this->stillMissingKey = $key;
        return $this;
    }

    public function openMenuButtonKey(string $key): self
    {
        $this->openMenuButtonKey = $key;
        return $this;
    }

    /**
     * @param array<int, string> $commands
     */
    public function exemptCommands(array $commands): self
    {
        $this->exemptCommands = array_values($commands);
        return $this;
    }

    /**
     * @param array<int, string> $prefixes
     */
    public function exemptCallbackPrefixes(array $prefixes): self
    {
        $this->exemptCallbackPrefixes = array_values($prefixes);
        return $this;
    }

    /**
     * @param array<int, int> $ids
     */
    public function adminUserIds(array $ids): self
    {
        $this->adminUserIds = array_map('intval', array_values($ids));
        return $this;
    }

    public function checkInGroups(bool $state = true): self
    {
        $this->checkInGroups = $state;
        return $this;
    }

    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    public function getCheckButtonKey(): string
    {
        return $this->checkButtonKey;
    }

    public function getSuccessKey(): string
    {
        return $this->successKey;
    }

    public function getStillMissingKey(): string
    {
        return $this->stillMissingKey;
    }

    public function getOpenMenuButtonKey(): string
    {
        return $this->openMenuButtonKey;
    }

    /** @return array<int, string> */
    public function getExemptCommands(): array
    {
        return $this->exemptCommands;
    }

    /** @return array<int, string> */
    public function getExemptCallbackPrefixes(): array
    {
        return $this->exemptCallbackPrefixes;
    }

    /** @return array<int, int> */
    public function getAdminUserIds(): array
    {
        return $this->adminUserIds;
    }

    public function shouldCheckInGroups(): bool
    {
        return $this->checkInGroups;
    }
}
