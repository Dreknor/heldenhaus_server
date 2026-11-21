<?php

namespace App\Http\Controllers;

use App\Domain\Insights\ActivityScores;
use App\Domain\Insights\AdminInsights;
use App\Domain\Insights\FamilyMetrics;
use App\Domain\Insights\Hints;
use App\Domain\Insights\ReviewService;
use App\Domain\Insights\SurveyService;
use App\Domain\Stats\StatsService;
use App\Models\Review;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Statistik-Seite, Rückblicke (§14.5), Umfragen und Kennzahlen „So läuft's“ (§14.10) sowie die
 * zusammengefassten Admin-Zahlen – im Web (Inertia) und über die API v1.
 */
class InsightsController extends Controller
{
    public function __construct(
        private readonly ReviewService $reviews,
        private readonly SurveyService $surveys,
        private readonly FamilyMetrics $metrics,
        private readonly Hints $hints,
    ) {}

    // ---------- Statistik (Web) ----------

    public function stats(Request $request): Response
    {
        $viewer = $request->user();
        $subject = $viewer;
        if ($request->filled('member')) {
            $subject = User::findOrFail($request->integer('member'));
            abort_unless($viewer->is($subject) || ($viewer->isParent() && $viewer->sameFamilyAs($subject)), 403);
        }

        return Inertia::render('stats/Index', [
            'subject' => ['id' => $subject->id, 'name' => $subject->name, 'is_self' => $viewer->is($subject)],
            'stats' => StatsService::forUser($subject, $viewer),
            'activity' => app(ActivityScores::class)->value($subject),
            'reviews' => collect(ReviewService::KINDS)->mapWithKeys(fn ($kind) => [$kind => ReviewService::present($this->reviews->get($subject, $kind), $viewer)]),
            'members' => $viewer->isParent()
                ? $viewer->family->members()->where('family_role', '!=', 'guest')->orderBy('name')->get(['id', 'name', 'avatar_color'])
                : [],
            'family' => $viewer->isParent() ? StatsService::forFamily($viewer->family, $viewer) : null,
            'categories' => config('game.categories'),
        ]);
    }

    // ---------- Rückblick ----------

    public function review(Request $request, string $kind): JsonResponse
    {
        return $this->respondReview($request, $kind, $request->user());
    }

    public function memberReview(Request $request, User $member, string $kind): JsonResponse
    {
        return $this->respondReview($request, $kind, $member);
    }

    private function respondReview(Request $request, string $kind, User $subject): JsonResponse
    {
        abort_unless(in_array($kind, ReviewService::KINDS, true), 404);
        $viewer = $request->user();
        abort_unless($viewer->is($subject) || ($viewer->isParent() && $viewer->sameFamilyAs($subject)), 403);

        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? null;
        $review = $this->reviews->get($subject, $kind, $date ? CarbonImmutable::parse($date, ReviewService::timezone($subject)) : null);

        return response()->json(['review' => ReviewService::present($review, $viewer)]);
    }

    public function seen(Request $request, Review $review): RedirectResponse|JsonResponse
    {
        $this->reviews->markSeen($request->user(), $review);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function share(Request $request, Review $review): RedirectResponse|JsonResponse
    {
        $this->reviews->share($request->user(), $review);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Im Familien-Feed geteilt!');
    }

    // ---------- Umfragen ----------

    public function dueSurvey(Request $request): JsonResponse
    {
        return response()->json(['survey' => $this->surveys->due($request->user())]);
    }

    public function storeSurvey(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['baseline', 'mood'])],
            'skip' => ['boolean'],
            'answers' => ['nullable', 'array'],
            'answers.mood' => ['nullable', 'integer', 'between:1,5'],
            ...collect(array_keys(SurveyService::BASELINE))->mapWithKeys(fn ($k) => ["answers.{$k}" => ['nullable', 'integer', 'between:1,5']])->all(),
            'answers.reasons' => ['nullable', 'array'],
            'answers.reasons.*' => ['string', Rule::in(array_keys(SurveyService::MOOD_REASONS))],
        ]);

        $survey = $this->surveys->submit($request->user(), $data['kind'], ($data['skip'] ?? false) ? null : ($data['answers'] ?? []));

        return $request->expectsJson()
            ? response()->json(['id' => $survey->id, 'kind' => $survey->kind, 'skipped' => $survey->answers === null], 201)
            : back()->with('success', $survey->answers === null ? 'Okay, wir fragen später wieder.' : 'Danke für deine Antwort!');
    }

    // ---------- Kennzahlen (Eltern) ----------

    public function metrics(Request $request): Response|JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isParent(), 403);

        $data = [
            'summary' => $this->metrics->summary($user->family),
            'hints' => $this->hints->for($user->family),
        ];
        if ($request->expectsJson()) {
            return response()->json($data);
        }

        return Inertia::render('family/Insights', [
            ...$data,
            'compare' => $this->metrics->compare($user->family),
            'survey' => $this->surveys->due($user),
        ]);
    }

    public function compare(Request $request): JsonResponse
    {
        abort_unless($request->user()->isParent(), 403);

        return response()->json($this->metrics->compare($request->user()->family));
    }

    // ---------- Admin ----------

    public function admin(AdminInsights $admin): Response
    {
        return Inertia::render('admin/Insights', ['summary' => $admin->summary()]);
    }
}
