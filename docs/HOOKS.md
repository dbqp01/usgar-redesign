# Hooks de Git — USGAR Hotels

Configuración: `core.hooksPath = .githooks` (definido en `.git/config`, no en `.git/hooks/`).

## Hooks activos

| Hook | Archivo | Qué hace |
|---|---|---|
| `pre-commit` | `.githooks/pre-commit` | Comprime media staged: `.mp4` (ffmpeg) e imágenes (sharp). Nunca bloquea el commit. |
| `post-commit` | `.githooks/post-commit` | Reconstruye el knowledge graph (graphify) tras cada commit. Instalado por `graphify hook install`. |
| `post-checkout` | `.githooks/post-checkout` | Reconstruye el knowledge graph al cambiar de rama (graphify). |

## pre-commit: compresión de media

### Flujo

1. **Videos `.mp4`** (staged, `--diff-filter=ACM`):
   - Si el archivo ya tiene el tag `USGAR_COMPRESSED` en metadatos → se salta (no re-comprime).
   - Si no: `ffmpeg -vcodec libx264 -crf 28 -preset medium -pix_fmt yuv420p`, escala a máx. 1280px, `minterpolate` 60fps, audio aac 128k, `-movflags +faststart`, y escribe el tag `USGAR_COMPRESSED`.
   - Si la compresión falla → se conserva el original y se avisa (no bloquea).
2. **Imágenes `.jpg/.jpeg/.png/.webp`** (staged): `node scripts/compress-images.js <archivos>` (sharp: resize a máx. 1920px, jpeg calidad 82 mozjpeg, png nivel 8, webp calidad 80).
3. Re-stagea los archivos comprimidos (`git add`).

### Requisitos

- `ffmpeg` + `ffprobe` en el PATH (en Windows: `winget install ffmpeg`).
- `node` en el PATH.
- `sharp` instalado (dependencia de `scripts/compress-images.js` — `npm install`).

### Nota Windows / por qué es PowerShell (2026-08-01)

El hook original era `#!/bin/sh` y **fallaba en Windows** con:

```
WSL (818 - Relay) ERROR: CreateProcessCommon:818: execvpe(/bin/bash) failed: No such file or directory
```

Causa raíz: el `sh` del PATH era un shim de scoop que delegaba en `C:\Windows\System32\bash.exe` (relay de WSL), y WSL no está instalado en la máquina. El hook se reescribió en **PowerShell puro** (Windows PowerShell 5.1, presente en todo Windows en `System32\WindowsPowerShell\v1.0\powershell.exe`), con el shebang `#!/usr/bin/env powershell` para que git lo ejecute sin depender de `sh`/bash/WSL.

### Probar el hook

```powershell
# Sin tocar git: lanzar el script directo (no modifica nada sin media staged)
powershell -NoProfile -ExecutionPolicy Bypass -File .githooks/pre-commit

# Con un commit real (ejecuta el hook automáticamente)
git add <archivo>
git commit -m "test: hook"

# Forzar la compresión de un video/imagen y ver el resultado en el output del commit
git add public/videos/hero.mp4
git commit -m "chore: video"
```

### Troubleshooting

- **"No se pudo determinar la raíz del repo"**: el hook se está ejecutando fuera de un repo git — revisar `core.hooksPath`.
- **ffmpeg no encontrado**: instalar ffmpeg (`winget install Gyan.FFmpeg`) y reiniciar la terminal; el hook avisará y continuará sin comprimir videos.
- **sharp no encontrado**: `npm install` en la raíz; el hook avisará y continuará sin comprimir imágenes.
- **Commit falla con error del hook**: el hook solo imprime advertencias (exit 0 siempre); si el commit falla, revisar el mensaje completo (puede ser `--no-verify` o un error previo al hook).
