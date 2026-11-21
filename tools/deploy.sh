#!/usr/bin/env bash
# Actualiza el plugin en uno o más sitios de Hostinger con `git pull`.
#
# Uso:
#   tools/deploy.sh                 # todos los sitios de tools/deploy.local
#   tools/deploy.sh midominio.com   # solo ese sitio
#
# Configuración en tools/deploy.local (no se sube a git), por ejemplo:
#   SSH_HOST=hostinger
#   SITES="sitio-uno.com sitio-dos.com"
set -euo pipefail

cd "$(dirname "$0")"
[ -f deploy.local ] && . ./deploy.local

SSH_HOST="${SSH_HOST:-hostinger}"
PLUGIN_SLUG="${PLUGIN_SLUG:-viable}"
BRANCH="${BRANCH:-master}"

if [ $# -gt 0 ]; then
  sites=("$@")
else
  read -r -a sites <<< "${SITES:-}"
fi
if [ ${#sites[@]} -eq 0 ]; then
  echo "Indicá el dominio o definí SITES en tools/deploy.local" >&2
  exit 1
fi

# Avisar si hay commits locales sin subir (el servidor baja lo que está en GitHub)
git fetch -q origin "$BRANCH" || true
ahead=$(git rev-list --count "origin/$BRANCH..$BRANCH" 2>/dev/null || echo 0)
if [ "$ahead" -gt 0 ]; then
  echo "Atención: tenés $ahead commit(s) en $BRANCH sin push; el servidor no los va a recibir." >&2
fi

status=0
for site in "${sites[@]}"; do
  echo "── $site"
  if ! ssh "$SSH_HOST" bash -s -- "$site" "$PLUGIN_SLUG" "$BRANCH" <<'REMOTE'
set -euo pipefail
site="$1"; slug="$2"; branch="$3"
dir="$HOME/domains/$site/public_html/wp-content/plugins/$slug"
cd "$dir" || { echo "No existe $dir"; exit 1; }

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "Hay archivos modificados en el servidor; no actualizo:"
  git status --short --untracked-files=no
  exit 1
fi

before=$(git rev-parse --short HEAD)
git pull --ff-only -q origin "$branch"
after=$(git rev-parse --short HEAD)

if [ "$before" = "$after" ]; then
  echo "Ya estaba actualizado ($after)"
else
  echo "$before → $after"
  git log --oneline "$before..$after"
fi
REMOTE
  then
    status=1
  fi
done

exit $status
