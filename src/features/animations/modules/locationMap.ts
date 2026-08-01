import type * as Leaflet from 'leaflet';

let leafletPromise: Promise<typeof Leaflet> | null = null;

// Brutal static map: CARTO dark tiles, no interaction at all — just shows
// where the hotel is. Leaflet is loaded on demand (only when the section
// scrolls into view) to keep it out of the initial bundle.
export async function initLocationMap(
  container: HTMLElement,
  lat: number,
  lng: number,
  zoom = 16
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

  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
    subdomains: 'abcd',
  }).addTo(map);

  const icon = L.divIcon({
    className: 'usgar-marker',
    html: '<div class="usgar-marker-core"></div>',
    iconSize: [36, 36],
    iconAnchor: [18, 18],
  });

  L.marker([lat, lng], { icon }).addTo(map);

  return () => {
    map.remove();
  };
}
