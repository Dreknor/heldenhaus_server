# Baut das Vorschaubild für die Dungeon-Karte auf dem Helden-Board aus den Kenney-Sprites (CC0).
# Aufruf: python3 tools/dungeon/build_teaser.py /tmp/teaser.png  (danach auf 480 px Breite als WebP nach public/images/ui/dungeon-teaser.webp)
import sys
from PIL import Image
import os
ROOT=os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
S=ROOT+'/public/dungeon/sprites/'
def sp(n): return Image.open(S+n+'.png').convert('RGBA')
N=5
W=N*128+128; H=N*64+300
img=Image.new('RGBA',(W,H),(0,0,0,0))
ox=W//2-64; oy=40
def put(n,c,r):
    img.alpha_composite(sp(n),(ox+(c-r)*64, oy+(c+r)*32))
floor={(c,r):'stone_S' for c in range(N) for r in range(N)}
floor[(2,2)]='dirtTiles_S'; floor[(3,1)]='stoneMissingTiles_S'; floor[(1,3)]='stoneUneven_S'
items={(0,0):'stoneColumn_S',(2,0):'stoneWallGateClosed_S',(1,0):'stoneWall_S',(3,0):'stoneWall_S',(4,0):'stoneWall_S',
       (0,1):'stoneWall_W',(0,2):'stoneWallArchway_W',(0,3):'stoneWall_W',(0,4):'stoneWall_W',
       (3,2):'chestClosed_S',(1,1):'barrels_S',(4,3):'woodenCrates_S',(2,3):'switchFloorOn_E'}
for s in range(2*N):
  for c in range(N):
    r=s-c
    if 0<=r<N:
      put(floor[(c,r)],c,r)
for s in range(2*N):
  for c in range(N):
    r=s-c
    if (c,r) in items: put(items[(c,r)],c,r)
img=img.crop(img.getbbox())
img.save(sys.argv[1])
print(img.size)
