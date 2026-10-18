<?php

namespace App\Alerts\Channels;

use App\Alerts\AlertMessage;
use Filament\Forms\Components\TagsInput;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Plain-text e-mail through the application's configured Laravel mailer.
 */
class MailChannel implements AlertChannel
{
    public static function type(): string
    {
        return 'mail';
    }

    public static function label(): string
    {
        return __('alerts.channels.mail');
    }

    public static function configForm(): array
    {
        return [
            TagsInput::make('recipients')
                ->label(__('alerts.mail.recipients'))
                ->helperText(__('alerts.mail.recipients_help'))
                ->nestedRecursiveRules(['email'])
                ->required(),
        ];
    }

    public static function validate(array $config): array
    {
        $recipients = self::recipients($config);

        if ($recipients === []) {
            return [__('alerts.mail.no_recipients')];
        }

        foreach ($recipients as $address) {
            if (Validator::make(['a' => $address], ['a' => 'email'])->fails()) {
                return [__('alerts.mail.bad_recipient', ['address' => $address])];
            }
        }

        return [];
    }

    public function send(AlertMessage $message, array $config): void
    {
        $recipients = self::recipients($config);

        Mail::raw($message->toPlain(), function (Message $mail) use ($recipients, $message): void {
            $mail->to($recipients)->subject($message->subject());
        });
    }

    public function test(array $config): void
    {
        $this->send(new AlertMessage('test', '✅', __('alerts.test.headline'), __('alerts.test.body')), $config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function recipients(array $config): array
    {
        $list = $config['recipients'] ?? [];

        return is_array($list) ? array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', $list))) : [];
    }
}
