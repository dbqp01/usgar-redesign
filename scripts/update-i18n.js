import fs from 'fs';
import path from 'path';

const i18nDir = path.join(process.cwd(), 'src/i18n');

const newKeys = {
  en: {
    "global": {
      "skipToContent": "Skip to main content",
      "directions": "Directions",
      "contactWhatsApp": "Contact via WhatsApp",
      "assistance247": "24/7 Assistance",
      "elevatedExperience": "ELEVATED EXPERIENCE",
      "alsoIncluded": "Also included:",
      "night": "night",
      "includedFree": "Included at no extra cost"
    }
  },
  es: {
    "global": {
      "skipToContent": "Ir al contenido principal",
      "directions": "Cómo llegar",
      "contactWhatsApp": "Contactar por WhatsApp",
      "assistance247": "Atención 24/7",
      "elevatedExperience": "EXPERIENCIA COMPLETA",
      "alsoIncluded": "También incluimos:",
      "night": "noche",
      "includedFree": "Incluido sin costo extra"
    }
  },
  fr: {
    "global": {
      "skipToContent": "Aller au contenu principal",
      "directions": "Itinéraire",
      "contactWhatsApp": "Contacter par WhatsApp",
      "assistance247": "Assistance 24/7",
      "elevatedExperience": "EXPÉRIENCE COMPLÈTE",
      "alsoIncluded": "Aussi inclus :",
      "night": "nuit",
      "includedFree": "Inclus sans frais"
    }
  },
  pt: {
    "global": {
      "skipToContent": "Ir para o conteúdo principal",
      "directions": "Como chegar",
      "contactWhatsApp": "Contato via WhatsApp",
      "assistance247": "Atendimento 24/7",
      "elevatedExperience": "EXPERIÊNCIA COMPLETA",
      "alsoIncluded": "Também incluído:",
      "night": "noite",
      "includedFree": "Incluído sem custo extra"
    }
  }
};

['en', 'es', 'fr', 'pt'].forEach(lang => {
  const filePath = path.join(i18nDir, `${lang}.json`);
  if (fs.existsSync(filePath)) {
    const data = JSON.parse(fs.readFileSync(filePath, 'utf8'));
    data.global = { ...data.global, ...newKeys[lang].global };
    fs.writeFileSync(filePath, JSON.stringify(data, null, 2));
    console.log(`Updated ${lang}.json`);
  }
});
