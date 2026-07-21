import exploreData from "../content/explore/explore.json";

export interface LocalizedField<T = string> {
  en: T;
  es: T;
  fr: T;
  pt: T;
}

export interface Attraction {
  id: string;
  name: LocalizedField<string>;
  description: LocalizedField<string>;
  distance: string;
  travelTime: string;
  category: 'historical' | 'nature' | 'cultural' | 'adventure';
  history: LocalizedField<string>;
  howToGet: LocalizedField<string>;
  tips: LocalizedField<string[]>;
  bestTime: LocalizedField<string>;
}

export const attractions: Attraction[] = exploreData.attractions.map((attr: any) => ({
  id: attr.id,
  name: {
    en: attr.name_en,
    es: attr.name_es,
    fr: attr.name_fr || attr.name_en,
    pt: attr.name_pt || attr.name_es
  },
  description: {
    en: attr.description_en,
    es: attr.description_es,
    fr: attr.description_fr || attr.description_en,
    pt: attr.description_pt || attr.description_es
  },
  distance: attr.distance,
  travelTime: attr.travelTime,
  category: attr.category as any,
  history: {
    en: attr.history_en,
    es: attr.history_es,
    fr: attr.history_fr || attr.history_en,
    pt: attr.history_pt || attr.history_es
  },
  howToGet: {
    en: attr.howToGet_en,
    es: attr.howToGet_es,
    fr: attr.howToGet_fr || attr.howToGet_en,
    pt: attr.howToGet_pt || attr.howToGet_es
  },
  tips: {
    en: attr.tips_en || [],
    es: attr.tips_es || [],
    fr: attr.tips_fr || attr.tips_en || [],
    pt: attr.tips_pt || attr.tips_es || []
  },
  bestTime: {
    en: attr.bestTime_en,
    es: attr.bestTime_es,
    fr: attr.bestTime_fr || attr.bestTime_en,
    pt: attr.bestTime_pt || attr.bestTime_es
  },
}));
