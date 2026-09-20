import { chromium } from 'playwright-core';

// FD-4: QuBeKa caído → la pantalla carga normal, sin sección, sin spinner
// colgado, y el guardado sigue operativo (el flujo del usuario no se rompe).

const BASE = 'http://127.0.0.1:8001';
const SHOTS = '/tmp/p3';
const resultados = [];
const check = (nombre, ok, detalle = '') => {
  resultados.push({ nombre, ok, detalle });
  console.log(`${ok ? 'PASS' : 'FAIL'} | ${nombre}${detalle ? ' | ' + detalle : ''}`);
};

const browser = await chromium.launch({
  executablePath: '/root/.cache/ms-playwright/chromium-1223/chrome-linux64/chrome',
  headless: true,
  args: ['--no-sandbox'],
});
const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });

await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
await page.fill('#email', 'qa-p3@test.local');
await page.fill('#password', 'devpassword');
await Promise.all([page.waitForURL('**/questions', { timeout: 15000 }).catch(() => {}), page.click('button:has-text("Iniciar sesión")')]);
await page.waitForTimeout(1500);
check('login', page.url().includes('/questions'), page.url());

await page.goto(`${BASE}/questions/create`, { waitUntil: 'networkidle' });
await page.waitForTimeout(9000); // > timeout de 5s del cliente + margen Livewire

const cuerpo = await page.locator('body').innerText();
check('FD-4: pantalla cargó sin congelarse', cuerpo.includes('Nueva pregunta'));
check('FD-4: sin sección de sugerencias', !cuerpo.includes('Quizás te interese preguntar'));
check('FD-4: sin error visible (degradación silenciosa §4)', !cuerpo.includes('Error al consultar'));
// El spinner real es el svg.animate-spin del botón; 'Consultando' también aparece
// en el encabezado fijo del repo único (no es spinner).
const spinners = await page.locator('svg.animate-spin').count();
check('FD-4: sin spinner colgado (flujo en idle)', cuerpo.includes('Consultar y guardar') && spinners === 0, `spinners=${spinners}`);
check('C.2: textarea disponible', await page.locator('#questionText').isVisible());

await page.screenshot({ path: `${SHOTS}/05-qbk-caido.png`, fullPage: true });
await browser.close();

const fallos = resultados.filter((r) => !r.ok);
console.log(`\nRESUMEN: ${resultados.length - fallos.length}/${resultados.length} verificaciones OK`);
if (fallos.length) {
  fallos.forEach((f) => console.log(`  - ${f.nombre} ${f.detalle}`));
  process.exit(1);
}
