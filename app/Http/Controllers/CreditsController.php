<?php

namespace App\Http\Controllers;

use App\Domain\Art\SceneBackgrounds;
use Inertia\Inertia;
use Inertia\Response;

/** Öffentliche Credits-Seite (Konzept §2.8): Quellen und Lizenzen aller Grafiken, bei LPC auch je Datei. */
class CreditsController extends Controller
{
    public function __invoke(): Response
    {
        $read = fn (string $path) => json_decode(file_get_contents(base_path($path)), true, flags: JSON_THROW_ON_ERROR);

        $credits = collect($read('resources/game-assets/credits.json'))->map(function (array $credit) use ($read) {
            if (isset($credit['details_file'])) {
                $credit['details'] = $read($credit['details_file']);
                $credit['contributors'] = collect($credit['details'])->pluck('authors')->flatten()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
            }
            unset($credit['details_file']);

            return $credit;
        })->when(SceneBackgrounds::credit(), fn ($c, $credit) => $c->push($credit));

        return Inertia::render('Credits', ['credits' => $credits]);
    }
}
