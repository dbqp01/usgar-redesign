import roomsData from "../content/rooms/rooms.json";

export interface AmenityLabel {
  en: string;
  es: string;
  fr: string;
  pt: string;
}

export interface Room {
  id: string;
  slug: string;
  name: { en: string; es: string; fr: string; pt: string };
  description: { en: string; es: string; fr: string; pt: string };
  maxGuests: number;
  beds: string;
  pricePerNight: number;
  amenities: string[];
  images: string[];
  photoFolder: string;
  hasVideoTour: boolean;
  amenityLabels: Record<string, AmenityLabel>;
}

export const rooms: Room[] = roomsData.rooms.map((r: any) => ({
  id: r.id,
  slug: r.slug,
  name: { en: r.name_en, es: r.name_es, fr: r.name_fr || r.name_en, pt: r.name_pt || r.name_es },
  description: { en: r.description_en, es: r.description_es, fr: r.description_fr || r.description_en, pt: r.description_pt || r.description_es },
  maxGuests: r.maxGuests,
  beds: r.beds,
  pricePerNight: r.pricePerNight,
  amenities: r.amenities,
  images: r.images ?? [],
  photoFolder: r.photoFolder,
  hasVideoTour: r.hasVideoTour,
  amenityLabels: Object.fromEntries(
    Object.entries((r.amenityLabels as Record<string, any>) ?? {}).map(([key, val]) => [
      key,
      {
        en: val.en || '',
        es: val.es || '',
        fr: val.fr || val.en || val.es || '',
        pt: val.pt || val.es || val.en || ''
      }
    ])
  ),
}));

export function getRoomBySlug(slug: string): Room | undefined {
  return rooms.find((r) => r.slug === slug);
}
