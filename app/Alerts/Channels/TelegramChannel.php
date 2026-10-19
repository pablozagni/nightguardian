<?php

namespace App\Alerts\Channels;

use App\Alerts\AlertMessage;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Telegram Bot API `sendMessage` (HTML parse mode, 10 s timeout). The bot token lives only in the
 * encrypted channel config; it is never put in an exception message or a log.
 */
class TelegramChannel implements AlertChannel
{
    private const API = 'https://api.telegram.org';

    public static function type(): string
    {
        return 'telegram';
    }

    public static function label(): string
    {
        return __('alerts.channels.telegram');
    }

    public static function configForm(): array
    {
        return [
            TextInput::make('bot_token')
                ->label(__('alerts.telegram.bot_token'))
                ->helperText(__('alerts.telegram.bot_token_help'))
                ->password()
                ->revealable()
                ->autocomplete(false)
                ->required(fn (?string $operation): bool => $operation === 'create')
                ->placeholder(fn (?string $operation): ?string => $operation === 'edit' ? __('alerts.keep_secret') : null),
            TextInput::make('chat_id')
                ->label(__('alerts.telegram.chat_id'))
                ->helperText(__('alerts.telegram.chat_id_help'))
                ->required(),
        ];
    }

    public static function validate(array $config): array
    {
        $problems = [];

        if (! is_string($config['bot_token'] ?? null) || preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $config['bot_token']) !== 1) {
            $problems[] = __('alerts.telegram.bad_token');
        }

        if (! is_scalar($config['chat_id'] ?? null) || preg_match('/^(-?\d{4,}|@[A-Za-z0-9_]{4,})$/', (string) $config['chat_id']) !== 1) {
            $problems[] = __('alerts.telegram.bad_chat_id');
        }

        return $problems;
    }

    public function send(AlertMessage $message, array $config): void
    {
        $token = (string) ($config['bot_token'] ?? '');

        try {
            $response = Http::timeout(10)->asJson()->post(self::API.'/bot'.$token.'/sendMessage', [
                'chat_id' => $config['chat_id'] ?? '',
                'text' => $message->toHtml(),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('Telegram: '.$this->scrub($e->getMessage(), $token), 0, $e);
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            $reason = $response->json('description');

            throw new RuntimeException('Telegram: '.$this->scrub(is_string($reason) ? $reason : 'HTTP '.$response->status(), $token));
        }
    }

    public function test(array $config): void
    {
        $this->send(new AlertMessage('test', '✅', __('alerts.test.headline'), __('alerts.test.body')), $config);
    }

    private function scrub(string $text, string $token): string
    {
        return $token === '' ? $text : str_replace($token, '[token]', $text);
    }
}
