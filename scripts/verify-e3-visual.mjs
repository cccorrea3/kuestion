import { chromium } from 'playwright-core';

const BASE = 'http://127.0.0.1:8001';
const SHOTS = '/tmp/e3';
const DOC = 'estrategia de reintentos para pagos fallidos'; // texto único: solo la sesión 67 está pendiente con él (la 66 ya fue promovida)
const NODO_UNICO = 'backoff exponencial'; // texto de un nodo SOLO de la sesión 67
const resultados = [];
const check = (nombre, ok, detalle = '') => {
  resultados.push({ nombre, ok, detalle });
  console.log(`${ok ? 'PASS' : 'FAIL'} | ${nombre}${detalle ? ' | ' + detalle : ''}`);
};

async function auditBtn(page, locator, nombre, clasesEsperadas) {
  const info = await locator.evaluate((el) => {
    const cs = getComputedStyle(el);
    return { display: cs.display, visibility: cs.visibility, bg: cs.backgroundColor, color: cs.color, clases: el.className.split(/\s+/) };
  });
  const faltan = clasesEsperadas.filter((c) => !info.clases.includes(c));
  check(`${nombre}: visible con estilos`, info.display !== 'none' && info.visibility === 'visible' && info.bg !== 'rgba(0, 0, 0, 0)', `bg=${info.bg} color=${info.color}`);
  check(`${nombre}: clases en DOM`, faltan.length === 0, faltan.join(',') || 'todas');
  check(`${nombre}: contraste (heurístico)`, info.color !== info.bg, `color=${info.color}`);
}

const browser = await chromium.launch({
  executablePath: '/root/.cache/ms-playwright/chromium-1223/chrome-linux64/chrome',
  headless: true,
  args: ['--no-sandbox'],
});
const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });

// 1) Login real -------------------------------------------------------------
await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
await page.screenshot({ path: `${SHOTS}/01-login.png` });
await page.fill('#email', 'ccorrea@proteam.cl');
await page.fill('#password', 'devpassword');
await Promise.all([page.waitForURL('**/questions', { timeout: 15000 }).catch(() => {}), page.click('button:has-text("Iniciar sesión")')]);
await page.waitForTimeout(1500);
check('login: sesión iniciada', page.url().includes('/questions'), page.url());

// 2) Bandeja ----------------------------------------------------------------
const linkBandeja = page.locator('a[href*="reviews"]').first();
await Promise.all([page.waitForLoadState('networkidle').catch(() => {}), linkBandeja.click()]);
await page.waitForTimeout(2000);
await page.screenshot({ path: `${SHOTS}/02-bandeja.png`, fullPage: true });
check('bandeja: URL', page.url().includes('/reviews'), page.url());

// 3) Localizar el ítem de la sesión objetivo POR SU TEXTO ÚNICO -------------
const item = page
  .locator('div')
  .filter({ hasText: DOC })
  .filter({ has: page.locator('button:has-text("Revisar documento")') })
  .last();
const btnExpandir = item.locator('button:has-text("Revisar documento")').first();
check('ancla: ítem del documento objetivo visible con su botón', await btnExpandir.isVisible(), DOC);
await auditBtn(page, btnExpandir, 'btn Revisar documento', ['inline-flex', 'rounded-lg', 'border', 'bg-surface']);

// 4) Expandir el documento correcto -----------------------------------------
await btnExpandir.click();
await page.waitForTimeout(2000);
await page.screenshot({ path: `${SHOTS}/03-documento-expandido.png`, fullPage: true });
const cuerpo = await page.locator('body').innerText();
check('expandido: es la sesión 66 (nodo único presente)', cuerpo.includes(NODO_UNICO), NODO_UNICO);
if (!cuerpo.includes(NODO_UNICO)) {
  console.log('HARD STOP: el panel expandido no es el documento objetivo.');
  await browser.close();
  process.exit(1);
}

const btnAprobar = page.locator('button:has-text("Aprobar seleccionados")').first();
check('expandido: botón aprobar visible', await btnAprobar.isVisible());
await auditBtn(page, btnAprobar, 'btn Aprobar seleccionados', ['bg-emerald-600', 'text-white']);

// Deseleccionar la Q raíz (por texto del label — no por posición) para forzar huérfanos.
const labelQ = page.locator('label').filter({ hasText: '¿Cuál es la estrategia de reintentos' }).first();
check('selección: label de la Q raíz visible', await labelQ.isVisible());
const checkQ = labelQ.locator('input[wire\\:click*="alternarNodo"]');
await checkQ.uncheck();
await page.waitForTimeout(500);
const contador = (await btnAprobar.textContent() ?? '').replace(/\s+/g, ' ').trim();
check('selección: contador refleja la deselección', /\(\d+\)/.test(contador), contador);
await page.screenshot({ path: `${SHOTS}/03b-seleccion.png`, fullPage: true });

// 5) "Aprobar seleccionados" → panel de advertencia --------------------------
await btnAprobar.click();
await page.waitForTimeout(3000);
await page.screenshot({ path: `${SHOTS}/04-advertencia.png`, fullPage: true });
const advertenciaVisible = await page.getByText('Revisa las consecuencias de esta selección').first().isVisible().catch(() => false);
check('advertencia: panel visible tras aprobar subconjunto', advertenciaVisible);
if (advertenciaVisible) {
  const texto = await page.locator('body').innerText();
  check('advertencia: nota informativa (no bloquea)', texto.includes('Estas consecuencias no bloquean la aprobación'));
  const tipos = ['Se promovería como raíz del grafo', 'El enlace no se recreará', 'Quedarían como nodos separados'];
  const encontrados = tipos.filter((t) => texto.includes(t));
  check('advertencia: encabezados por tipo', encontrados.length > 0, encontrados.join(' | '));
  check('advertencia: nodos citados por texto', texto.includes('«'), 'cita con «»');
  check('advertencia: descripción lista de QuBeKa (no reescrita)', texto.includes('quedará como raíz') || texto.includes('no se recreará') || texto.includes('grafo'));

  const btnConfirmar = page.locator('button:has-text("Confirmar aprobación")').first();
  const btnVolver = page.locator('button:has-text("Volver a la selección")').first();
  check('advertencia: botón Confirmar visible', await btnConfirmar.isVisible());
  await auditBtn(page, btnConfirmar, 'btn Confirmar aprobación', ['bg-emerald-600', 'text-white']);
  check('advertencia: botón Volver visible', await btnVolver.isVisible());

  // 6) "Volver a la selección": selección intacta (criterio de cierre #5) ----
  await btnVolver.click();
  await page.waitForTimeout(2000);
  await page.screenshot({ path: `${SHOTS}/05-volver-seleccion.png`, fullPage: true });
  check('volver: panel de advertencia desaparece', !(await page.getByText('Revisa las consecuencias de esta selección').first().isVisible().catch(() => false)));
  const qSigueDeseleccionada = !(await checkQ.isChecked());
  check('volver: la Q sigue deseleccionada (selección intacta)', qSigueDeseleccionada);

  // 7) Confirmar la aprobación del subconjunto -------------------------------
  await btnAprobar.click();
  await page.waitForTimeout(2500);
  await page.locator('button:has-text("Confirmar aprobación")').first().click();
  await page.waitForTimeout(3000);
  await page.screenshot({ path: `${SHOTS}/06-confirmado.png`, fullPage: true });
  const quedoPanel = await page.getByText('Confirmar aprobación').first().isVisible().catch(() => false);
  const fallo = await page.getByText('No se pudo completar la operación').first().isVisible().catch(() => false);
  check('confirmar: flujo cerró sin error', !quedoPanel && !fallo, fallo ? 'error visible' : 'panel cerrado');
}

await browser.close();

const fallos = resultados.filter((r) => !r.ok);
console.log(`\nRESUMEN: ${resultados.length - fallos.length}/${resultados.length} verificaciones OK`);
if (fallos.length) {
  console.log('FALLOS:');
  fallos.forEach((f) => console.log(`  - ${f.nombre} ${f.detalle}`));
  process.exit(1);
}
