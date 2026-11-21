# Viable – Obras

Plugin de WordPress para fichas de obras, mapas (Leaflet, fondo Mapa/Satélite) y planos georreferenciados (PMTiles).

Requiere **ACF**.

## Dónde están los datos

El plugin es código: se instala y actualiza con git. Los **datos de cada sitio no van en el repo**:

| Qué | Dónde |
|---|---|
| Trazados (GeoPackage) | `wp-content/uploads/viable/viable.gpkg` |
| Planos de cada proyecto (`.pmtiles`) | Biblioteca de medios, campo "Planos (PMTiles)" |
| Fichas, eventos, etc. | Base de datos de WordPress |

Para usar otra ruta de trazados: `define('VIABLE_GPKG_PATH', '/ruta/al/archivo.gpkg');` en `wp-config.php`.

## Conexión SSH a Hostinger (una vez, en tu PC)

1. hPanel → **Avanzado → Acceso SSH**: anotá IP, puerto (65002) y usuario (`u…`). Activá SSH si está apagado.
2. Si no tenés clave: `ssh-keygen -t ed25519`. Pegá el contenido de `~/.ssh/id_ed25519.pub` en hPanel → **Claves SSH**.
3. En `~/.ssh/config`:

   ```
   Host hostinger
       HostName <IP>
       Port 65002
       User <usuario>
   ```

4. Probá: `ssh hostinger` tiene que entrar sin pedir contraseña.
5. Creá `tools/deploy.local` (no se sube a git):

   ```
   SSH_HOST=hostinger
   SITES="dominio-uno.com dominio-dos.com"
   ```

## Pasar un sitio a instalación por git (una vez por sitio)

En el servidor (`ssh hostinger`), reemplazando `DOMINIO`:

```bash
cd ~/domains/DOMINIO/public_html/wp-content

# 1. Ver cómo se llama la carpeta actual del plugin. Los comandos asumen "viable";
#    si se llama distinto, usá ese nombre acá y poné PLUGIN_SLUG=<nombre> en deploy.local
ls plugins/

# 2. Mover los trazados fuera del plugin
mkdir -p uploads/viable
cp plugins/viable/assets/data/viable.gpkg uploads/viable/

# 3. Clonar el plugin aparte y reemplazar la carpeta en un solo paso
git clone -q https://github.com/pertile/viable.git ~/viable-nuevo
mv plugins/viable ~/viable-respaldo-$(date +%F) && mv ~/viable-nuevo plugins/viable

# 4. Revisar qué tenía la copia vieja que no esté en git (debería ser solo assets/data)
diff -rq ~/viable-respaldo-*/ plugins/viable/ -x .git
```

Después, en wp-admin: el plugin tiene que seguir activo, los mapas tienen que verse y **no** debe aparecer el aviso amarillo "Obras: …" sobre los trazados. Si algo falla, volver atrás es: `mv plugins/viable ~/viable-fallido && mv ~/viable-respaldo-FECHA plugins/viable`.

Cuando todo esté bien, se puede borrar el respaldo.

## Actualizar el plugin

```bash
git push                      # el servidor baja lo que está en GitHub
bash tools/deploy.sh          # todos los sitios de deploy.local
bash tools/deploy.sh DOMINIO  # o uno solo
```

El script hace `git pull --ff-only` en cada sitio y muestra los commits nuevos. No toca nada si alguien editó archivos directamente en el servidor. Los JS y CSS se versionan por fecha de modificación, así que no hace falta limpiar caché del navegador; si usás LiteSpeed Cache, purgalo.

## Actualizar los trazados

```bash
bash tools/subir-trazados.sh DOMINIO                    # sube assets/data/viable.gpkg
bash tools/subir-trazados.sh DOMINIO otro/archivo.gpkg
```

`assets/data/viable.gpkg` es tu copia de trabajo local (QGIS); está en `.gitignore`.

## Planos (PMTiles)

```bash
pip install pmtiles
python tools/tiles-to-pmtiles.py teselas.zip -o PROYECTO.pmtiles --name "…" --attribution "…"
```

Se suben en el campo "Planos (PMTiles)" del proyecto. Para JOSM, debajo del campo aparece la URL TMS lista para copiar.
