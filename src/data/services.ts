import servicesData from "../content/services/services.json";

export interface Service {
  id: string;
  name: { en: string; es: string; fr: string; pt: string };
  description: { en: string; es: string; fr: string; pt: string };
  icon: string;
}

export const services: Service[] = servicesData.services.map((s: any) => ({
  id: s.id,
  name: { en: s.name_en, es: s.name_es, fr: s.name_fr || s.name_en, pt: s.name_pt || s.name_es },
  description: { en: s.description_en, es: s.description_es, fr: s.description_fr || s.description_en, pt: s.description_pt || s.description_es },
  icon: s.icon,
}));
