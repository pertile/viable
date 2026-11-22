#!/usr/bin/env bash
# Sube el GeoPackage de trazados a wp-content/uploads/viable/ de un sitio.
# Se sube con nombre temporal y se renombra al final, para que el sitio nunca
# lea un archivo a medio subir.
#
# Uso:
#   tools/subir-trazados.sh midominio.com                     # sube assets/data/viable.gpkg
#   tools/subir-trazados.sh midominio.com ruta/al/archivo.gpkg
#
# Usa SSH_HOST de tools/deploy.local (por defecto "hostinger").
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
# Se limpian CRLF y BOM por si el archivo se creó en Windows
[ -f "$here/deploy.local" ] && . <(sed -e 's/\r$//' -e '1s/^\xEF\xBB\xBF//' "$here/deploy.local")
SSH_HOST="${SSH_HOST:-hostinger}"

site="${1:?Indicá el dominio, por ejemplo: tools/subir-trazados.sh midominio.com}"
file="${2:-$here/../assets/data/viable.gpkg}"
[ -f "$file" ] || { echo "No existe $file" >&2; exit 1; }

remote_dir="domains/$site/public_html/wp-content/uploads/viable"

# Si QGIS dejó cambios en el "-wal" (modo WAL), subir solo el .gpkg los perdería:
# se sube una copia consolidada (un único archivo con todo). No modifica el original.
if [ -f "$file-wal" ]; then
  tmpdir="$(mktemp -d)"
  trap 'rm -rf "$tmpdir"' EXIT
  python -c "
import sqlite3, sys
src = sqlite3.connect('file:' + sys.argv[1] + '?mode=ro', uri=True)
dst = sqlite3.connect(sys.argv[2])
src.backup(dst)
dst.execute('PRAGMA journal_mode=DELETE')
dst.close(); src.close()
" "$file" "$tmpdir/viable.gpkg" || { echo "No se pudo consolidar el GeoPackage (¿está Python instalado?)" >&2; exit 1; }
  file="$tmpdir/viable.gpkg"
  echo "GeoPackage en modo WAL: se sube una copia consolidada"
fi

ssh "$SSH_HOST" "mkdir -p '$remote_dir'"
scp -q "$file" "$SSH_HOST:$remote_dir/viable.gpkg.subiendo"
ssh "$SSH_HOST" "mv -f '$remote_dir/viable.gpkg.subiendo' '$remote_dir/viable.gpkg' && ls -l '$remote_dir/viable.gpkg'"
echo "Trazados actualizados en $site"
