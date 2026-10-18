<?php

namespace App\Jobs;

use App\Alerts\AlertMessage;
use App\Alerts\ChannelRegistry;
use App\Models\AlertChannel;
use App\Models\AlertLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Delivers one message through one channel: 3 tries, backoff 30 / 120 / 600 s.
 * The outcome goes to alert_logs and to alert_channels.last_error / last_sent_at.
 */
class SendAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $message  AlertMessage::toArray()
     */
    public function __construct(
        public readonly int $channelId,
        public readonly string $kind,
        public readonly ?string $issueId,
        public readonly array $message,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(ChannelRegistry $registry): void
    {
        $channel = AlertChannel::query()->find($this->channelId);

        if ($channel === null || ! $channel->enabled) {
            return;
        }

        try {
            $registry->make($channel->type)->send(AlertMessage::fromArray($this->message), $channel->config);
        } catch (Throwable $e) {
            // Keep the reason visible in the panel right away; the final failure is logged by failed().
            $channel->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            throw $e;
        }

        $channel->forceFill(['last_error' => null, 'last_sent_at' => now()])->save();
        $this->log('sent');
    }

    public function failed(Throwable $e): void
    {
        $this->log('failed', mb_substr($e->getMessage(), 0, 1000));
    }

    private function log(string $status, ?string $error = null): void
    {
        if (! AlertChannel::query()->whereKey($this->channelId)->exists()) {
            return;
        }

        AlertLog::create([
            'alert_channel_id' => $this->channelId,
            'issue_id' => $this->issueId,
            'kind' => $this->kind,
            'status' => $status,
            'error' => $error,
        ]);
    }
}
