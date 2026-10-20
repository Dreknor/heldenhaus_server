<?php

use App\Domain\Art\SceneBackgrounds;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    [$this->family, $this->parent, $this->child] = makeFamily();
});

function backgroundZip(array $names): string
{
    $path = tempnam(sys_get_temp_dir(), 'bg').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $image = imagecreatetruecolor(1792, 1024);
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    foreach ($names as $name) {
        $zip->addFromString("Fantasy Forests/{$name}.png", $png);
    }
    $zip->addFromString('Fantasy Forests/readme.txt', 'kein Bild');
    $zip->close();

    return $path;
}

it('zeigt ohne importierte Hintergründe die Fluent-Szene', function () {
    $this->actingAs($this->child)->get('/dashboard')->assertInertia(fn ($page) => $page->where('sceneBackground', null));
    expect(SceneBackgrounds::credit())->toBeNull();
});

it('importiert Hintergründe ohne Innenräume und nennt sie in den Credits', function () {
    $this->artisan('heldenhaus:import-backgrounds', ['zip' => backgroundZip(['Trees 1', 'Village 2', 'Castle Kitchen', 'Cottage 6 Tavern'])])
        ->expectsOutputToContain('2 Hintergründe importiert')
        ->assertSuccessful();

    Storage::disk('public')->assertExists(['scenes/trees-1.webp', 'scenes/village-2.webp']);
    Storage::disk('public')->assertMissing('scenes/castle-kitchen.webp');
    expect(getimagesizefromstring(Storage::disk('public')->get('scenes/trees-1.webp'))[0])->toBe(1280);

    $this->actingAs($this->child)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('sceneBackground', fn ($url) => str_contains($url, '/storage/scenes/') && str_ends_with($url, '.webp')));

    $this->get('/credits')->assertInertia(fn ($page) => $page
        ->where('credits.2.title', 'Szenen-Hintergründe: Fantasy Forest Backgrounds')
        ->where('credits.2.author', 'Lornn'));
});
