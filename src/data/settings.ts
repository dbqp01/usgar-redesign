import settingsData from "../content/settings/settings.json";

export interface SocialLink {
  platform: string;
  url: string;
  label: string;
}

export interface SiteSettings {
  hotelName: string;
  phone: string;
  phoneRaw: string;
  email: string;
  whatsappNumber: string;
  streetAddress: string;
  city: string;
  region: string;
  postalCode: string;
  country: string;
  address: { en: string; es: string };
  latitude: number;
  longitude: number;
  checkinTime: string;
  checkoutTime: string;
  starRating: number;
  priceRange: string;
  siteDescription: { en: string; es: string };
  socialLinks: SocialLink[];
}

export const siteSettings: SiteSettings = {
  hotelName: settingsData.hotelName,
  phone: settingsData.phone,
  phoneRaw: settingsData.phoneRaw,
  email: settingsData.email,
  whatsappNumber: settingsData.whatsappNumber,
  streetAddress: settingsData.streetAddress,
  city: settingsData.city,
  region: settingsData.region,
  postalCode: settingsData.postalCode,
  country: settingsData.country,
  address: { en: settingsData.address_en, es: settingsData.address_es },
  latitude: settingsData.latitude,
  longitude: settingsData.longitude,
  checkinTime: settingsData.checkinTime,
  checkoutTime: settingsData.checkoutTime,
  starRating: settingsData.starRating,
  priceRange: settingsData.priceRange,
  siteDescription: { en: settingsData.siteDescription_en, es: settingsData.siteDescription_es },
  socialLinks: settingsData.socialLinks,
};
