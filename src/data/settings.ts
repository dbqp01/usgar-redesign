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
  address: { en: string; es: string; fr: string; pt: string };
  latitude: number;
  longitude: number;
  checkinTime: string;
  checkoutTime: string;
  starRating: number;
  priceRange: string;
  customCursor: boolean;
  siteDescription: { en: string; es: string; fr: string; pt: string };
  socialLinks: SocialLink[];
  roomInventory: Record<string, number>;
}

export const DEFAULT_ROOM_INVENTORY: Record<string, number> = {
  'doble-superior': 8,
  matrimonial: 6,
  'triple-superior': 4,
  'familiar-superior': 2,
};

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
  address: {
    en: settingsData.address_en,
    es: settingsData.address_es,
    fr: settingsData.address_fr,
    pt: settingsData.address_pt
  },
  latitude: settingsData.latitude,
  longitude: settingsData.longitude,
  checkinTime: settingsData.checkinTime,
  checkoutTime: settingsData.checkoutTime,
  starRating: settingsData.starRating,
  priceRange: settingsData.priceRange,
  customCursor: settingsData.customCursor !== false,
  siteDescription: {
    en: settingsData.siteDescription_en,
    es: settingsData.siteDescription_es,
    fr: settingsData.siteDescription_fr,
    pt: settingsData.siteDescription_pt
  },
  socialLinks: settingsData.socialLinks,
  roomInventory: settingsData.roomInventory ?? DEFAULT_ROOM_INVENTORY,
};

export function getRoomInventory(): Record<string, number> {
  return settingsData.roomInventory ?? DEFAULT_ROOM_INVENTORY;
}
