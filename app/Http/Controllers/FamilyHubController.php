<?php

namespace App\Http\Controllers;

use App\Domain\Family\WeeklyReport;
use App\Domain\Social\FeedService;
use App\Models\AllowanceEntry;
use App\Models\FeedEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Familien-Feed, Taschengeld-Konto und Wochenbericht (Web und API). */
class FamilyHubController extends Controller
{
    public function feed(Request $request): JsonResponse
    {
        return response()->json(['entries' => FeedService::present($request->user()->family_id, $request->user()->id)]);
    }

    public function react(Request $request, FeedEntry $entry, FeedService $feed): RedirectResponse|JsonResponse
    {
        $emoji = $request->validate(['emoji' => ['required', 'string', 'max:8']])['emoji'];
        $added = $feed->react($request->user(), $entry, $emoji);

        return $request->expectsJson() ? response()->json(['added' => $added]) : back();
    }

    public function allowance(Request $request): Response|JsonResponse
    {
        $user = $request->user();
        $members = $user->isParent()
            ? $user->family->members()->where('family_role', 'child')->orderBy('name')->get()
            : collect([$user]);

        $data = [
            'accounts' => $members->map(fn (User $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'avatar_color' => $m->avatar_color,
                'balance_cents' => (int) AllowanceEntry::where('user_id', $m->id)->sum('amount_cents'),
                'entries' => AllowanceEntry::where('user_id', $m->id)->latest('id')->limit(15)->get()
                    ->map(fn (AllowanceEntry $e) => [
                        'id' => $e->id, 'amount_cents' => $e->amount_cents, 'description' => $e->description, 'date' => $e->created_at->toDateString(),
                    ]),
            ])->values(),
            'isParent' => $user->isParent(),
        ];

        return $request->expectsJson() ? response()->json($data) : Inertia::render('family/Allowance', $data);
    }

    public function storeAllowance(Request $request): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->isParent(), 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'between:-1000,1000', 'not_in:0'],
            'description' => ['required', 'string', 'max:120'],
        ], [], ['amount' => 'Betrag', 'description' => 'Beschreibung']);

        $member = User::findOrFail($data['user_id']);
        abort_unless($request->user()->sameFamilyAs($member), 403);

        $entry = AllowanceEntry::create([
            'family_id' => $member->family_id,
            'user_id' => $member->id,
            'amount_cents' => (int) round($data['amount'] * 100),
            'description' => $data['description'],
            'created_by' => $request->user()->id,
        ]);

        return $request->expectsJson() ? response()->json($entry, 201) : back()->with('success', 'Gebucht.');
    }

    public function report(Request $request): Response|JsonResponse
    {
        abort_unless($request->user()->isParent(), 403);
        $week = $request->query('week');
        $start = $week ? CarbonImmutable::parse($week)->startOfWeek() : null;
        $report = WeeklyReport::for($request->user()->family, $start);

        return $request->expectsJson() ? response()->json($report) : Inertia::render('family/Report', ['report' => $report]);
    }
}
