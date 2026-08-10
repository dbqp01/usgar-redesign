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

  // Tile layer: CARTO Dark Matter (obsidian luxury theme)
  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    maxZoom: 19,
    subdomains: 'abcd',
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
  }).addTo(map);

  // Custom Zoom Control at bottom right
  const zoomControl = L.control.zoom({ position: 'bottomright' });
  zoomControl.addTo(map);

  // Gold Luxury Hotel Pin with Radar Pulse
  const mainIcon = L.divIcon({
    className: 'usgar-hotel-marker',
    html: `
      <div class="relative flex items-center justify-center cursor-pointer group">
        <span class="absolute inline-flex h-12 w-12 animate-ping rounded-full bg-amber-400/35 opacity-75"></span>
        <span class="absolute inline-flex h-16 w-16 rounded-full bg-amber-500/10 blur-sm"></span>
        <div class="relative flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-amber-300 via-amber-500 to-amber-700 p-[2px] shadow-[0_0_25px_rgba(245,158,11,0.7)] transition-transform duration-300 group-hover:scale-110">
          <div class="flex h-full w-full items-center justify-center rounded-full bg-stone-950 font-serif font-bold text-amber-300 text-sm shadow-inner">
            U
          </div>
        </div>
      </div>
    `,
    iconSize: [44, 44],
    iconAnchor: [22, 22],
    popupAnchor: [0, -24],
  });

  const hotelMarker = L.marker([lat, lng], { icon: mainIcon }).addTo(map);

  const popupHtml = `
    <div class="p-2 text-stone-100 font-sans max-w-[200px]">
      <div class="text-amber-400 font-mono text-[10px] tracking-widest uppercase font-semibold">Boutique Hotel</div>
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
          <div class="h-3.5 w-3.5 rounded-full border-2 border-amber-400 bg-amber-500/90 shadow-[0_0_12px_rgba(245,158,11,0.5)] backdrop-blur-sm"></div>
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

