import { defineCollection } from 'astro:content';
import { file } from 'astro/loaders';
import { z } from 'astro/zod';

// Flat (JSON) shape: keys are `name_en`, `name_es`, ... — re-nesting into
// {en, es, fr, pt} happens in src/data/*.ts wrappers (keeps fallback logic there).
const settings = defineCollection({
  loader: file('src/content/settings/settings.json', {
    // Single object file -> wrap as one entry with a synthetic id.
    parser: (text) => [{ ...JSON.parse(text), id: 'site' }],
  }),
  schema: z.object({
    id: z.string(),
    hotelName: z.string(),
    phone: z.string(),
    phoneRaw: z.string(),
    email: z.string(),
    whatsappNumber: z.string(),
    streetAddress: z.string(),
    city: z.string(),
    region: z.string(),
    postalCode: z.string(),
    country: z.string(),
    address_en: z.string(),
    address_es: z.string(),
    address_fr: z.string(),
    address_pt: z.string(),
    latitude: z.number(),
    longitude: z.number(),
    checkinTime: z.string(),
    checkoutTime: z.string(),
    starRating: z.number(),
    priceRange: z.string(),
    // Optional: defaults are applied in src/data/settings.ts (customCursor !== false, roomInventory ?? DEFAULT_ROOM_INVENTORY).
    customCursor: z.boolean().optional(),
    roomInventory: z.record(z.string(), z.number()).optional(),
    siteDescription_en: z.string(),
    siteDescription_es: z.string(),
    siteDescription_fr: z.string(),
    siteDescription_pt: z.string(),
    socialLinks: z.array(
      z.object({ platform: z.string(), url: z.string(), label: z.string() })
    ),
  }),
});

const rooms = defineCollection({
  loader: file('src/content/rooms/rooms.json', {
    // Nested document: entries live under the `rooms` key. JSON array order is
    // the business order (docs: generated store order is non-deterministic) —
    // carry it via `order`; stripped in src/data/rooms.ts.
    parser: (text) =>
      JSON.parse(text).rooms.map((r: unknown, i: number) => ({ ...(r as object), order: i })),
  }),
  schema: z.object({
    id: z.string(),
    order: z.number(),
    slug: z.string(),
    name_en: z.string(),
    name_es: z.string(),
    name_fr: z.string(),
    name_pt: z.string(),
    description_en: z.string(),
    description_es: z.string(),
    description_fr: z.string(),
    description_pt: z.string(),
    maxGuests: z.number(),
    baseOccupancy: z.number(),
    extraGuestCharge: z.number(),
    beds: z.string(),
    pricePerNight: z.number(),
    amenities: z.array(z.string()),
    images: z.array(z.string()),
    photoFolder: z.string(),
    hasVideoTour: z.boolean(),
    amenityLabels: z.record(
      z.string(),
      z.object({
        en: z.string(),
        es: z.string(),
        fr: z.string(),
        pt: z.string(),
      })
    ),
  }),
});

const services = defineCollection({
  loader: file('src/content/services/services.json', {
    // JSON array order = business order (docs: generated store order is
    // non-deterministic) — carry it via `order`; stripped in src/data/services.ts.
    parser: (text) =>
      JSON.parse(text).services.map((s: unknown, i: number) => ({ ...(s as object), order: i })),
  }),
  schema: z.object({
    id: z.string(),
    order: z.number(),
    name_en: z.string(),
    name_es: z.string(),
    name_fr: z.string(),
    name_pt: z.string(),
    description_en: z.string(),
    description_es: z.string(),
    description_fr: z.string(),
    description_pt: z.string(),
    icon: z.string(),
  }),
});

const reviews = defineCollection({
  loader: file('src/content/reviews/reviews.json', {
    // No id in the source file -> synthesize from array position; JSON array
    // order = business order (docs: generated store order is non-deterministic) —
    // carry it via `order`; stripped in src/data/reviews.ts.
    parser: (text) =>
      JSON.parse(text).reviews.map((r: unknown, i: number) => ({ ...(r as object), id: String(i + 1), order: i })),
  }),
  schema: z.object({
    id: z.string(),
    order: z.number(),
    name: z.string(),
    country: z.string(),
    rating: z.number(),
    text_en: z.string(),
    text_es: z.string(),
    text_fr: z.string(),
    text_pt: z.string(),
    date_en: z.string(),
    date_es: z.string(),
    date_fr: z.string(),
    date_pt: z.string(),
  }),
});

const explore = defineCollection({
  loader: file('src/content/explore/explore.json', {
    // JSON array order = business order (docs: generated store order is
    // non-deterministic) — carry it via `order`; stripped in src/data/attractions.ts.
    parser: (text) =>
      JSON.parse(text).attractions.map((a: unknown, i: number) => ({ ...(a as object), order: i })),
  }),
  schema: z.object({
    id: z.string(),
    order: z.number(),
    name_en: z.string(),
    name_es: z.string(),
    name_fr: z.string(),
    name_pt: z.string(),
    description_en: z.string(),
    description_es: z.string(),
    description_fr: z.string(),
    description_pt: z.string(),
    distance: z.string(),
    travelTime: z.string(),
    category: z.enum(['historical', 'nature', 'cultural', 'adventure']),
    history_en: z.string(),
    history_es: z.string(),
    history_fr: z.string(),
    history_pt: z.string(),
    howToGet_en: z.string(),
    howToGet_es: z.string(),
    howToGet_fr: z.string(),
    howToGet_pt: z.string(),
    tips_en: z.array(z.string()),
    tips_es: z.array(z.string()),
    tips_fr: z.array(z.string()),
    tips_pt: z.array(z.string()),
    bestTime_en: z.string(),
    bestTime_es: z.string(),
    bestTime_fr: z.string(),
    bestTime_pt: z.string(),
    // SEO: guia extendida por atraccion (overview + FAQ)
    overview_en: z.string(),
    overview_es: z.string(),
    overview_fr: z.string(),
    overview_pt: z.string(),
    faq_en: z.array(z.object({ q: z.string(), a: z.string() })),
    faq_es: z.array(z.object({ q: z.string(), a: z.string() })),
    faq_fr: z.array(z.object({ q: z.string(), a: z.string() })),
    faq_pt: z.array(z.object({ q: z.string(), a: z.string() })),
  }),
});

const faq = defineCollection({
  loader: file('src/content/faq/faq.json', {
    // No id in the source file -> synthesize from array position; JSON array
    // order = business order (docs: generated store order is non-deterministic) —
    // carry it via `order`; stripped in src/data/faq.ts.
    parser: (text) =>
      JSON.parse(text).questions.map((q: unknown, i: number) => ({ ...(q as object), id: String(i + 1), order: i })),
  }),
  schema: z.object({
    id: z.string(),
    order: z.number(),
    question_en: z.string(),
    question_es: z.string(),
    question_fr: z.string(),
    question_pt: z.string(),
    answer_en: z.string(),
    answer_es: z.string(),
    answer_fr: z.string(),
    answer_pt: z.string(),
  }),
});

const about = defineCollection({
  loader: file('src/content/about/about.json', {
    // Single object file -> wrap as one entry with a synthetic id. The `values`
    // array keeps JSON order via `order` (docs: generated store order is
    // non-deterministic); stripped in src/data/about.ts.
    parser: (text) => {
      const data = JSON.parse(text);
      return [{
        ...data,
        id: 'about',
        values: data.values.map((v: unknown, i: number) => ({ ...(v as object), order: i })),
      }];
    },
  }),
  schema: z.object({
    id: z.string(),
    title_en: z.string(),
    title_es: z.string(),
    title_fr: z.string(),
    title_pt: z.string(),
    purpose_en: z.string(),
    purpose_es: z.string(),
    purpose_fr: z.string(),
    purpose_pt: z.string(),
    values: z.array(
      z.object({
        order: z.number(),
        title_en: z.string(),
        title_es: z.string(),
        title_fr: z.string(),
        title_pt: z.string(),
        description_en: z.string(),
        description_es: z.string(),
        description_fr: z.string(),
        description_pt: z.string(),
      })
    ),
  }),
});

export const collections = { settings, rooms, services, reviews, explore, faq, about };
