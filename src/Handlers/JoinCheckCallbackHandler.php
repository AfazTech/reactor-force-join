<?php

namespace AfazTech\ReactorJoin\Handlers;

use AfazTech\ReactorJoin\Contracts\JoinManagerInterface;
use AfazTech\ReactorJoin\Events;
use AfazTech\ReactorJoin\Models\RequiredChannel;
use AfazTech\ReactorJoin\Support\JoinConfig;
use App\Contracts\Repository\UserRepositoryInterface;
use App\Handlers\BaseHandler;
use Neili\Client;
use Reactor\Attributes\Callback;
use Reactor\Attributes\OnUpdate;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\EventDispatcher;

/**
 * Handles the "I've joined" button from the force-join prompt.
 *
 * On success the prompt is edited into a short confirmation message
 * with a "main menu" button that triggers the app's `menu` callback,
 * so the user lands back on the main menu without having to resend
 * /start.
 *
 * On still-missing the prompt is re-rendered with the updated set of
 * channels and a toast is shown.
 */
#[Callback(data: 'join:check', priority: 200)]
#[OnUpdate('callback_query')]
class JoinCheckCallbackHandler extends BaseHandler
{
    public function __construct(
        Client $client,
        LanguageInterface $language,
        LoggerInterface $logger,
        UserRepositoryInterface $userRepository,
        private JoinManagerInterface $joinManager,
        private EventDispatcher $dispatcher,
        private JoinConfig $config,
    ) {
        parent::__construct($client, $language, $logger, $userRepository);
    }

    protected function handle(array $params = []): void
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return;
        }

        $lang       = $this->getUserLanguage();
        $callbackId = (string) ($this->update['callback_query']['id'] ?? '');
        $chatId     = $this->update['callback_query']['message']['chat']['id'] ?? null;
        $messageId  = $this->update['callback_query']['message']['message_id'] ?? null;

        $missing = $this->joinManager->missingChannels($userId);

        if ($missing === []) {
            $this->onAllJoined($userId, $lang, $callbackId, $chatId, $messageId);
            return;
        }

        $this->onStillMissing($lang, $callbackId, $chatId, $messageId, $missing);
    }

    private function onAllJoined(int $userId, string $lang, string $callbackId, ?int $chatId, ?int $messageId): void
    {
        $this->dispatcher->dispatch(Events::JOIN_PASSED, $userId);

        $successText = $this->language->get($this->config->getSuccessKey(), $lang);

        // Toast with the same message so the user gets immediate
        // feedback even if the message edit fails.
        if ($callbackId !== '') {
            $this->client->answerCallbackQuery($callbackId, $successText);
        }

        if (!is_int($chatId) || !is_int($messageId)) {
            return;
        }

        $keyboard = [
            'inline_keyboard' => [[
                [
                    'text'          => '🏠 ' . $this->language->get($this->config->getOpenMenuButtonKey(), $lang),
                    'callback_data' => 'menu',
                ],
            ]],
        ];

        try {
            $this->client->editMessageText(
                chatId: $chatId,
                messageId: $messageId,
                text: $successText,
                keyboard: $keyboard,
                parseMode: 'HTML',
            );
        } catch (\Throwable $e) {
            $this->logger->debug('Could not update join prompt', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<int, RequiredChannel> $missing
     */
    private function onStillMissing(
        string $lang,
        string $callbackId,
        ?int $chatId,
        ?int $messageId,
        array $missing
    ): void {
        if ($callbackId !== '') {
            $this->client->answerCallbackQuery(
                $callbackId,
                $this->language->get($this->config->getStillMissingKey(), $lang),
                true
            );
        }

        if (!is_int($chatId) || !is_int($messageId)) {
            return;
        }

        $text     = $this->language->get($this->config->getMessageKey(), $lang);
        $keyboard = $this->buildKeyboard($missing, $lang);

        try {
            $this->client->editMessageText(
                chatId: $chatId,
                messageId: $messageId,
                text: $text,
                keyboard: $keyboard,
                parseMode: 'HTML',
            );
        } catch (\Throwable $e) {
            $this->logger->debug('Could not update join prompt', ['error' => $e->getMessage()]);
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
}
