import { getCollection } from "astro:content";

export interface Service {
  id: string;
  name: { en: string; es: string; fr: string; pt: string };
  description: { en: string; es: string; fr: string; pt: string };
  icon: string;
}

// file() loader store order is non-deterministic (docs) — sort by the parser's
// `order` index (JSON array order = business order).
const rawServices = (await getCollection("services")).sort((a, b) =>
  a.data.order - b.data.order
);

export const services: Service[] = rawServices.map(({ data: s }) => ({
  id: s.id,
  name: { en: s.name_en, es: s.name_es, fr: s.name_fr || s.name_en, pt: s.name_pt || s.name_es },
  description: { en: s.description_en, es: s.description_es, fr: s.description_fr || s.description_en, pt: s.description_pt || s.description_es },
  icon: s.icon,
}));
