import servicesData from "../content/services/services.json";

export interface Service {
  id: string;
  name: { en: string; es: string };
  description: { en: string; es: string };
  icon: string;
}

export const services: Service[] = servicesData.services.map((s) => ({
  id: s.id,
  name: { en: s.name_en, es: s.name_es },
  description: { en: s.description_en, es: s.description_es },
  icon: s.icon,
}));
