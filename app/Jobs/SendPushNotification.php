<?php

namespace App\Jobs;

use App\Domain\Notifications\FcmClient;
use App\Domain\Notifications\PushNotifier;
use App\Models\DeviceToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /** @param  ?string  $type  null = stille Datennachricht */
    public function __construct(public readonly int $deviceId, public readonly ?string $type, public readonly array $data) {}

    public function handle(FcmClient $fcm): void
    {
        $device = DeviceToken::find($this->deviceId);
        if ($device === null || ! $fcm->configured()) {
            return;
        }

        [$title, $body] = $this->type ? PushNotifier::TYPES[$this->type] : [null, null];
        $result = $fcm->send($device->token, $title ? ['title' => $title, 'body' => $body] : null, $this->data);

        if ($result === FcmClient::INVALID_TOKEN) {
            $device->delete(); // App deinstalliert oder Token erneuert
        }
    }
}
