<?php

use App\Domain\Art\SceneBackgrounds;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        ->where('credits', fn ($credits) => collect($credits)->contains(fn ($c) => $c['title'] === 'Szenen-Hintergründe: Fantasy Forest Backgrounds' && $c['author'] === 'Lornn')));
});

it('nimmt das ZIP im Online-Updater in Stücken an und entpackt es in Portionen', function () {
    Storage::fake('local');
    $this->parent->forceFill(['is_admin' => true])->save();
    $zip = file_get_contents(backgroundZip(array_map(fn ($i) => "Trees {$i}", range(1, 10))));
    $upload = (string) Str::uuid();
    $chunks = str_split($zip, (int) ceil(strlen($zip) / 3));

    $this->actingAs($this->child)->post('/admin/updates/backgrounds/chunk')->assertForbidden();
    $this->actingAs($this->parent);

    $offset = 0;
    foreach ($chunks as $chunk) {
        $this->post('/admin/updates/backgrounds/chunk', ['upload' => $upload, 'offset' => $offset, 'chunk' => UploadedFile::fake()->createWithContent('chunk', $chunk)])
            ->assertOk()->assertJson(['received' => $offset += strlen($chunk)]);
    }
    // Ein doppelt gesendetes Stück wird abgelehnt, die Seite erfährt den richtigen Stand
    $this->post('/admin/updates/backgrounds/chunk', ['upload' => $upload, 'offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk', $chunks[0])])
        ->assertStatus(409)->assertJson(['received' => strlen($zip)]);

    $this->post('/admin/updates/backgrounds/import', ['upload' => $upload, 'from' => 0])->assertOk()->assertJson(['total' => 10, 'next' => 8, 'finished' => false]);
    Storage::disk('public')->assertMissing('scenes/index.json');
    $this->post('/admin/updates/backgrounds/import', ['upload' => $upload, 'from' => 8])->assertOk()
        ->assertJson(['next' => 10, 'finished' => true, 'summary' => ['title' => 'Fantasy Forest Backgrounds', 'count' => 10]]);

    expect(SceneBackgrounds::summary()['count'])->toBe(10)
        ->and(Storage::disk('local')->files('scene-uploads'))->toBeEmpty();

    $this->delete('/admin/updates/backgrounds')->assertRedirect();
    expect(SceneBackgrounds::summary())->toBeNull();
});

it('meldet ein kaputtes Paket verständlich', function () {
    Storage::fake('local');
    $this->parent->forceFill(['is_admin' => true])->save();
    $upload = (string) Str::uuid();

    $this->actingAs($this->parent)
        ->post('/admin/updates/backgrounds/chunk', ['upload' => $upload, 'offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk', 'kein zip')])->assertOk();
    $this->post('/admin/updates/backgrounds/import', ['upload' => $upload, 'from' => 0])
        ->assertStatus(422)->assertJson(['message' => 'Die Datei ist kein lesbares ZIP-Archiv.']);
});
