import { spawn } from 'child_process';
import net from 'net';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');

const DEV_PORTS = [
  { port: 8000, name: 'PHP API' },
  { port: 4321, name: 'Astro dev' },
];

// Preflight: un servidor dev viejo en el mismo puerto hace que el nuevo falle
// en silencio y que el navegador siga viendo el .env ANTERIOR (Vite inlinea
// import.meta.env al arrancar; Config.php cachea el .env por proceso PHP).
// Es la causa tipica de "cambie el .env y siguen llegando las credenciales viejas".
async function isPortBusy(port) {
  const results = await Promise.all(['127.0.0.1', '::1'].map((host) => new Promise((resolve) => {
    const socket = net.connect({ port, host });
    socket.setTimeout(800);
    socket.once('connect', () => { socket.destroy(); resolve(true); });
    socket.once('timeout', () => { socket.destroy(); resolve(false); });
    socket.once('error', () => resolve(false));
  })));
  return results.some(Boolean);
}

for (const { port, name } of DEV_PORTS) {
  if (await isPortBusy(port)) {
    const killCmd = process.platform === 'win32'
      ? `taskkill /F /PID $(netstat -ano | findstr :${port} | findstr LISTENING | awk '{print $NF}')`
      : `lsof -ti:${port} | xargs kill`;
    console.error(`\n [dev] ERROR: ${name} ya esta escuchando en el puerto ${port}.`);
    console.error(' [dev] Ese proceso sirve el .env que tenia al arrancar (cacheado en memoria).');
    console.error(` [dev] Matalo y reintenta: ${killCmd}\n`);
    process.exit(1);
  }
}

console.log(' Iniciando entorno de desarrollo completo (Astro + PHP API)...\n');

let phpServer = null;

try {
  phpServer = spawn('php', ['-S', 'localhost:8000', 'public/index.php'], {
    cwd: rootDir,
    stdio: 'inherit',
  });

  phpServer.on('error', (err) => {
    if (err.code === 'ENOENT') {
      console.warn('\n⚠️  [dev] PHP no se encontró en el PATH del sistema.');
      console.warn('ℹ️   El frontend de Astro seguirá corriendo normalmente en http://localhost:4321.');
      console.warn('ℹ️   (Para usar endpoints de backend PHP en local, instala PHP 8 o usa "npm run dev" solo para frontend).\n');
    } else {
      console.error('❌ Error al iniciar el servidor PHP:', err.message);
    }
  });
} catch (err) {
  console.warn('⚠️  No se pudo iniciar el proceso de PHP:', err.message);
}

// 2. Iniciar Astro dev server en puerto 4321
const isWin = process.platform === 'win32';
const astroServer = spawn(
  isWin ? 'cmd.exe' : 'npx',
  isWin ? ['/c', 'npx', 'astro', 'dev'] : ['astro', 'dev'],
  { cwd: rootDir, stdio: 'inherit' }
);

astroServer.on('error', (err) => {
  console.error('❌ Error al iniciar Astro dev server:', err.message);
});

const cleanup = () => {
  console.log('\n🛑 Cerrando servidores de desarrollo...');
  if (phpServer && phpServer.pid) {
    try { phpServer.kill(); } catch {}
  }
  if (astroServer && astroServer.pid) {
    try { astroServer.kill(); } catch {}
  }
  process.exit(0);
};

process.on('SIGINT', cleanup);
process.on('SIGTERM', cleanup);
