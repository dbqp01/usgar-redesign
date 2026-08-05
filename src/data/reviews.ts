import { getCollection } from "astro:content";

export interface Review {
  id: number;
  name: string;
  country: string;
  rating: number;
  text: { en: string; es: string; fr: string; pt: string };
  date: { en: string; es: string; fr: string; pt: string };
}

// file() loader store order is non-deterministic (docs) — sort by the parser's
// `order` index (JSON array order = business order).
const rawReviews = (await getCollection("reviews")).sort((a, b) =>
  a.data.order - b.data.order
);

export const reviews: Review[] = rawReviews.map(({ data: r }, index) => ({
  id: index + 1,
  name: r.name,
  country: r.country,
  rating: r.rating,
  text: {
    en: r.text_en,
    es: r.text_es,
    fr: r.text_fr || r.text_en,
    pt: r.text_pt || r.text_es
  },
  date: {
    en: r.date_en,
    es: r.date_es,
    fr: r.date_fr || r.date_en,
    pt: r.date_pt || r.date_es
  },
}));
