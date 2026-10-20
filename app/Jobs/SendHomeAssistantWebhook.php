<?php

namespace App\Jobs;

use App\Domain\Integrations\HomeAssistant\HomeAssistantWebhooks;
use App\Models\HaAuditEntry;
use App\Models\HaWebhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Einen Webhook an HA zustellen. Fehler → erneuter Versuch über die Queue (HA kann kurz offline sein). */
class SendHomeAssistantWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 20;

    public function __construct(public readonly int $webhookId, public readonly array $payload) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(): void
    {
        $webhook = HaWebhook::find($this->webhookId);
        if ($webhook === null || ! $webhook->active) {
            return;
        }

        $body = json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = HomeAssistantWebhooks::headers($webhook->secret, $body, $this->payload['event'], $this->payload['id']);

        try {
            $response = Http::timeout(10)->withHeaders($headers)->withBody($body, 'application/json')->post($webhook->url);
            $status = $response->status();
            $error = $response->successful() ? null : 'HTTP '.$status;
        } catch (Throwable $e) {
            $status = null;
            $error = mb_substr($e->getMessage(), 0, 250);
        }

        $webhook->forceFill([
            'last_sent_at' => now(),
            'last_status' => $status,
            'last_error' => $error,
            'failures' => $error ? $webhook->failures + 1 : 0,
        ])->save();

        HaAuditEntry::create([
            'family_id' => $webhook->family_id,
            'direction' => 'out',
            'action' => 'webhook:'.$this->payload['event'],
            'member_id' => $this->payload['data']['member_id'] ?? null,
            'status' => $status,
            'details' => array_filter(['delivery' => $this->payload['id'], 'attempt' => $this->attempts(), 'error' => $error]),
        ]);

        if ($error !== null) {
            throw new RuntimeException("Home-Assistant-Webhook fehlgeschlagen: {$error}");
        }
    }
}
