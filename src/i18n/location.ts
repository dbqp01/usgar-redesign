import en from './location/en.json';
import es from './location/es.json';
import fr from './location/fr.json';
import pt from './location/pt.json';

export const locationCopy = { en, es, fr, pt };
export const locationRoutes = {
  'near-san-pedro-market': 'market',
  'near-san-pedro-station': 'station',
} as const;
export type LocationSlug = keyof typeof locationRoutes;
