// src/utils/ogImage.ts
import { getImage } from 'astro:assets';
import type { ImageMetadata } from 'astro';

/**
 * Imagen social (og:image) de una pagina: recorte 1200x630 de una foto del
 * sitio, en JPEG.
 *
 * - 1200x630 es el tamano que esperan WhatsApp, Facebook y X.
 * - JPEG y no AVIF/WebP: los scrapers de redes sociales no leen los formats
 *   que emite `astro:assets` por defecto, y sin una imagen legible el link se
 *   comparte sin miniatura.
 */
export async function socialImage(src: ImageMetadata): Promise<string> {
  const { src: url } = await getImage({
    src,
    width: 1200,
    height: 630,
    format: 'jpeg',
    quality: 75,
  });
  return new URL(url, import.meta.env.SITE).href;
}
