import { getCollection } from "astro:content";

export interface Localized {
  en: string;
  es: string;
  fr: string;
  pt: string;
}

// Etiqueta corta de un servicio (las dos listas de la web).
export interface ServiceItem {
  label: Localized;
}

export interface Service {
  id: string;
  group: "hotel" | "room";
  name: Localized;
  description?: Localized;
}

// file() loader store order is non-deterministic (docs) — sort by the parser's
// `order` index (JSON array order = business order).
const rawServices = (await getCollection("services")).sort((a, b) =>
  a.data.order - b.data.order
);

export const services: Service[] = rawServices.map(({ data: s }) => ({
  id: s.id,
  group: s.group,
  name: { en: s.name_en, es: s.name_es, fr: s.name_fr || s.name_en, pt: s.name_pt || s.name_es },
  ...(s.description_en
    ? {
        description: {
          en: s.description_en,
          es: s.description_es || s.description_en,
          fr: s.description_fr || s.description_en,
          pt: s.description_pt || s.description_es || s.description_en,
        },
      }
    : {}),
}));

// Vistas derivadas del mismo catalogo: los servicios del hotel y los de la
// habitacion. El JSON es la unica fuente; aqui solo se separa por `group`.
const asItems = (group: "hotel" | "room"): ServiceItem[] =>
  services.filter((s) => s.group === group).map((s) => ({ label: s.name }));

export const hotelServices: ServiceItem[] = asItems("hotel");
export const roomServices: ServiceItem[] = asItems("room");
