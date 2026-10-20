<?php

namespace App\Http\Middleware;

use App\Models\HaAuditEntry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prüfprotokoll für Home Assistant (Konzept §4.5): jede ändernde Anfrage mit Ergebnis.
 * Abfragen (GET, alle 60 s) werden nicht protokolliert, sonst wäre das Protokoll nur Rauschen.
 */
class LogHomeAssistantRequest
{
    private const LOGGED_INPUT = ['event', 'user_id', 'member_id', 'title', 'stars', 'reason', 'comment'];

    public function handle(Request $request, Closure $next): Response
    {
        // vor $next lesen – ActAsFamilyMember entfernt den Parameter
        $member = $request->route('member');
        $familyId = $request->user()?->family_id;

        $response = $next($request);

        if ($familyId && ! $request->isMethodSafe()) {
            $details = $request->only(self::LOGGED_INPUT);
            if (! $response->isSuccessful() && $response->headers->get('Content-Type') === 'application/json') {
                $details['message'] = mb_substr((string) (json_decode($response->getContent(), true)['message'] ?? ''), 0, 200);
            }

            HaAuditEntry::create([
                'family_id' => $familyId,
                'direction' => 'in',
                'action' => $request->method().' '.preg_replace('#^api/v1/#', '', $request->path()),
                'member_id' => is_numeric($member) ? (int) $member : ($request->integer('member_id') ?: $request->integer('user_id') ?: null),
                'status' => $response->getStatusCode(),
                'details' => array_filter($details, fn ($v) => $v !== null && $v !== '') ?: null,
                'ip' => $request->ip(),
            ]);
        }

        return $response;
    }
}
