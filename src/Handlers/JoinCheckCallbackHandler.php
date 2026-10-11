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
 * The middleware exempts callbacks whose data starts with "join:", so
 * this handler is guaranteed to reach the pipeline even when the user
 * is still blocked.
 *
 * JoinManager::missingChannels() re-checks every active channel. For
 * channels already marked as joined in the database the stored value
 * is trusted; for the others a fresh getChatMember call is issued to
 * catch the case where the user just joined and Telegram's chat_member
 * update has not been delivered yet. The result of that check is
 * persisted, so subsequent messages need no API call.
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

        if ($callbackId !== '') {
            $this->client->answerCallbackQuery(
                $callbackId,
                $this->language->get($this->config->getSuccessKey(), $lang)
            );
        }

        if (is_int($chatId) && is_int($messageId)) {
            try {
                $this->client->deleteMessage($chatId, $messageId);
            } catch (\Throwable $e) {
                $this->logger->debug('Could not delete join prompt', ['error' => $e->getMessage()]);
            }
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
            $this->client->editMessageText($chatId, $messageId, $text, $keyboard);
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
