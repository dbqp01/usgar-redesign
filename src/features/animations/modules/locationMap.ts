import type * as Leaflet from 'leaflet';
import 'leaflet/dist/leaflet.css';

let leafletPromise: Promise<typeof Leaflet> | null = null;

export interface MapPOI {
  id: string;
  name: string;
  lat: number;
  lng: number;
  timeText: string;
}

export async function initLocationMap(
  container: HTMLElement,
  lat: number,
  lng: number,
  pois: MapPOI[] = [],
  zoom = 15
): Promise<() => void> {
  const L = await (leafletPromise ??= import('leaflet'));

  const map = L.map(container, {
    center: [lat, lng],
    zoom,
    dragging: true,
    scrollWheelZoom: false,
    doubleClickZoom: true,
    touchZoom: true,
    zoomControl: false,
    keyboard: false,
    attributionControl: false,
  });

  // Tile layer: CARTO Dark Matter (obsidian luxury theme).
  // Fallback to OSM Standard if the CARTO CDN is blocked or down (DNS blockers, outages).
  const cartoTiles = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    maxZoom: 19,
    subdomains: 'abcd',
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
  });
  cartoTiles.addTo(map);

  // ponytail: single provider switch after 3 errors; add retry/second fallback if a real outage shows up
  let tileErrors = 0;
  cartoTiles.on('tileerror', () => {
    if (++tileErrors < 3) return; // ignore isolated tile hiccups
    cartoTiles.off('tileerror');
    cartoTiles.remove();
    // No {r}: OSM Standard serves no @2x tiles (Leaflet docs) — would 404 on retina.
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);
    L.control.attribution({ prefix: false }).addTo(map); // OSM policy: attribution must stay visible
  });

  // Custom Zoom Control at bottom right
  const zoomControl = L.control.zoom({ position: 'bottomright' });
  zoomControl.addTo(map);

  // Hotel Pin Blanco with Gold Radar Pulse (pin blanco + anillo blanco; el
  // pulso radar y los POIs usan el dorado secondary) (audit 2026-08-12)
  const mainIcon = L.divIcon({
    className: 'usgar-hotel-marker',
    html: `
      <div class="relative flex items-center justify-center cursor-pointer group">
        <span class="absolute h-7 w-7 rounded-full border-2 border-white/90 shadow-[0_0_18px_rgba(255,255,255,0.35)]"></span>
        <span class="absolute h-2.5 w-2.5 rounded-full bg-white shadow-[0_0_12px_rgba(255,255,255,0.9)]"></span>
        <span class="absolute inline-flex h-10 w-10 animate-ping rounded-full bg-secondary/30 opacity-60"></span>
      </div>
    `,
    iconSize: [44, 44],
    iconAnchor: [22, 22],
    popupAnchor: [0, -26],
  });

  const hotelMarker = L.marker([lat, lng], { icon: mainIcon }).addTo(map);

  const popupHtml = `
    <div class="p-2 text-stone-100 font-sans max-w-[200px]">
      <div class="text-secondary font-mono text-[10px] tracking-widest uppercase font-semibold">Boutique Hotel</div>
      <h4 class="font-serif text-base font-bold text-white mt-0.5">USGAR Hotels</h4>
      <p class="text-xs text-stone-300 mt-1 leading-tight">759 Calle Hospital, San Pedro, Cusco</p>
    </div>
  `;
  hotelMarker.bindPopup(popupHtml, {
    className: 'usgar-leaflet-popup',
    closeButton: false,
  });

  // Secondary POI Markers
  const poiMarkers = new Map<string, Leaflet.Marker>();

  pois.forEach((poi) => {
    const poiIcon = L.divIcon({
      className: 'usgar-poi-marker',
      html: `
        <div class="group relative flex items-center justify-center transition-transform hover:scale-125 cursor-pointer" data-poi-id="${poi.id}">
          <div class="h-3.5 w-3.5 rounded-full border-2 border-secondary bg-secondary shadow-[0_0_12px_rgba(212,175,55,0.6)] backdrop-blur-sm"></div>
        </div>
      `,
      iconSize: [16, 16],
      iconAnchor: [8, 8],
    });

    const marker = L.marker([poi.lat, poi.lng], { icon: poiIcon })
      .addTo(map)
      .bindTooltip(`<span class="font-sans text-xs font-semibold px-2 py-1 text-white">${poi.name} · ${poi.timeText}</span>`, {
        direction: 'top',
        offset: [0, -8],
        className: 'usgar-leaflet-tooltip',
      });

    poiMarkers.set(poi.id, marker);
  });

  // Attach hover handlers for POI list elements if present
  const poiListElements = document.querySelectorAll('[data-map-poi]');
  poiListElements.forEach((el) => {
    const poiId = el.getAttribute('data-map-poi');
    if (!poiId) return;
    const targetMarker = poiMarkers.get(poiId);
    if (!targetMarker) return;

    el.addEventListener('mouseenter', () => {
      targetMarker.openTooltip();
    });
    el.addEventListener('mouseleave', () => {
      targetMarker.closeTooltip();
    });
    el.addEventListener('click', () => {
      map.panTo(targetMarker.getLatLng(), { animate: true, duration: 0.8 });
      targetMarker.openTooltip();
    });
  });

  const resizeObserver = typeof ResizeObserver !== 'undefined'
    ? new ResizeObserver(() => map.invalidateSize({ pan: false }))
    : null;
  resizeObserver?.observe(container);
  requestAnimationFrame(() => map.invalidateSize({ pan: false }));

  return () => {
    poiListElements.forEach((el) => {
      const clone = el.cloneNode(true);
      el.parentNode?.replaceChild(clone, el);
    });
    resizeObserver?.disconnect();
    map.remove();
  };
}

