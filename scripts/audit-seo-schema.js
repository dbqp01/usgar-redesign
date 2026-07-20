// scripts/audit-seo-schema.js - Audit Schema.org JSON-LD in dist static pages
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const distDir = path.join(__dirname, '..', 'dist');

console.log('==========================================================');
console.log('🔍 AUDITORÍA DE SEO Y SCHEMA.ORG JSON-LD');
console.log('==========================================================');

if (!fs.existsSync(distDir)) {
  console.log('❌ Error: El directorio dist/ no existe. Ejecuta `npm run build` primero.');
  process.exit(1);
}

let totalPagesChecked = 0;
let totalSchemasFound = 0;
let errors = 0;

function checkHtmlFile(filePath) {
  const html = fs.readFileSync(filePath, 'utf8');
  totalPagesChecked++;

  const schemaRegex = /<script\s+is:inline\s+type="application\/ld\+json">([\s\S]*?)<\/script>/gi;
  const matches = [...html.matchAll(schemaRegex)];

  if (matches.length > 0) {
    matches.forEach(match => {
      totalSchemasFound++;
      try {
        const json = JSON.parse(match[1]);
        if (!json['@context'] || !json['@type']) {
          console.log(` ⚠️ WARN: [${path.basename(filePath)}] Schema no contiene @context o @type.`);
        }
      } catch (e) {
        console.log(` ❌ FAIL: [${path.basename(filePath)}] Error parseando JSON-LD: ${e.message}`);
        errors++;
      }
    });
  }
}

function traverseDir(dir) {
  const files = fs.readdirSync(dir);
  for (const file of files) {
    const fullPath = path.join(dir, file);
    const stat = fs.statSync(fullPath);
    if (stat.isDirectory()) {
      traverseDir(fullPath);
    } else if (file.endsWith('.html')) {
      checkHtmlFile(fullPath);
    }
  }
}

traverseDir(distDir);

console.log(`\n📊 RESUMEN DE AUDITORÍA SEO:`);
console.log(`   Páginas examinadas: ${totalPagesChecked}`);
console.log(`   Bloques JSON-LD encontrados: ${totalSchemasFound}`);
console.log(`   Errores de sintaxis Schema: ${errors}`);
console.log('==========================================================\n');

if (errors > 0) {
  process.exit(1);
} else {
  process.exit(0);
}
