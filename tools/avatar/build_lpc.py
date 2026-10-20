#!/usr/bin/env python3
"""
Baut die Avatar-Teile aus dem Universal LPC Spritesheet Character Generator
(https://github.com/LiberatedPixelCup/Universal-LPC-Spritesheet-Character-Generator).

Aus jedem verwendeten Spritesheet wird nur das Stehbild nach vorn (walk, Reihe „Süden“, Bild 0, 64×64 px)
ausgeschnitten, bei Bedarf mit den LPC-Paletten umgefärbt und nach public/avatar/lpc/ geschrieben.
resources/avatars/lpc.json beschreibt, welche Dateien in welcher Reihenfolge übereinander liegen,
und resources/avatars/lpc-credits.json die Credits jeder verwendeten Datei (nur Teile mit CC0, CC-BY oder OGA-BY als Lizenzoption).

Aufruf:  python3 tools/avatar/build_lpc.py /pfad/zum/lpc-generator
"""

import hashlib
import json
import sys
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
OUT_DIR = ROOT / 'public/avatar/lpc'
MANIFEST = ROOT / 'resources/avatars/lpc.json'
CREDITS = ROOT / 'resources/avatars/lpc-credits.json'

FRAME = 64
SOUTH_ROW = 2
BODY_TYPES = ['male', 'female']

SKINS = ['light', 'amber', 'olive', 'taupe', 'bronze', 'brown', 'black']
HAIR_COLORS = ['black', 'dark_brown', 'chestnut', 'light_brown', 'blonde', 'platinum', 'ginger', 'redhead', 'gray', 'pink', 'purple', 'blue', 'green']
CLOTH = ['red', 'blue', 'green', 'yellow', 'purple', 'orange', 'gray', 'pink', 'teal', 'navy', 'white', 'black']

# Teil → LPC-Definition und Farben. „palette:“ färbt über die Palette des Materials um, sonst sind es Varianten-Dateien.
PARTS = {
    # Körper und Gesicht
    'body': ('body/body', SKINS),
    'head': ({'male': 'head/heads/human/heads_human_male', 'female': 'head/heads/human/heads_human_female'}, SKINS),
    'face_happy': ('head/faces/face_happy', SKINS),
    'face_sad': ('head/faces/face_sad', SKINS),
    'face_closing': ('head/faces/face_closing', SKINS),
    'face_shock': ('head/faces/face_shock', SKINS),
    # Frisuren und Bärte
    'hair_plain': ('hair/short/hair_plain', HAIR_COLORS),
    'hair_spiked': ('hair/spiky/hair_spiked', HAIR_COLORS),
    'hair_curly_short': ('hair/curly/hair_curly_short', HAIR_COLORS),
    'hair_cowlick': ('hair/short/hair_cowlick', HAIR_COLORS),
    'hair_bob': ('hair/bob/hair_bob', HAIR_COLORS),
    'hair_long_straight': ('hair/long/hair_long_straight', HAIR_COLORS),
    'hair_wavy': ('hair/long/hair_wavy', HAIR_COLORS),
    'hair_high_ponytail': ('hair/braids/hair_high_ponytail', HAIR_COLORS),
    'hair_bangs_bun': ('hair/braids/hair_bangs_bun', HAIR_COLORS),
    'hair_braid': ('hair/braids/hair_braid', HAIR_COLORS),
    'hair_afro': ('hair/afro/hair_afro', HAIR_COLORS),
    'hair_buzzcut': ('hair/bald/hair_buzzcut', HAIR_COLORS),
    'beard_trimmed': ('hair/beards/beards_trimmed', HAIR_COLORS),
    'beard_medium': ('hair/beards/beards_medium', HAIR_COLORS),
    'beard_winter': ('hair/beards/beards_winter', HAIR_COLORS),
    'beard_handlebar': ('hair/mustaches/beards_handlebar', HAIR_COLORS),
    # Grundkleidung
    'shirt_tshirt': ('torso/shirts/shortsleeve/torso_clothes_tshirt', CLOTH),
    'shirt_longsleeve': ('torso/shirts/longsleeve/torso_clothes_longsleeve2', CLOTH + ['leather']),
    'shirt_buttoned': ('torso/shirts/longsleeve/torso_clothes_longsleeve2_buttoned', CLOTH),
    'shirt_cardigan': ('torso/shirts/longsleeve/torso_clothes_longsleeve2_cardigan', CLOTH),
    'shirt_scoop': ('torso/shirts/shortsleeve/torso_clothes_tshirt_scoop', CLOTH),
    'skirt': ('legs/skirts/legs_skirts_plain', CLOTH),
    'pants': ('legs/pants/legs_pants', ['charcoal']),
    'shoes': ('feet/shoes/feet_shoes_basic', ['walnut']),
    # Klassen-Kostüme
    'cape': ('torso/cape/cape_solid', ['red', 'blue', 'white', 'purple', 'forest']),
    'cape_tattered': ('torso/cape/cape_tattered', ['black']),
    'hat_wizard': ('headwear/hats/magic/hat_magic_celestial', ['blue']),
    'hood': ('headwear/coverings/hoods/hat_hood_cloth', ['charcoal', 'white']),
    'belt_robe': ('torso/waist/belt_robe', ['white']),
    'overalls': ('torso/aprons/torso_aprons_overalls', ['leather']),
    'headband': ('headwear/coverings/headbands/hat_headband_tied', ['red']),
    'cap_leather': ('headwear/hats/caps/hat_cap_leather', ['base', 'brown']),
    'cap_feather': ('headwear/hats/caps/hat_cap_leather_feather', ['red']),
    # Ausrüstung
    'cap_bonnie': ('headwear/hats/caps/hat_cap_bonnie', ['red']),
    'helmet_nasal': ('headwear/helmets/helmets/hat_helmet_nasal', ['iron']),
    'helmet_bascinet': ('headwear/helmets/helmets/hat_helmet_bascinet', ['steel']),
    'helmet_armet': ('headwear/helmets/helmets/hat_helmet_armet', ['silver']),
    'helmet_greathelm': ('headwear/helmets/helmets/hat_helmet_greathelm', ['gold']),
    'armour_leather': ('torso/armour/torso_armour_leather', ['brown']),
    'chainmail': ('torso/torso_chainmail', ['iron', 'steel']),
    'armour_plate': ('torso/armour/torso_armour_plate', ['steel', 'silver', 'gold']),
    'arms_plate': ('arms/arms_armour', ['silver', 'gold']),
    'dagger': ('weapons/sword/weapon_sword_dagger', ['dagger']),
    'sword': ('weapons/sword/weapon_sword_arming', ['bronze', 'iron', 'steel', 'silver', 'gold']),
    'staff': ('weapons/magic/weapon_magic_simple', ['simple']),
    'staff_diamond': ('weapons/magic/weapon_magic_diamond', ['medium']),
    'hammer': ('tools/tool_hammer', ['base']),
    'shield_heater': ('weapons/shields/heater/shield_heater_wood', ['base']),
    'shield_round': ('weapons/shields/shield_round', ['brown', 'silver', 'gold']),
    'shield_crusader': ('weapons/shields/engrailed/shield_crusader', ['crusader']),
    'glasses': ('headwear/accessories/glasses/facial_glasses', ['black']),
    'necklace': ('headwear/neck/neck_necklace_chain', ['iron', 'bronze', 'silver', 'gold']),
}

OK_LICENSES = ('CC0', 'CC-BY 3.0', 'CC-BY 4.0', 'OGA-BY 3.0', 'OGA-BY 4.0')


def load_json(path: Path):
    return json.loads(path.read_text(encoding='utf-8'))


class Lpc:
    def __init__(self, root: Path):
        self.root = root
        self.palettes = {}

    def definition(self, name: str) -> dict:
        return load_json(self.root / 'sheet_definitions' / f'{name}.json')

    def palette(self, material: str) -> tuple[list[str], dict[str, list[str]]]:
        if material not in self.palettes:
            meta = load_json(self.root / 'palette_definitions' / material / f'meta_{material}.json')
            colors = load_json(self.root / 'palette_definitions' / material / f'{material}_ulpc.json')
            self.palettes[material] = (colors[meta['base']], colors)
        return self.palettes[material]

    def frame(self, path: Path) -> Image.Image | None:
        if not path.is_file():
            return None
        sheet = Image.open(path).convert('RGBA')
        if sheet.height < FRAME * (SOUTH_ROW + 1):
            return None
        return sheet.crop((0, FRAME * SOUTH_ROW, FRAME, FRAME * (SOUTH_ROW + 1)))


def hex_rgb(value: str) -> tuple[int, int, int]:
    value = value.lstrip('#')
    return int(value[0:2], 16), int(value[2:4], 16), int(value[4:6], 16)


def recolor(img: Image.Image, source: list[str], target: list[str]) -> Image.Image:
    pairs = [(hex_rgb(s), hex_rgb(t)) for s, t in zip(source, target)]
    out = img.copy()
    px = out.load()
    for y in range(out.height):
        for x in range(out.width):
            r, g, b, a = px[x, y]
            if a == 0:
                continue
            for (sr, sg, sb), (tr, tg, tb) in pairs:
                if abs(r - sr) <= 1 and abs(g - sg) <= 1 and abs(b - sb) <= 1:
                    px[x, y] = (tr, tg, tb, a)
                    break
    return out


def material_of(definition: dict) -> str | None:
    rec = definition.get('recolors')
    if not isinstance(rec, dict):
        return None
    if 'material' in rec:
        return rec['material']
    return rec.get('color_1', {}).get('material')


def head_for(body_type: str) -> str:
    return 'male' if body_type == 'male' else 'female'


def build(lpc_root: Path) -> None:
    lpc = Lpc(lpc_root)
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    for old in OUT_DIR.glob('*.png'):
        old.unlink()

    manifest = {'frame': FRAME, 'parts': {}, 'palettes': {}, 'credits': {}}
    written: dict[str, str] = {}

    for material, names in (('body', SKINS), ('hair', HAIR_COLORS), ('cloth', CLOTH)):
        _, colors = lpc.palette(material)
        # Mittlerer Ton als Farbfeld im Baukasten
        manifest['palettes'][material] = {n: '#' + colors[n][len(colors[n]) // 2].lstrip('#').lower() for n in names}

    for part, (defs, colors) in PARTS.items():
        manifest['parts'][part] = {}
        for body_type in BODY_TYPES:
            def_name = defs[body_type] if isinstance(defs, dict) else defs
            d = lpc.definition(def_name)
            material = material_of(d)
            variants = d.get('variants') or []
            per_color = {}
            for color in colors:
                layers = []
                for key, layer in d.items():
                    if not key.startswith('layer_') or layer.get('custom_animation'):
                        continue
                    rel = layer.get(body_type)
                    if not rel:
                        continue
                    rel = rel.replace('${head}', head_for(body_type)).rstrip('/')
                    base = lpc_root / 'spritesheets' / rel
                    if variants and color in variants:
                        img = lpc.frame(base / 'walk' / f'{color}.png')
                    else:
                        img = lpc.frame(base / 'walk.png')
                        if img is not None and material and color != 'base':
                            source, palette = lpc.palette(material)
                            if color not in palette:
                                raise SystemExit(f'{part}: Farbe {color} fehlt in Palette {material}')
                            img = recolor(img, source, palette[color])
                    if img is None or img.getbbox() is None:
                        # Hintergrund-Ebenen gibt es nicht für jede Animation
                        continue
                    data = img.tobytes()
                    digest = hashlib.sha1(data).hexdigest()[:12]
                    name = written.get(digest)
                    if name is None:
                        name = f'{part}-{digest}.png'
                        img.save(OUT_DIR / name, optimize=True)
                        written[digest] = name
                    layers.append([layer['zPos'], name])
                    for credit in d.get('credits', []):
                        if rel.startswith(credit['file'].rstrip('/')) or credit['file'].startswith(rel):
                            licenses = [lic for lic in credit['licenses'] if lic.startswith(OK_LICENSES)]
                            if not licenses:
                                raise SystemExit(f'{part}: {credit["file"]} hat keine erlaubte Lizenz ({credit["licenses"]})')
                            manifest['credits'][credit['file']] = {
                                'authors': credit['authors'],
                                'licenses': licenses,
                                'urls': credit['urls'],
                            }
                if not layers:
                    raise SystemExit(f'{part}/{body_type}/{color}: kein Stehbild gefunden')
                per_color[color] = sorted(layers)
            manifest['parts'][part][body_type] = per_color

    credits = [{'file': f, **c} for f, c in sorted(manifest.pop('credits').items())]
    MANIFEST.write_text(json.dumps(manifest, ensure_ascii=False, separators=(',', ':')) + '\n', encoding='utf-8')
    CREDITS.write_text(json.dumps(credits, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')
    print(f'{len(written)} Bilder, {len(credits)} Credit-Einträge')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit(__doc__)
    build(Path(sys.argv[1]).resolve())
