import { spawn } from 'child_process';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');

console.log(' Iniciando entorno de desarrollo completo (Astro + PHP API)...\n');

// 1. Iniciar servidor PHP en puerto 8000
const phpServer = spawn('php', ['-S', 'localhost:8000', 'public/index.php'], {
  cwd: rootDir,
  stdio: 'inherit',
  shell: true,
});

phpServer.on('error', (err) => {
  console.error(' Error al iniciar el servidor PHP:', err.message);
});

// 2. Iniciar Astro dev server en puerto 4321
const astroServer = spawn('npx', ['astro', 'dev'], {
  cwd: rootDir,
  stdio: 'inherit',
  shell: true,
});

astroServer.on('error', (err) => {
  console.error(' Error al iniciar Astro dev server:', err.message);
});

const cleanup = () => {
  console.log('\n Cerrando servidores de desarrollo...');
  phpServer.kill();
  astroServer.kill();
  process.exit(0);
};

process.on('SIGINT', cleanup);
process.on('SIGTERM', cleanup);
