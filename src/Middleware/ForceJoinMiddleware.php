<?php

namespace AfazTech\ReactorJoin\Middleware;

use AfazTech\ReactorJoin\Contracts\JoinManagerInterface;
use AfazTech\ReactorJoin\Events;
use AfazTech\ReactorJoin\Models\RequiredChannel;
use AfazTech\ReactorJoin\Support\JoinConfig;
use Neili\Client;
use Reactor\Attributes\Middleware;
use Reactor\Attributes\OnUpdate;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\MiddlewareInterface;
use Reactor\Contracts\UserProviderInterface;
use Reactor\Core\EventDispatcher;
use Reactor\Core\Routing\CommandParser;
use Reactor\Enums\MiddlewareMode;

/**
 * Blocks usage of the bot until the user joins every active channel
 * registered with the join package.
 *
 * The middleware runs at a lower priority than SyncUserMiddleware so
 * that the user record already exists when this check executes, and it
 * skips users that have not yet picked a language so that the
 * language selection flow can complete first.
 *
 * The join check is fail-open: any error raised while talking to the
 * persistence layer is logged and the update is allowed to continue,
 * so a misconfigured install cannot silently swallow every message.
 */
#[Middleware(priority: 30, mode: MiddlewareMode::GLOBAL)]
#[OnUpdate('message,callback_query')]
class ForceJoinMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Client $client,
        private LanguageInterface $language,
        private UserProviderInterface $userProvider,
        private JoinManagerInterface $joinManager,
        private EventDispatcher $dispatcher,
        private JoinConfig $config,
        private LoggerInterface $logger,
        private CommandParser $commandParser,
    ) {
    }

    public function handle(array $update): bool
    {
        $chatId = $this->extractChatId($update);
        $userId = $this->extractUserId($update);

        if ($chatId === null || $userId === null) {
            return false;
        }

        $chatType = $this->extractChatType($update);
        if ($chatType !== 'private' && !$this->config->shouldCheckInGroups()) {
            return false;
        }

        if (in_array($userId, $this->config->getAdminUserIds(), true)) {
            return false;
        }

        if ($this->isExemptCommand($update) || $this->isExemptCallback($update)) {
            return false;
        }

        // Let users without a language go through so the language
        // selection handler runs. The message key requires a language.
        $lang = $this->userProvider->getLanguage($userId);
        if ($lang === '') {
            return false;
        }

        // Fail-open: never let a persistence problem lock the user
        // out of the bot.
        try {
            $channels = $this->joinManager->listActiveChannels();
        } catch (\Throwable $e) {
            $this->logger->error('Force-join: listActiveChannels failed, allowing update', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }

        if ($channels === []) {
            return false;
        }

        try {
            $missing = $this->joinManager->missingChannels($userId);
        } catch (\Throwable $e) {
            $this->logger->error('Force-join: missingChannels failed, allowing update', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }

        if ($missing === []) {
            $this->dispatcher->dispatch(Events::JOIN_PASSED, $userId);
            return false;
        }

        $this->sendPrompt($chatId, $userId, $lang, $missing);
        $this->dispatcher->dispatch(Events::JOIN_BLOCKED, $userId, $missing);

        return true;
    }

    private function isExemptCommand(array $update): bool
    {
        $text = $update['message']['text'] ?? '';
        if (!is_string($text) || $text === '' || !$this->commandParser->isCommand($text)) {
            return false;
        }

        $parsed = $this->commandParser->parse($text);
        if ($parsed === null) {
            return false;
        }

        return in_array($parsed['name'], $this->config->getExemptCommands(), true);
    }

    private function isExemptCallback(array $update): bool
    {
        $data = $update['callback_query']['data'] ?? '';
        if (!is_string($data) || $data === '') {
            return false;
        }

        foreach ($this->config->getExemptCallbackPrefixes() as $prefix) {
            if (str_starts_with($data, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, RequiredChannel> $missing
     */
    private function sendPrompt(int $chatId, int $userId, string $lang, array $missing): void
    {
        $text     = $this->language->get($this->config->getMessageKey(), $lang);
        $keyboard = $this->buildKeyboard($missing, $lang);

        try {
            // parse_mode=HTML so tags like <b> in the message key are
            // rendered instead of being displayed verbatim.
            $this->client->sendMessage(
                chatId: $chatId,
                text: $text,
                keyboard: $keyboard,
                parseMode: 'HTML',
            )->await();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to send force-join prompt', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<int, RequiredChannel> $missing
     * @return array<string, mixed>
     */
    private function buildKeyboard(array $missing, string $lang): array
    {
        $rows = [];

        foreach ($missing as $channel) {
            $rows[] = [[
                'text' => '📢 ' . $channel->title,
                'url'  => $channel->link,
            ]];
        }

        $rows[] = [[
            'text'          => '✅ ' . $this->language->get($this->config->getCheckButtonKey(), $lang),
            'callback_data' => 'join:check',
        ]];

        return ['inline_keyboard' => $rows];
    }

    private function extractUserId(array $update): ?int
    {
        $id = $update['message']['from']['id']
            ?? $update['callback_query']['from']['id']
            ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    private function extractChatId(array $update): ?int
    {
        $id = $update['message']['chat']['id']
            ?? $update['callback_query']['message']['chat']['id']
            ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    private function extractChatType(array $update): ?string
    {
        return $update['message']['chat']['type']
            ?? $update['callback_query']['message']['chat']['type']
            ?? null;
    }
}
