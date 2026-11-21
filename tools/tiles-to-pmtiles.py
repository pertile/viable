#!/usr/bin/env python3
"""
Convierte una carpeta (o un .zip) de teselas XYZ {z}/{x}/{y}.png en un único
archivo .pmtiles listo para subir al campo "Planos (PMTiles)" de un proyecto.

Requisitos:
    pip install pmtiles

Uso básico:
    python tools/tiles-to-pmtiles.py RP13_leaflet.zip -o H13-3.pmtiles \
        --name "RP13 Sección III" --attribution "Planos: DVP Chaco"

Recortar a una zona (útil cuando un mismo juego de teselas cubre varios
proyectos). Se conservan las teselas que tocan el rectángulo:
    python tools/tiles-to-pmtiles.py RP13_leaflet.zip -o H13-4.pmtiles \
        --bbox -60.80,-27.70,-60.4276,-27.40

Opciones útiles:
    --bbox OESTE,SUR,ESTE,NORTE   recorta por rectángulo (grados WGS84)
    --min-zoom / --max-zoom       descarta niveles de zoom
    --opacity 0.6                 opacidad inicial sugerida en el mapa
    --tms                         si las teselas usan el esquema TMS (y invertida)
    --layer tiles/rp13            si el zip tiene más de un juego de teselas
"""

import argparse
import math
import os
import re
import sys
import zipfile

try:
    from pmtiles.tile import Compression, TileType, zxy_to_tileid
    from pmtiles.writer import Writer
except ImportError:
    sys.exit("Falta la librería pmtiles. Instalala con:  pip install pmtiles")

TILE_RE = re.compile(r"^(?P<prefix>.*?)(?P<z>\d+)/(?P<x>\d+)/(?P<y>\d+)\.(?P<ext>png|jpe?g|webp)$", re.I)
TILE_TYPES = {"png": TileType.PNG, "jpg": TileType.JPEG, "jpeg": TileType.JPEG, "webp": TileType.WEBP}


def tile_bounds(z, x, y):
    """Devuelve (oeste, sur, este, norte) en grados de una tesela XYZ."""
    n = 2 ** z

    def lat(yy):
        return math.degrees(math.atan(math.sinh(math.pi * (1 - 2 * yy / n))))

    return (x / n * 360 - 180, lat(y + 1), (x + 1) / n * 360 - 180, lat(y))


class Source:
    """Lista y lee teselas desde una carpeta o un .zip."""

    def __init__(self, path):
        self.path = path
        self.zip = zipfile.ZipFile(path) if zipfile.is_zipfile(path) else None
        if not self.zip and not os.path.isdir(path):
            sys.exit(f"No existe la carpeta o el zip: {path}")

    def names(self):
        if self.zip:
            return [n for n in self.zip.namelist() if not n.endswith("/")]
        out = []
        for root, _, files in os.walk(self.path):
            for f in files:
                out.append(os.path.relpath(os.path.join(root, f), self.path).replace(os.sep, "/"))
        return out

    def read(self, name):
        if self.zip:
            return self.zip.read(name)
        with open(os.path.join(self.path, name), "rb") as f:
            return f.read()


def parse_bbox(text):
    try:
        w, s, e, n = (float(v) for v in text.split(","))
    except ValueError:
        raise argparse.ArgumentTypeError("usar OESTE,SUR,ESTE,NORTE, por ejemplo -60.8,-27.7,-60.4,-27.4")
    if w >= e or s >= n:
        raise argparse.ArgumentTypeError("el rectángulo está invertido (OESTE < ESTE y SUR < NORTE)")
    return (w, s, e, n)


def main():
    ap = argparse.ArgumentParser(description="Convierte teselas XYZ (carpeta o zip) a PMTiles.")
    ap.add_argument("input", help="carpeta o .zip con teselas {z}/{x}/{y}.png")
    ap.add_argument("-o", "--output", required=True, help="archivo .pmtiles de salida")
    ap.add_argument("--layer", help="prefijo del juego de teselas dentro del zip (si hay más de uno)")
    ap.add_argument("--bbox", type=parse_bbox, help="OESTE,SUR,ESTE,NORTE: conserva solo las teselas que lo tocan")
    ap.add_argument("--min-zoom", type=int)
    ap.add_argument("--max-zoom", type=int)
    ap.add_argument("--tms", action="store_true", help="las teselas usan esquema TMS (y invertida)")
    ap.add_argument("--name", default="", help="nombre descriptivo de los planos")
    ap.add_argument("--attribution", default="", help="crédito que se muestra en el mapa")
    ap.add_argument("--opacity", type=float, default=0.6, help="opacidad inicial sugerida (0 a 1)")
    args = ap.parse_args()

    src = Source(args.input)

    # Agrupar teselas por prefijo (carpeta raíz del juego de teselas)
    groups = {}
    for name in src.names():
        m = TILE_RE.match(name)
        if m:
            groups.setdefault(m.group("prefix"), []).append((name, m))
    if not groups:
        sys.exit("No se encontraron teselas con la forma {z}/{x}/{y}.png")

    if args.layer:
        prefix = args.layer.strip("/") + "/"
        if prefix not in groups:
            sys.exit(f"No se encontró el juego '{args.layer}'. Disponibles: " + ", ".join(p or "(raíz)" for p in groups))
    elif len(groups) > 1:
        sys.exit("Hay más de un juego de teselas; elegí uno con --layer: " + ", ".join(p or "(raíz)" for p in groups))
    else:
        prefix = next(iter(groups))

    exts = {m.group("ext").lower() for _, m in groups[prefix]}
    if len(exts) > 1:
        sys.exit(f"Las teselas mezclan formatos ({', '.join(sorted(exts))}); PMTiles admite uno solo.")
    tile_type = TILE_TYPES[exts.pop()]

    selected = []
    for name, m in groups[prefix]:
        z, x, y = int(m.group("z")), int(m.group("x")), int(m.group("y"))
        if args.tms:
            y = (2 ** z) - 1 - y
        if args.min_zoom is not None and z < args.min_zoom:
            continue
        if args.max_zoom is not None and z > args.max_zoom:
            continue
        if args.bbox:
            w, s, e, n = tile_bounds(z, x, y)
            bw, bs, be, bn = args.bbox
            if e <= bw or w >= be or n <= bs or s >= bn:
                continue
        selected.append((zxy_to_tileid(z, x, y), z, x, y, name))

    if not selected:
        sys.exit("Ningún tile quedó dentro de los filtros indicados.")

    selected.sort()
    max_z = max(t[1] for t in selected)
    min_z = min(t[1] for t in selected)

    # Límites: unión de las teselas del zoom máximo, recortada al bbox pedido
    w, s, e, n = 180.0, 90.0, -180.0, -90.0
    for _, z, x, y, _ in selected:
        if z == max_z:
            tw, ts, te, tn = tile_bounds(z, x, y)
            w, s, e, n = min(w, tw), min(s, ts), max(e, te), max(n, tn)
    if args.bbox:
        w, s = max(w, args.bbox[0]), max(s, args.bbox[1])
        e, n = min(e, args.bbox[2]), min(n, args.bbox[3])

    out_dir = os.path.dirname(os.path.abspath(args.output))
    os.makedirs(out_dir, exist_ok=True)
    with open(args.output, "wb") as f:
        writer = Writer(f)
        for tileid, _, _, _, name in selected:
            writer.write_tile(tileid, src.read(name))
        writer.finalize(
            {
                "tile_type": tile_type,
                "tile_compression": Compression.NONE,
                "min_lon_e7": int(w * 1e7),
                "min_lat_e7": int(s * 1e7),
                "max_lon_e7": int(e * 1e7),
                "max_lat_e7": int(n * 1e7),
                "center_zoom": min_z,
                "center_lon_e7": int((w + e) / 2 * 1e7),
                "center_lat_e7": int((s + n) / 2 * 1e7),
            },
            {
                "name": args.name,
                "attribution": args.attribution,
                "opacity": max(0.0, min(1.0, args.opacity)),
            },
        )

    size_mb = os.path.getsize(args.output) / 1024 / 1024
    print(f"OK: {args.output}")
    print(f"  {len(selected)} teselas, zoom {min_z}-{max_z}, {size_mb:.1f} MB")
    print(f"  límites: oeste {w:.5f}, sur {s:.5f}, este {e:.5f}, norte {n:.5f}")


if __name__ == "__main__":
    main()
