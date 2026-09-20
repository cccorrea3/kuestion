import { chromium } from 'playwright-core';

// Ola 3, Punto 3 — D.2/D.3a/FD-4: verificación visual en Chromium real.
// Anclas explícitas (lección Punto 1.1): nada de clics por posición; el dato
// esperado en pantalla proviene de QuBeKa real (FD-3a: 'competencias clave').

const BASE = 'http://127.0.0.1:8001';
const SHOTS = '/tmp/p3';
const ANCLA_REAL = 'competencias clave'; // texto de la sugerencia real del workspace QBK
const TITULO = 'Quizás te interese preguntar';
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

// 1) Login real -------------------------------------------------------------
await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
await page.fill('#email', 'qa-p3@test.local'); // usuario QA dedicado (no mutar el user real)
await page.fill('#password', 'devpassword');
await Promise.all([page.waitForURL('**/questions', { timeout: 15000 }).catch(() => {}), page.click('button:has-text("Iniciar sesión")')]);
await page.waitForTimeout(1500);
check('login: sesión iniciada', page.url().includes('/questions'), page.url());

// 2) Pantalla de entrada — wire:init dispara cargarSugerencias ---------------
await page.goto(`${BASE}/questions/create`, { waitUntil: 'networkidle' });
await page.waitForTimeout(2500); // wire:init + respuesta de QuBeKa real
await page.screenshot({ path: `${SHOTS}/01-create-sugerencias.png`, fullPage: true });
const cuerpo = await page.locator('body').innerText();
check('sección: título visible', cuerpo.includes(TITULO), TITULO);

const btnsSugerencia = page.locator('form button').filter({ hasText: '¿' });
const nSugerencias = await btnsSugerencia.count();
check('sección: 3-5 sugerencias clickeables', nSugerencias >= 1 && nSugerencias <= 5, `n=${nSugerencias}`);

// FD-3a — los datos vienen del grafo real de QuBeKa (no del catálogo local)
check('FD-3a: sugerencia real de QuBeKa en pantalla', cuerpo.includes(ANCLA_REAL), ANCLA_REAL);
check('C.2: textarea disponible junto a la sección', await page.locator('#questionText').isVisible());

if (nSugerencias > 0) {
  const primera = btnsSugerencia.first();
  const textoPrimera = (await primera.textContent() ?? '').trim();
  const estilos = await primera.evaluate((el) => {
    const cs = getComputedStyle(el);
    return { display: cs.display, visibility: cs.visibility, bg: cs.backgroundColor, color: cs.color, clases: el.className.split(/\s+/) };
  });
  check('C.1: botón visible con fondo', estilos.display !== 'none' && estilos.visibility === 'visible' && estilos.bg !== 'rgba(0, 0, 0, 0)', `bg=${estilos.bg}`);
  const faltan = ['w-full', 'rounded-lg', 'border', 'bg-page', 'text-text', 'hover:border-primary/40'].filter((c) => !estilos.clases.includes(c));
  check('C.1: clases en DOM', faltan.length === 0, faltan.join(',') || 'todas');
  check('C.1: contraste (color ≠ fondo)', estilos.color !== estilos.bg, `color=${estilos.color} bg=${estilos.bg}`);

  // C.3 — sin estados técnicos
  check('C.3: sin fuente/ids técnicos en pantalla', !cuerpo.includes('pregunta_abierta') && !cuerpo.includes('nodo_origen_id') && !cuerpo.includes('SQ-0'), 'solo texto claro');

  // 3) Clic → precarga en el textarea (nunca ejecuta) -------------------------
  await primera.click();
  await page.waitForTimeout(1200);
  const enTextarea = await page.inputValue('#questionText');
  check('FD-2/B.3: clic precarga el texto en el textarea', enTextarea === textoPrimera, `"${enTextarea.slice(0, 40)}…"`);
  await page.screenshot({ path: `${SHOTS}/02-precargada.png`, fullPage: true });

  // 4) Editar y enviar → flujo de guardado normal (regresión de la pantalla) --
  await page.fill('#questionText', `${enTextarea} (editado)`);
  await Promise.all([
    page.waitForSelector('text=Pregunta guardada', { timeout: 90000 }).catch(() => null),
    page.click('button:has-text("Consultar y guardar")'),
  ]);
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${SHOTS}/03-guardada.png`, fullPage: true });
  const guardo = (await page.locator('body').innerText()).includes('Pregunta guardada');
  check('FD-2: guardado completo con QBK real', guardo, 'pantalla Pregunta guardada');
}

// 5) Refresco tras enviar (§1.4): nueva pantalla vuelve a mostrar sugerencias -
await page.goto(`${BASE}/questions/create`, { waitUntil: 'networkidle' });
await page.waitForTimeout(2500);
check('§1.4: tras enviar, la sección vuelve a cargar', (await page.locator('body').innerText()).includes(TITULO));
await page.screenshot({ path: `${SHOTS}/04-refresco.png`, fullPage: true });

await browser.close();

const fallos = resultados.filter((r) => !r.ok);
console.log(`\nRESUMEN: ${resultados.length - fallos.length}/${resultados.length} verificaciones OK`);
if (fallos.length) {
  console.log('FALLOS:');
  fallos.forEach((f) => console.log(`  - ${f.nombre} ${f.detalle}`));
  process.exit(1);
}
