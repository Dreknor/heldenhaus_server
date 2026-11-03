/**
 * Isometrische Dungeon-Karte aus den Tiled-Bausteinen (public/dungeon, Kenney „Isometric Miniature Dungeon“, CC0).
 * Gleiche Regeln wie die App (heldenhaus_app lib/ui/dungeon/dungeon_block.dart): 5×5 Felder je Raum,
 * Kachel 128×64, Bilder 128×256, Ebenen in Tiled-Reihenfolge; Türen, Truhe, Falle und Hebel an ihren Spots.
 *
 * Die Räume liegen im Dungeon-Raster (x → Spalte, y → Zeile) mit einem Feld Abstand: dort liegt der Gang.
 */

export const TILE_W = 128;
export const TILE_H = 64;
export const IMAGE_H = 256;
export const ROOM_SIZE = 5;
/** Felder von Raum zu Raum (5 Raum + 1 Gang) */
export const STRIDE = ROOM_SIZE + 1;
const MID = Math.floor(ROOM_SIZE / 2);

export type Side = 'N' | 'E' | 'S' | 'W';
export type DungeonArt = {
    tileset: string;
    block: string;
    sprites: string;
    side_suffix: Record<Side, string>;
    edges: Record<string, string>;
    spots: Record<string, string>;
};
export type Spot = { name: string; type: string; col: number; row: number; props: Record<string, string> };
export type Block = { name: string; layers: [string, (string | null)[][]][]; spots: Record<string, Spot> };
export type IsoSprite = { layer: string; col: number; row: number; key: string };
export type MapRoom = {
    id: number;
    x: number;
    y: number;
    fog: boolean;
    block?: string;
    doors?: Partial<Record<Side, { to: number; kind: string; sprite: string } | null>>;
    chest?: { opened: boolean };
    trap?: { sprung: boolean };
    lever?: { pulled: boolean };
};

let tileset: Promise<{ byGid: Map<number, string>; known: Set<string> }> | null = null;
const blocks = new Map<string, Promise<Block>>();
const images = new Map<string, Promise<HTMLImageElement | null>>();

async function xml(url: string): Promise<Document> {
    const response = await fetch(url);
    if (!response.ok) throw new Error(`${url}: ${response.status}`);
    return new DOMParser().parseFromString(await response.text(), 'application/xml');
}

function properties(node: Element): Record<string, string> {
    const props: Record<string, string> = {};
    node.querySelectorAll(':scope > properties > property').forEach((p) => (props[p.getAttribute('name') ?? ''] = p.getAttribute('value') ?? ''));
    return props;
}

function loadTileset(art: DungeonArt) {
    tileset ??= xml(art.tileset).then((doc) => {
        const byGid = new Map<number, string>();
        const known = new Set<string>();
        // Die Bausteine binden das Tileset mit firstgid 1 ein
        doc.querySelectorAll('tileset > tile').forEach((tile) => {
            const key = properties(tile).key;
            if (!key) return;
            byGid.set(1 + Number(tile.getAttribute('id')), key);
            known.add(key);
        });
        return { byGid, known };
    });
    return tileset;
}

export function loadBlock(art: DungeonArt, name: string): Promise<Block> {
    let block = blocks.get(name);
    if (!block) {
        block = Promise.all([loadTileset(art), xml(art.block.replace('{block}', name))]).then(([ts, doc]) => {
            const layers: Block['layers'] = [];
            doc.querySelectorAll('map > layer').forEach((layer) => {
                const csv = layer.querySelector('data')?.textContent?.trim() ?? '';
                const rows = csv
                    .split('\n')
                    .map((line) => line.split(',').filter((v) => v.trim() !== ''))
                    .filter((row) => row.length)
                    .map((row) => row.map((gid) => ts.byGid.get(Number(gid) & 0x1fffffff) ?? null));
                layers.push([layer.getAttribute('name') ?? '', rows]);
            });
            const spots: Record<string, Spot> = {};
            doc.querySelectorAll('objectgroup[name="spots"] > object').forEach((o) => {
                const props = properties(o);
                const name = o.getAttribute('name') ?? '';
                spots[name] = {
                    name,
                    type: o.getAttribute('type') ?? '',
                    col: Number(props.col ?? Math.floor(Number(o.getAttribute('x')) / TILE_H)),
                    row: Number(props.row ?? Math.floor(Number(o.getAttribute('y')) / TILE_H)),
                    props,
                };
            });
            return { name, layers, spots };
        });
        blocks.set(name, block);
    }
    return block;
}

export async function knownSprites(art: DungeonArt): Promise<Set<string>> {
    return (await loadTileset(art)).known;
}

export function spriteImage(art: DungeonArt, key: string): Promise<HTMLImageElement | null> {
    let image = images.get(key);
    if (!image) {
        image = new Promise((resolve) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = () => resolve(null);
            img.src = art.sprites.replace('{sprite}', key.replace(/_[NESW]$/, '')).replace('{suffix}', key.slice(-1));
        });
        images.set(key, image);
    }
    return image;
}

/** Zeichenliste eines Raums: Türen je Seite (sonst Wand), Truhe, Falle und Hebel an ihren Spots. */
export function compose(block: Block, room: MapRoom, art: DungeonArt, known: Set<string>): IsoSprite[] {
    const grid = new Map(block.layers.map(([name, rows]) => [name, rows.map((r) => [...r])]));
    const put = (layer: string, col: number, row: number, key: string) => {
        const rows = grid.get(layer) ?? grid.get('props');
        if (!rows || !rows[row] || col < 0 || col >= rows[row].length || !known.has(key)) return;
        rows[row][col] = key;
    };

    for (const side of ['N', 'E', 'S', 'W'] as Side[]) {
        const spot = block.spots[`door_${side}`];
        if (!spot) continue;
        const layer = spot.props.layer ?? 'walls_back';
        const door = room.doors?.[side];
        const key =
            door?.sprite ?? `${art.edges[layer.startsWith('walls_front') ? 'none_front' : 'none_back'] ?? 'stoneWall'}_${spot.props.suffix ?? 'S'}`;
        put(layer, spot.col, spot.row, key);
    }
    const at = (name: string) => block.spots[name];
    if (room.chest && at('chest'))
        put('props', at('chest').col, at('chest').row, `${art.spots[room.chest.opened ? 'chest_open' : 'chest_closed']}_S`);
    if (room.trap && at('trap')) put('floor', at('trap').col, at('trap').row, `${art.spots[room.trap.sprung ? 'trap_sprung' : 'trap_hidden']}_S`);
    if (room.lever && at('lever')) put('props', at('lever').col, at('lever').row, `${art.spots.lever}_E`);

    const sprites: IsoSprite[] = [];
    for (const [name] of block.layers) {
        grid.get(name)!.forEach((cells, row) => cells.forEach((key, col) => key && sprites.push({ layer: name, col, row, key })));
    }
    return sprites;
}

/** Nebelraum: nur ein dunkler Boden, Wände folgen beim Betreten. */
export function fogSprites(): IsoSprite[] {
    const sprites: IsoSprite[] = [];
    for (let row = 0; row < ROOM_SIZE; row++) for (let col = 0; col < ROOM_SIZE; col++) sprites.push({ layer: 'floor', col, row, key: 'stone_S' });
    return sprites;
}

/** Gangfeld zwischen zwei Räumen (in Weltfeldern) */
export function corridorCell(a: { x: number; y: number }, b: { x: number; y: number }): { col: number; row: number } | null {
    if (a.y === b.y && Math.abs(a.x - b.x) === 1) return { col: Math.min(a.x, b.x) * STRIDE + ROOM_SIZE, row: a.y * STRIDE + MID };
    if (a.x === b.x && Math.abs(a.y - b.y) === 1) return { col: a.x * STRIDE + MID, row: Math.min(a.y, b.y) * STRIDE + ROOM_SIZE };
    return null;
}

/** Obere Ecke der Raute von Weltfeld (col,row) */
export function cellTop(col: number, row: number) {
    return { x: ((col - row) * TILE_W) / 2, y: ((col + row) * TILE_H) / 2 };
}

/** Mitte eines Feldes in einem Raum (Standpunkt von Figuren) */
export function roomCellCenter(room: { x: number; y: number }, col: number, row: number) {
    const top = cellTop(room.x * STRIDE + col, room.y * STRIDE + row);
    return { x: top.x, y: top.y + TILE_H / 2 };
}

/** Weltgrenzen eines Raums inklusive der hohen Wandbilder */
export function roomBounds(room: { x: number; y: number }) {
    const left = cellTop(room.x * STRIDE, room.y * STRIDE + ROOM_SIZE - 1).x - TILE_W / 2;
    const right = cellTop(room.x * STRIDE + ROOM_SIZE - 1, room.y * STRIDE).x + TILE_W / 2;
    const top = cellTop(room.x * STRIDE, room.y * STRIDE).y + TILE_H - IMAGE_H;
    const bottom = cellTop(room.x * STRIDE + ROOM_SIZE - 1, room.y * STRIDE + ROOM_SIZE - 1).y + TILE_H;
    return { left, right, top, bottom };
}

export const ROOM_CENTER = MID;
