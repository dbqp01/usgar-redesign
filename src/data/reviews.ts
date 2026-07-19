import reviewsData from "../content/reviews/reviews.json";

export interface Review {
  id: number;
  name: string;
  country: string;
  rating: number;
  text: { en: string; es: string };
  date: { en: string; es: string };
}

export const reviews: Review[] = reviewsData.reviews.map((r, index) => ({
  id: index + 1,
  name: r.name,
  country: r.country,
  rating: r.rating,
  text: { en: r.text_en, es: r.text_es },
  date: { en: r.date_en, es: r.date_es },
}));
