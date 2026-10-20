#!/usr/bin/env python3
"""Baut die Tiled-Bausteine für die Dungeon-Räume aus dem Kenney-Paket „Isometric Miniature Dungeon“ (CC0).

Aufruf (Pfad zum entpackten Paket, das die Ordner Isometric/ und tiled/ enthält):

    python3 tools/dungeon/build_blocks.py /pfad/zu/kenney_isometric-miniature-dungeon [--preview ordner]

Erzeugt unter public/dungeon/:
  sprites/*.png      verkleinerte Sprites (128x256), nur die benutzten
  dungeon.tsx        Tiled-Tileset (Bildersammlung, isometrisch 128x64)
  blocks/<key>.tmx   ein Raum je Baustein-Schlüssel aus database/content/dungeons.json
  LICENSE-kenney.txt Lizenz des Pakets

Jeder Raum ist 5x5 Felder groß. Die Raumrichtungen entsprechen dem Dungeon-Raster:
Norden (y-1) liegt oben rechts (Zeile 0), Westen (x-1) oben links (Spalte 0),
Süden unten links (Zeile 4), Osten unten rechts (Spalte 4). Die Türen liegen jeweils
in der Mitte einer Seite. Die Türlücken bleiben in den Wand-Ebenen frei; der Client
setzt dort das Sprite für die Kante ein (Zuordnung in dungeons.json unter "art").

Ebenen: floor, walls_back, props, walls_front und die Objektebene spots
(door_N/E/S/W, spawn, monster, chest, trap, puzzle, lever, campfire, merchant, boss).
"""

import argparse
import json
import pathlib
import shutil
import sys

from PIL import Image

ROOT = pathlib.Path(__file__).resolve().parents[2]
OUT = ROOT / 'public' / 'dungeon'
SIZE = 5
MID = SIZE // 2
TILE_W, TILE_H = 128, 64
SPRITE_W, SPRITE_H = 128, 256

# Seite des Raums -> Richtungs-Endung des Kenney-Sprites, das auf dieser Kante sitzt
SIDE_SUFFIX = {'N': 'S', 'W': 'E', 'S': 'N', 'E': 'W'}
DOOR_CELL = {'N': (MID, 0), 'W': (0, MID), 'S': (MID, SIZE - 1), 'E': (SIZE - 1, MID)}

# Kanten-Sprites, die der Client in die Türlücken setzt (alle vier Richtungen werden exportiert)
EDGE_SPRITES = [
    'stoneWall', 'stoneWallHalf', 'stoneWallArchway', 'stoneWallDoorClosed', 'stoneWallDoorOpen',
    'stoneWallGateClosed', 'stoneWallGateOpen', 'stoneWallBroken', 'stoneWallHole',
]
# Zustands-Sprites für die Spots
STATE_SPRITES = ['chestClosed', 'chestOpen', 'stoneMissingTiles', 'stoneInset']

FLOOR = 'stone'


def room(floor=FLOOR, floors=None, props=None, back=None, spots=None):
    """floors/props: {(c, r): 'name_DIR'}; back: Ersatz-Wandstücke {(seite, i): 'name'}; spots: {name: (c, r)}."""
    return {'floor': floor, 'floors': floors or {}, 'props': props or {}, 'back': back or {}, 'spots': spots or {}}


CENTER = (MID, MID)

BLOCKS = {
    'entrance_stairs': room(
        props={(0, 0): 'stairsSpiral_S', (4, 0): 'barrels_S', (0, 4): 'woodenCrates_E', (3, 1): 'woodenCrate_W'},
        spots={'spawn': CENTER},
    ),
    'entrance_gate': room(
        props={(1, 0): 'stoneColumn_S', (3, 0): 'stoneColumn_S', (0, 1): 'stoneColumn_E', (0, 3): 'stoneColumn_E', (4, 4): 'woodenPile_N'},
        floors={(1, 1): 'stoneInset_S', (3, 3): 'stoneInset_S'},
        spots={'spawn': CENTER},
    ),
    'hall_pillars': room(
        props={(1, 1): 'stoneColumn_S', (3, 1): 'stoneColumn_S', (1, 3): 'stoneColumn_S', (3, 3): 'stoneColumn_S', (4, 0): 'barrel_W'},
        floors={(2, 1): 'stoneUneven_S', (1, 2): 'stoneUneven_S'},
        spots={'monster': CENTER, 'spawn': (MID, 3)},
    ),
    'corridor_bend': room(
        floor='dirt',
        floors={(c, MID): 'stone_S' for c in range(SIZE)} | {(MID, r): 'stone_S' for r in range(SIZE)} | {(4, 4): 'dirtTiles_S'},
        props={(0, 0): 'woodenSupportBeams_S', (4, 0): 'woodenSupports_S', (0, 4): 'woodenSupports_S', (3, 3): 'woodenPile_N'},
        spots={'monster': CENTER, 'spawn': (MID, 3)},
    ),
    'storeroom': room(
        floor='planks',
        props={(0, 0): 'barrelsStacked_S', (1, 0): 'woodenCrates_S', (0, 1): 'barrels_E', (3, 0): 'woodenCrate_S',
               (4, 0): 'barrel_W', (0, 4): 'woodenPile_E', (4, 3): 'woodenCrates_W', (3, 4): 'barrel_N'},
        spots={'monster': CENTER, 'spawn': (MID, 3)},
    ),
    'arena_small': room(
        floor='stoneUneven',
        floors={(c, r): 'stone_S' for c in range(1, 4) for r in range(1, 4)},
        props={(0, 0): 'stoneColumn_S', (4, 0): 'stoneColumn_S', (0, 4): 'stoneColumn_S', (4, 4): 'stoneColumn_S',
               (3, 0): 'tableChairsBroken_W', (0, 3): 'woodenPile_E'},
        spots={'monster': CENTER, 'spawn': (MID, 4)},
    ),
    'vault_chests': room(
        floor='stoneInset',
        props={(0, 0): 'barrelsStacked_S', (4, 0): 'woodenCrates_S', (1, 0): 'chestClosed_S', (3, 0): 'chestOpen_S',
               (0, 1): 'stoneColumn_E', (0, 3): 'stoneColumn_E'},
        spots={'chest': (MID, 1), 'spawn': (MID, 3)},
    ),
    'alcove_chest': room(
        props={(1, 0): 'stoneColumn_S', (3, 0): 'stoneColumn_S', (1, 1): 'stoneColumn_S', (3, 1): 'stoneColumn_S', (4, 4): 'barrel_N'},
        floors={(MID, 0): 'stoneInset_S', (MID, 1): 'stoneInset_S'},
        spots={'chest': (MID, 0), 'spawn': (MID, 3)},
    ),
    'trap_floor': room(
        floors={(1, 1): 'stoneUneven_S', (3, 3): 'stoneUneven_S', (1, 3): 'stoneMissingTiles_S', (3, 1): 'stoneMissingTiles_S'},
        props={(4, 0): 'woodenPile_W', (0, 4): 'barrel_N'},
        spots={'trap': CENTER, 'spawn': (MID, 4)},
    ),
    'trap_darts': room(
        back={('N', 1): 'stoneWallHole', ('N', 3): 'stoneWallHole', ('W', 1): 'stoneWallHole', ('W', 3): 'stoneWallHole'},
        floors={(MID, 1): 'stoneInset_S', (MID, 3): 'stoneInset_S', (1, MID): 'stoneInset_S', (3, MID): 'stoneInset_S'},
        spots={'trap': CENTER, 'spawn': (MID, 4)},
    ),
    'rune_door': room(
        floors={(1, MID): 'stoneInset_S', (3, MID): 'stoneInset_S', (MID, 1): 'stoneInset_S', (MID, MID): 'stoneInset_S'},
        props={(0, 0): 'stoneColumn_S', (4, 0): 'stoneColumn_S', (0, 4): 'stoneColumn_S', (4, 4): 'woodenCrate_W'},
        spots={'puzzle': CENTER, 'spawn': (MID, 4)},
    ),
    'sliding_tiles': room(
        floors={(c, r): ('stoneInset_S' if (c + r) % 2 else 'stone_S') for c in range(SIZE) for r in range(SIZE)},
        props={(0, 0): 'stoneColumn_S', (4, 4): 'stoneColumn_S'},
        spots={'puzzle': CENTER, 'spawn': (MID, 4)},
    ),
    'camp_ruins': room(
        floor='dirt',
        floors={(1, 1): 'planksBroken_S', (3, 3): 'dirtTiles_S'},
        back={('N', 1): 'stoneWallBroken', ('W', 3): 'stoneWallBroken'},
        props={(0, 3): 'tableChairsBroken_E', (3, 0): 'woodenPile_S', (4, 4): 'woodenSupports_S', (0, 0): 'woodenSupportBeams_S'},
        spots={'campfire': CENTER, 'spawn': (MID, 4)},
    ),
    'merchant_nook': room(
        floor='planks',
        props={(MID, 0): 'tableShortChairs_S', (0, 0): 'barrelsStacked_S', (4, 0): 'woodenCrates_S',
               (0, 1): 'barrels_E', (0, 4): 'woodenCrate_E', (4, 4): 'barrel_N'},
        spots={'merchant': (MID, 1), 'spawn': (MID, 3)},
    ),
    'lever_room': room(
        props={(0, 0): 'woodenSupportBeams_S', (4, 4): 'woodenSupports_S', (4, 0): 'barrels_W', (0, 4): 'woodenCrates_E'},
        floors={(MID, 1): 'stoneInset_S'},
        spots={'lever': (MID, 1), 'spawn': (MID, 3)},
    ),
    'boss_throne': room(
        floors={(c, 0): 'stoneSteps_S' for c in range(1, 4)} | {(c, 1): 'stoneStep_S' for c in range(1, 4)},
        props={(0, 0): 'stoneColumn_S', (4, 0): 'stoneColumn_S', (0, 3): 'stoneColumn_E', (4, 3): 'stoneColumn_W'},
        spots={'boss': (MID, 1), 'spawn': (MID, 4)},
    ),
    'boss_lair': room(
        floor='stoneUneven',
        floors={(1, 1): 'dirt_S', (3, 1): 'dirtTiles_S', (1, 3): 'dirt_S', (3, 3): 'dirtTiles_S', (MID, MID): 'dirt_S'},
        back={('N', 1): 'stoneWallBroken', ('W', 3): 'stoneWallBroken'},
        props={(0, 0): 'woodenSupportBeams_S', (4, 4): 'tableChairsBroken_W', (0, 4): 'woodenPile_N', (4, 0): 'barrels_W'},
        spots={'boss': CENTER, 'spawn': (MID, 4)},
    ),
}


def wall_cells(side):
    for i in range(SIZE):
        yield i, ((i, 0) if side == 'N' else (0, i) if side == 'W' else (i, SIZE - 1) if side == 'S' else (SIZE - 1, i))


def layers(block):
    floor = {(c, r): f"{block['floor']}_S" for c in range(SIZE) for r in range(SIZE)} | block['floors']
    back, front = {}, {}
    for side in 'NW':
        for i, cell in wall_cells(side):
            if i != MID:
                back.setdefault(cell, []).append(f"{block['back'].get((side, i), 'stoneWall')}_{SIDE_SUFFIX[side]}")
    for side in 'SE':
        for i, cell in wall_cells(side):
            if i != MID:
                front.setdefault(cell, []).append(f"stoneWallHalf_{SIDE_SUFFIX[side]}")
    return floor, back, block['props'], front


def sprite_names():
    names = set()
    for block in BLOCKS.values():
        floor, back, props, front = layers(block)
        names |= set(floor.values()) | set(props.values())
        names |= {n for cell in back.values() for n in cell} | {n for cell in front.values() for n in cell}
    names |= {f'{n}_{d}' for n in EDGE_SPRITES + STATE_SPRITES for d in 'NESW'}
    return sorted(names) + ['switchFloorOn_E']


def export_sprites(src, names):
    (OUT / 'sprites').mkdir(parents=True, exist_ok=True)
    for old in (OUT / 'sprites').glob('*.png'):
        old.unlink()
    images = {}
    for name in names:
        path = src / 'Isometric' / f'{name}.png'
        if not path.exists():
            path = src / 'tiled' / 'Sprites' / f'{name}.png'
        img = Image.open(path).convert('RGBA').resize((SPRITE_W, SPRITE_H), Image.LANCZOS)
        img.save(OUT / 'sprites' / f'{name}.png', optimize=True)
        images[name] = img
    return images


def write_tileset(names):
    lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        f'<tileset version="1.10" tiledversion="1.10.2" name="dungeon" tilewidth="{SPRITE_W}" tileheight="{SPRITE_H}" tilecount="{len(names)}" columns="0">',
        f' <grid orientation="isometric" width="{TILE_W}" height="{TILE_H}"/>',
    ]
    for i, name in enumerate(names):
        lines += [f' <tile id="{i}">', f'  <properties><property name="key" value="{name}"/></properties>',
                  f'  <image width="{SPRITE_W}" height="{SPRITE_H}" source="sprites/{name}.png"/>', ' </tile>']
    lines.append('</tileset>')
    (OUT / 'dungeon.tsx').write_text('\n'.join(lines) + '\n')


def csv(cells, gid):
    rows = []
    for r in range(SIZE):
        rows.append(','.join(str(gid[cells[(c, r)]] if (c, r) in cells else 0) for c in range(SIZE)))
    return ',\n'.join(rows)


def write_block(key, block, gid):
    floor, back, props, front = layers(block)
    # Mehrere Wandstücke auf einem Feld (Ecke) bekommen je eine eigene Ebene
    def split(cells):
        out = [{}, {}]
        for cell, names in cells.items():
            for i, n in enumerate(names):
                out[i][cell] = n
        return [o for o in out if o]

    tile_layers = [('floor', floor)] + [(f'walls_back{i or ""}', l) for i, l in enumerate(split(back))] \
        + [('props', props)] + [(f'walls_front{i or ""}', l) for i, l in enumerate(split(front))]
    lid = 1
    lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        f'<map version="1.10" tiledversion="1.10.2" orientation="isometric" renderorder="right-down" width="{SIZE}" height="{SIZE}" '
        f'tilewidth="{TILE_W}" tileheight="{TILE_H}" infinite="0" nextlayerid="{len(tile_layers) + 2}" nextobjectid="64">',
        f' <properties><property name="block" value="{key}"/></properties>',
        ' <tileset firstgid="1" source="../dungeon.tsx"/>',
    ]
    for name, cells in tile_layers:
        lines += [f' <layer id="{lid}" name="{name}" width="{SIZE}" height="{SIZE}">', '  <data encoding="csv">',
                  csv(cells, gid), '</data>', ' </layer>']
        lid += 1
    lines.append(f' <objectgroup id="{lid}" name="spots">')
    oid = 1
    for side, (c, r) in DOOR_CELL.items():
        # Türen sitzen auf der Außenkante des Feldes
        x = {'N': c + 0.5, 'S': c + 0.5, 'W': 0, 'E': SIZE}[side] * TILE_H
        y = {'W': r + 0.5, 'E': r + 0.5, 'N': 0, 'S': SIZE}[side] * TILE_H
        lines += [f'  <object id="{oid}" name="door_{side}" type="door" x="{x:g}" y="{y:g}">',
                  f'   <properties><property name="side" value="{side}"/><property name="suffix" value="{SIDE_SUFFIX[side]}"/>'
                  f'<property name="col" type="int" value="{c}"/><property name="row" type="int" value="{r}"/>'
                  f'<property name="layer" value="{"walls_back" if side in "NW" else "walls_front"}"/></properties>',
                  '   <point/>', '  </object>']
        oid += 1
    for name, (c, r) in block['spots'].items():
        lines += [f'  <object id="{oid}" name="{name}" type="{name}" x="{(c + 0.5) * TILE_H:g}" y="{(r + 0.5) * TILE_H:g}">',
                  f'   <properties><property name="col" type="int" value="{c}"/><property name="row" type="int" value="{r}"/></properties>',
                  '   <point/>', '  </object>']
        oid += 1
    lines += [' </objectgroup>', '</map>']
    (OUT / 'blocks' / f'{key}.tmx').write_text('\n'.join(lines) + '\n')


def preview(key, block, images, target):
    """Kleines Vorschaubild mit offenen Türen und Truhe/Hebel an ihren Spots."""
    floor, back, props, front = layers(block)
    w = SIZE * TILE_W
    img = Image.new('RGBA', (w + 16, SIZE * TILE_H + SPRITE_H), (38, 34, 40, 255))
    extra_back = {DOOR_CELL[s]: [f'stoneWallArchway_{SIDE_SUFFIX[s]}'] for s in 'NW'}
    extra_front = {DOOR_CELL[s]: [f'stoneWallArchway_{SIDE_SUFFIX[s]}'] for s in 'SE'}
    spot_props = {}
    if 'chest' in block['spots']:
        spot_props[block['spots']['chest']] = 'chestClosed_S'
    if 'lever' in block['spots']:
        spot_props[block['spots']['lever']] = 'switchFloorOn_E'

    def draw(cells):
        for r in range(SIZE):
            for c in range(SIZE):
                names = cells.get((c, r))
                for n in [names] if isinstance(names, str) else names or []:
                    x = w // 2 + (c - r) * TILE_W // 2 - SPRITE_W // 2 + 8
                    y = (c + r) * TILE_H // 2 + TILE_H - SPRITE_H + SPRITE_H - TILE_H
                    img.alpha_composite(images[n], (x, y))

    draw(floor)
    draw({k: back.get(k, []) + extra_back.get(k, []) for k in set(back) | set(extra_back)})
    draw(props | spot_props)
    draw({k: front.get(k, []) + extra_front.get(k, []) for k in set(front) | set(extra_front)})
    bbox = img.getchannel('A').point(lambda a: 255).getbbox()
    img.crop(bbox).convert('RGB').resize((w // 2 + 8, img.height // 2)).save(target / f'{key}.png', optimize=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('source', type=pathlib.Path)
    parser.add_argument('--preview', type=pathlib.Path)
    args = parser.parse_args()

    content = json.loads((ROOT / 'database' / 'content' / 'dungeons.json').read_text())
    wanted = {k for keys in content['blocks'].values() for k in keys}
    missing = wanted - set(BLOCKS)
    if missing:
        sys.exit(f'Baustein fehlt im Skript: {", ".join(sorted(missing))}')

    names = sprite_names()
    images = export_sprites(args.source, names)
    write_tileset(names)
    gid = {n: i + 1 for i, n in enumerate(names)}
    (OUT / 'blocks').mkdir(parents=True, exist_ok=True)
    for old in (OUT / 'blocks').glob('*.tmx'):
        old.unlink()
    for key in sorted(wanted):
        write_block(key, BLOCKS[key], gid)
    shutil.copy(args.source / 'License.txt', OUT / 'LICENSE-kenney.txt')
    if args.preview:
        args.preview.mkdir(parents=True, exist_ok=True)
        for key in sorted(wanted):
            preview(key, BLOCKS[key], images, args.preview)
    print(f'{len(wanted)} Bausteine, {len(names)} Sprites -> {OUT.relative_to(ROOT)}')


if __name__ == '__main__':
    main()
