<?php

// Langzeitmotivation (Konzept §2.9): Ausrüstung soll man am Helden sehen – jedes Teil anders.

it('gibt jedem Ausrüstungsteil ein eigenes, vorhandenes Aussehen am Helden', function () {
    $rules = json_decode(file_get_contents(resource_path('avatars/lpc-rules.json')), true);
    $parts = json_decode(file_get_contents(resource_path('avatars/lpc.json')), true)['parts'];
    $equipment = collect(json_decode(file_get_contents(database_path('content/items.json')), true))->where('type', 'equipment');

    foreach ($equipment as $item) {
        expect($rules['items'])->toHaveKey($item['key']);
        foreach ($rules['items'][$item['key']] as [$part, $color]) {
            foreach (['male', 'female'] as $body) {
                expect($parts[$part][$body][$color] ?? null)->not->toBeNull("{$item['key']}: {$part}/{$body}/{$color} fehlt");
            }
        }
    }

    $looks = $equipment->map(fn ($item) => $item['slot'].json_encode($rules['items'][$item['key']]))->unique();
    expect($looks->count())->toBeGreaterThanOrEqual((int) floor($equipment->count() * 0.95));
});
