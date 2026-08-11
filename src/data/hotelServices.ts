// src/data/hotelServices.ts
// Listas OFICIALES de servicios del hotel (fuente: mensaje del cliente 10/08/2026).
// Son globales del hotel (identicas para todas las habitaciones): se muestran
// completas en la pagina de detalle de habitacion. El wizard solo muestra las
// 8 amenities relevantes (room.amenities / room.amenityLabels en rooms.json).
export interface ServiceItem {
  label: { en: string; es: string; fr: string; pt: string };
}

export const hotelServices: ServiceItem[] = [
  { label: { en: "Buffet breakfast (6:00 am - 9:00 am)", es: "Desayuno buffet (6:00 am - 9:00 am)", fr: "Petit-dejeuner buffet (6h00 - 9h00)", pt: "Cafe da manha buffet (6h00 - 9h00)" } },
  { label: { en: "24-hour front desk assistance", es: "Atencion en recepcion las 24 horas", fr: "Reception disponible 24h/24", pt: "Atendimento na recepcao 24 horas" } },
  { label: { en: "Check-in from 12:00 hrs", es: "Check in 12:00 hrs", fr: "Check-in des 12h00", pt: "Check-in a partir das 12h00" } },
  { label: { en: "Check-out until 10:30 hrs", es: "Check out 10:30 hrs", fr: "Check-out jusqu'a 10h30", pt: "Check-out ate as 10h30" } },
  { label: { en: "Free Wi-Fi connection", es: "Conexion Wifi gratuita", fr: "Wi-Fi gratuit", pt: "Wi-Fi gratuito" } },
  { label: { en: "Cafeteria open until 10:00 pm", es: "Cafeteria abierta hasta las 22 hrs", fr: "Cafeteria ouverte jusqu'a 22h", pt: "Cafetaria aberta ate as 22h" } },
  { label: { en: "Complimentary oxygen service", es: "Servicio de oxigeno de cortesia", fr: "Service d'oxygene gracieux", pt: "Servico de oxigenio de cortesia" } },
  { label: { en: "Complimentary hot drinks station", es: "Estacion de bebidas calientes de cortesia", fr: "Station de boissons chaudes gratuite", pt: "Estacao de bebidas quentes de cortesia" } },
  { label: { en: "Laundry service (additional cost)", es: "Servicio de lavanderia (costo adicional)", fr: "Service de blanchisserie (supplement)", pt: "Servico de lavanderia (custo adicional)" } },
  { label: { en: "Airport transfer (additional cost)", es: "Servicio de traslado (costo adicional)", fr: "Service de transfert (supplement)", pt: "Servico de traslado (custo adicional)" } },
  { label: { en: "Souvenir shop", es: "Tienda de souvenirs", fr: "Boutique de souvenirs", pt: "Loja de souvenirs" } },
  { label: { en: "Non-smoking rooms", es: "Habitacion para no fumadores", fr: "Chambres non-fumeurs", pt: "Quarto para nao fumantes" } },
  { label: { en: "Free luggage storage", es: "Custodia de maletas sin costo", fr: "Consigne a bagages gratuite", pt: "Guarda-volumes gratuito" } },
  { label: { en: "24-hour reception", es: "Recepcion las 24h", fr: "Reception 24h/24", pt: "Recepcao 24 horas" } },
  { label: { en: "Bilingual staff", es: "Personal bilingue", fr: "Personnel bilingue", pt: "Equipe bilingue" } },
  { label: { en: "Tourist information", es: "Informacion turistica", fr: "Informations touristiques", pt: "Informacoes turisticas" } },
  { label: { en: "Tours", es: "Tours", fr: "Excursions", pt: "Passeios" } },
  { label: { en: "Daily room cleaning", es: "Servicio de limpieza de las habitaciones", fr: "Menage quotidien des chambres", pt: "Limpeza diaria dos quartos" } },
  { label: { en: "Currency exchange", es: "Cambio de moneda", fr: "Bureau de change", pt: "Cambio de moedas" } },
];

export const roomServices: ServiceItem[] = [
  { label: { en: "Private bathroom with shower", es: "Bano privado con ducha", fr: "Salle de bain privee avec douche", pt: "Banheiro privativo com chuveiro" } },
  { label: { en: "Bathroom amenities", es: "Amenities para el bano", fr: "Accessoires de salle de bain", pt: "Amenidades de banheiro" } },
  { label: { en: "Hot water 24 hours", es: "Agua caliente las 24 horas", fr: "Eau chaude 24h/24", pt: "Agua quente 24 horas" } },
  { label: { en: "Hair dryer", es: "Secadora de cabello", fr: "Seche-cheveux", pt: "Secador de cabelo" } },
  { label: { en: "Towels", es: "Toallas", fr: "Serviettes", pt: "Toalhas" } },
  { label: { en: "Complimentary tea & infusion kit", es: "Kit de infusiones cortesia en la habitacion", fr: "Kit d'infusions offert en chambre", pt: "Kit de infusoes de cortesia no quarto" } },
  { label: { en: "Wardrobe", es: "Armario", fr: "Armoire", pt: "Guarda-roupa" } },
  { label: { en: "Desk with chair", es: "Escritorio con silla", fr: "Bureau avec chaise", pt: "Escrivaninha com cadeira" } },
  { label: { en: "Cable TV", es: "TV con cable", fr: "TV par cable", pt: "TV a cabo" } },
  { label: { en: "Telephone", es: "Telefono", fr: "Telephone", pt: "Telefone" } },
  { label: { en: "In-room safe", es: "Caja de seguridad", fr: "Coffre-fort", pt: "Cofre de seguranca" } },
  { label: { en: "Heater", es: "Calefactor", fr: "Chauffage", pt: "Aquecedor" } },
  { label: { en: "Filtered water", es: "Agua filtrada", fr: "Eau filtree", pt: "Agua filtrada" } },
];
