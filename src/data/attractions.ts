import exploreData from "../content/explore/explore.json";

export interface Attraction {
  id: string;
  name: { en: string; es: string };
  description: { en: string; es: string };
  distance: string;
  travelTime: string;
  category: 'historical' | 'nature' | 'cultural' | 'adventure';
  history: { en: string; es: string };
  howToGet: { en: string; es: string };
  tips: { en: string[]; es: string[] };
  bestTime: { en: string; es: string };
}

export const attractions: Attraction[] = exploreData.attractions.map((attr) => ({
  id: attr.id,
  name: { en: attr.name_en, es: attr.name_es },
  description: { en: attr.description_en, es: attr.description_es },
  distance: attr.distance,
  travelTime: attr.travelTime,
  category: attr.category as any,
  history: { en: attr.history_en, es: attr.history_es },
  howToGet: { en: attr.howToGet_en, es: attr.howToGet_es },
  tips: { en: attr.tips_en, es: attr.tips_es },
  bestTime: { en: attr.bestTime_en, es: attr.bestTime_es },
}));
