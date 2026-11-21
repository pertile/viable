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
[ -f "$here/deploy.local" ] && . "$here/deploy.local"
SSH_HOST="${SSH_HOST:-hostinger}"

site="${1:?Indicá el dominio, por ejemplo: tools/subir-trazados.sh midominio.com}"
file="${2:-$here/../assets/data/viable.gpkg}"
[ -f "$file" ] || { echo "No existe $file" >&2; exit 1; }

remote_dir="domains/$site/public_html/wp-content/uploads/viable"

ssh "$SSH_HOST" "mkdir -p '$remote_dir'"
scp -q "$file" "$SSH_HOST:$remote_dir/viable.gpkg.subiendo"
ssh "$SSH_HOST" "mv -f '$remote_dir/viable.gpkg.subiendo' '$remote_dir/viable.gpkg' && ls -l '$remote_dir/viable.gpkg'"
echo "Trazados actualizados en $site"
