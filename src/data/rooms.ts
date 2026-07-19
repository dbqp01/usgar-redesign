import roomsData from "../content/rooms/rooms.json";

export interface AmenityLabel {
  en: string;
  es: string;
}

export interface Room {
  id: string;
  slug: string;
  name: { en: string; es: string };
  description: { en: string; es: string };
  maxGuests: number;
  beds: string;
  pricePerNight: number;
  amenities: string[];
  images: string[];
  photoFolder: string;
  hasVideoTour: boolean;
  amenityLabels: Record<string, AmenityLabel>;
}

export const rooms: Room[] = roomsData.rooms.map((r) => ({
  id: r.id,
  slug: r.slug,
  name: { en: r.name_en, es: r.name_es },
  description: { en: r.description_en, es: r.description_es },
  maxGuests: r.maxGuests,
  beds: r.beds,
  pricePerNight: r.pricePerNight,
  amenities: r.amenities,
  images: r.images ?? [],
  photoFolder: r.photoFolder,
  hasVideoTour: r.hasVideoTour,
  amenityLabels: (r.amenityLabels as Record<string, AmenityLabel>) ?? {},
}));

export function getRoomBySlug(slug: string): Room | undefined {
  return rooms.find((r) => r.slug === slug);
}
