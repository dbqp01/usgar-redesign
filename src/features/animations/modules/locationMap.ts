import type * as Leaflet from 'leaflet';
import 'leaflet/dist/leaflet.css';

let leafletPromise: Promise<typeof Leaflet> | null = null;

// Brutal static map: CARTO dark tiles, no interaction at all — just shows
// where the hotel is. Leaflet is loaded on demand (only when the section
// scrolls into view) to keep it out of the initial bundle.
export async function initLocationMap(
  container: HTMLElement,
  lat: number,
  lng: number,
  zoom = 15
): Promise<() => void> {
  const L = await (leafletPromise ??= import('leaflet'));

  const map = L.map(container, {
    center: [lat, lng],
    zoom,
    dragging: false,
    scrollWheelZoom: false,
    doubleClickZoom: false,
    touchZoom: false,
    zoomControl: false,
    keyboard: false,
    attributionControl: true,
  });

  L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
    subdomains: 'abcd',
  }).addTo(map);

  const icon = L.divIcon({
    className: 'usgar-marker',
    html: '<div style="width:26px;height:26px;background:var(--color-secondary);border:3px solid var(--color-white);transform:rotate(45deg);box-shadow:0 0 0 4px rgba(212,175,55,.35)"></div>',
    iconSize: [36, 36],
    iconAnchor: [18, 18],
  });

  L.marker([lat, lng], { icon }).addTo(map);

  const resizeObserver = typeof ResizeObserver !== 'undefined'
    ? new ResizeObserver(() => map.invalidateSize({ pan: false }))
    : null;
  resizeObserver?.observe(container);
  requestAnimationFrame(() => map.invalidateSize({ pan: false }));

  return () => {
    resizeObserver?.disconnect();
    map.remove();
  };
}
