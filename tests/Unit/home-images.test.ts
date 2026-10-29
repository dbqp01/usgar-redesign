import { describe, expect, it } from 'vitest';
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

const root = process.cwd();
const components = ['Hero', 'HeritageSection', 'AboutSection', 'GastronomySection', 'ServiceGrid', 'InstagramSection', 'FAQSection'];

describe('home image ownership', () => {
  it('gives each editorial image its own home-only file', () => {
    const paths: string[] = [];
    for (const component of components) {
      const source = readFileSync(resolve(root, `src/components/${component}.astro`), 'utf8');
      const images = [...source.matchAll(/from\s+['"](\.\.\/assets\/[^'"]+\.jpg)['"]/g)].map((match) => match[1]);
      expect(images.length, component).toBeGreaterThan(0);
      for (const image of images) {
        expect(image, component).toMatch(/^\.\.\/assets\/home\//);
        expect(existsSync(resolve(root, 'src/components', image)), image).toBe(true);
        paths.push(image);
      }
    }
    expect(new Set(paths).size).toBe(paths.length);
  });

  it('uses dedicated room and destination previews for all home cards', () => {
    const rooms = JSON.parse(readFileSync(resolve(root, 'src/content/rooms/rooms.json'), 'utf8')).rooms;
    const destinations = JSON.parse(readFileSync(resolve(root, 'src/content/explore/explore.json'), 'utf8'));
    const roomsSource = readFileSync(resolve(root, 'src/components/RoomsEditorialGrid.astro'), 'utf8');
    const exploreSource = readFileSync(resolve(root, 'src/components/ExploreCarousel.astro'), 'utf8');
    expect(roomsSource).toContain('../assets/home/rooms/*.jpg');
    expect(exploreSource).toContain('../assets/home/explore/*.jpg');
    for (const room of rooms.slice(0, 4)) {
      expect(existsSync(resolve(root, `src/assets/home/rooms/${room.photoFolder}.jpg`)), room.photoFolder).toBe(true);
    }
    const entries = Array.isArray(destinations) ? destinations : Object.values(destinations)[0] as { id: string }[];
    for (const destination of entries) {
      expect(existsSync(resolve(root, `src/assets/home/explore/${destination.id}.jpg`)), destination.id).toBe(true);
    }
    expect(readdirSync(resolve(root, 'src/assets/home/rooms'))).toHaveLength(4);
  });
});
