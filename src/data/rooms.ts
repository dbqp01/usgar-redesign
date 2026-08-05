import { getCollection } from "astro:content";
import { fallbackChain, type Locale } from "../i18n/utils";

// Resolve a `<field>_<locale>` column through the shared key-level fallback
// chain (fr -> en -> es, pt -> es -> en; single source in src/i18n/utils.ts).
function pickRow(row: Record<string, any>, field: string, locale: Locale): string {
  return row[`${field}_${locale}`] || fallbackChain[locale].map((l) => row[`${field}_${l}`]).find(Boolean) || '';
}

// Same, for inline per-locale objects (amenity labels).
function pickLabels(labels: Record<string, string>, locale: Locale): string {
  return labels[locale] || fallbackChain[locale].map((l) => labels[l]).find(Boolean) || '';
}

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
  baseOccupancy: number;
  extraGuestCharge: number;
  beds: string;
  pricePerNight: number;
  amenities: string[];
  images: string[];
  photoFolder: string;
  hasVideoTour: boolean;
  amenityLabels: Record<string, AmenityLabel>;
}

// file() loader store order is non-deterministic (docs) — sort by the parser's
// `order` index (JSON array order = business order).
const rawRooms = (await getCollection("rooms")).sort((a, b) =>
  a.data.order - b.data.order
);

export const rooms: Room[] = rawRooms.map(({ data: r }) => ({
  id: r.id,
  slug: r.slug,
  name: { en: r.name_en, es: r.name_es, fr: pickRow(r, 'name', 'fr'), pt: pickRow(r, 'name', 'pt') },
  description: { en: r.description_en, es: r.description_es, fr: pickRow(r, 'description', 'fr'), pt: pickRow(r, 'description', 'pt') },
  maxGuests: r.maxGuests,
  baseOccupancy: r.baseOccupancy,
  extraGuestCharge: r.extraGuestCharge,
  beds: r.beds,
  pricePerNight: r.pricePerNight,
  amenities: r.amenities,
  images: r.images ?? [],
  photoFolder: r.photoFolder,
  hasVideoTour: r.hasVideoTour,
  amenityLabels: Object.fromEntries(
    Object.entries(r.amenityLabels).map(([key, val]) => [
      key,
      {
        en: val.en || '',
        es: val.es || '',
        fr: pickLabels(val, 'fr'),
        pt: pickLabels(val, 'pt')
      }
    ])
  ),
}));

export function getRoomBySlug(slug: string): Room | undefined {
  return rooms.find((r) => r.slug === slug);
}
