"""Misst die mittlere Helligkeit (0–1) jedes Tierbildes in public/images/pets, nur deckende Pixel.

PetArt.vue und die App gleichen damit die Schlüpfpulver-Färbung an: helle Tiere (Frosch) und dunkle
(schwarze Katze) erreichen mit demselben Pulver dieselbe Zielhelligkeit.

    python tools/pets/pet_luma.py   → schreibt resources/js/lib/petLuma.json
"""
import json
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
lumas = {}
for f in sorted((ROOT / 'public/images/pets').glob('*.webp')):
    im = Image.open(f).convert('RGBA')
    im.thumbnail((128, 128))
    total = weight = 0.0
    px = im.load()
    for r, g, b, a in (px[x, y] for x in range(im.width) for y in range(im.height)):
        if a < 128:
            continue
        total += (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255
        weight += 1
    lumas[f.stem] = round(total / weight, 3) if weight else 0.5

(ROOT / 'resources/js/lib/petLuma.json').write_text(json.dumps(lumas, indent=1) + '\n', encoding='utf-8')
print(len(lumas), 'Tiere')
