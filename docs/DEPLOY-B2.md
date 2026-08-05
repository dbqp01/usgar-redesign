# DEPLOY-B2 — Deploy sin rebuild en Hostinger (rama `build`)

**Estado:** mecanismo preparado (workflow `.github/workflows/deploy-build-branch.yml`).
**Switch manual pendiente:** el usuario debe apuntar Hostinger a la rama `build` en hPanel (pasos abajo).

## Por qué

Hoy el deploy es manual: `npm run build` local + subir `dist/` por FTP. Sin SSH no hay rsync.
Hostinger **Advanced → Git** resuelve el transporte: conecta el repo y, en cada push a la rama
elegida, **copia los archivos a `public_html` sin ejecutar ningún build** (Hostinger no compila).

Para que eso funcione, la rama debe llevar el sitio YA compilado: `dist/` preconstruido. De eso
se encarga el workflow nuevo.

## Cómo funciona el workflow (`.github/workflows/deploy-build-branch.yml`)

En cada push a `main`:

1. `npm ci` + `npm run build` (ubuntu-latest, Node 24 — mismas convenciones que `build.yml`).
2. El `postbuild` de `package.json:10` copia `app/`, `vendor/` y `.env` a `dist/` — **el paso
   siguiente borra `dist/.env`** (`rm -f dist/.env`) y verifica con `test ! -f dist/.env` (si
   quedara, el job FALLA antes de publicar: las credenciales nunca llegan a la rama).
3. `s0/git-publish-subdir-action@develop` publica el contenido de `dist/` en la rama `build`
   del mismo repo (`REPO: self`), con commit `Build: ({sha}) {msg}`. La rama se crea sola la
   primera vez. No usa `SQUASH_HISTORY`: el historial de la rama se conserva para el rollback.

El push a la rama `build` **no** re-dispara este workflow ni `build.yml` (ambos solo escuchan
push a `main`).

## Switch manual en hPanel (una sola vez)

1. hPanel → tu sitio → **Advanced → Git**.
2. **Connect GitHub** (OAuth) y autoriza. No se necesitan SSH keys ni deploy keys con este flujo.
3. Selecciona el repo: **dbqp01/usgar-redesign**.
4. **Branch:** selecciona **`build`** (¡no `main`!).
5. **Root directory:** `public_html` (default).
6. Click **Deploy** para el primer despliegue (los logs se ven en vivo).

> Primera vez: si `public_html` ya tiene contenido, haz backup y vacíalo antes de conectar
> (el primer deploy puede requerir el directorio vacío).

### Qué pasa después

- **Auto-deploy:** cualquier push a la rama `build` (es decir, cualquier push a `main` que
  complete el workflow) despliega automáticamente. Hostinger **copia los archivos, no compila**.
- `main` deja de ser relevante para Hostinger: la única rama conectada es `build`.
- Flujo diario: `git push origin main` → esperar el workflow verde → el sitio está actualizado.

## vendor/ — opción A vs B

El workflow incluye `vendor/` (53.7 MB / 2246 archivos) en la rama `build`:

- **Opción A (recomendada, default):** `vendor/` viaja en la rama. Primer push grande, luego
  diffs. Cero pasos manuales. El deploy siempre queda consistente con el código.
- **Opción B:** subir `vendor/` una vez manualmente a Hostinger y excluirlo de la rama (p. ej.
  borrarlo de `dist/` antes de publicar). Primer deploy más chico, pero hay que mantener
  `vendor/` de prod a mano — riesgo de drift PHP. Solo si el tamaño del primer push es un
  problema real.

## .env — nunca en la rama

- El workflow borra `dist/.env` y falla si quedara (guard `test ! -f`).
- **El `.env` de producción se crea/actualiza UNA vez manualmente en Hostinger** (File Manager
  o donde ya se maneje hoy). El deploy copia archivos nuevos pero no borra `.env` de prod
  (no está en la rama, no se sobrescribe).
- Verificación post-deploy: en hPanel File Manager comprobar que `public_html/.env` existe y
  que GitHub no muestra `.env` en la rama `build`.

## Rollback

La rama `build` conserva el historial completo (sin squash a propósito). Para volver atrás:

```bash
git fetch origin
git branch -f build origin/build~1     # apuntar a un commit anterior (o el SHA exacto)
git push --force origin build          # Hostinger auto-despliega el estado anterior
```

(En hPanel también hay historial de deploys: **View latest build output** para diagnosticar.)

## Checklist post-switch

- [ ] `public_html/.env` presente con credenciales de producción
- [ ] `https://hotelesusgar.com` responde y los `/api/*` funcionan (pagos, rooms)
- [ ] Rama `build` en GitHub sin `.env` (revisar el árbol de archivos)
- [ ] Un push de prueba a `main` deja el workflow verde y el sitio actualizado
