// Visual check of the storefront: screenshots at 375, 768 and 1440 px of the home, /promociones, a product with a promotion,
// the open cart and the checkout (step 3 without coupon, with an invalid coupon, with a valid one, and the finished order).
// With admin credentials it also photographs /admin/coupons, /admin/orders and /admin/customers (empty state or list, each filter pill, the detail drawer, the WhatsApp link).
// Needs the app running (php artisan serve + npm run dev, or a build).
//
//   npm run screenshots                                   # storefront against http://127.0.0.1:8000
//   SCREENSHOT_BASE_URL=http://127.0.0.1:8123 npm run screenshots
//
// Optional environment (never write these in the repo):
//   SCREENSHOT_COUPON=HELENA10          a valid coupon code: adds the "valid coupon" capture
//   SCREENSHOT_PLACE_ORDER=1            confirms an order to photograph step 4. It CREATES an order: only with a throwaway database.
//   SCREENSHOT_ADMIN_EMAIL / SCREENSHOT_ADMIN_PASSWORD   an admin user of a LOCAL database: adds the /admin/coupons captures
//   SCREENSHOT_HOY_ONLY=1               only Hoy (/admin/today) as admin, dispatch (carlos.rojas) and delivery (luis.mora); their password comes from SCREENSHOT_ROLE_PASSWORD
//
// Images go to storage/app/screenshots/<width>/ (ignored by git). Open them and look at them.
import { chromium } from '@playwright/test';
import { mkdirSync, rmSync } from 'node:fs';

const BASE = (process.env.SCREENSHOT_BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const OUT = 'storage/app/screenshots';
const SIZES = [{ width: 375, height: 812 }, { width: 768, height: 1024 }, { width: 1440, height: 900 }];
const COUPON = process.env.SCREENSHOT_COUPON || '';
const PLACE_ORDER = process.env.SCREENSHOT_PLACE_ORDER === '1';
const HOY_ONLY = process.env.SCREENSHOT_HOY_ONLY === '1'; // only the Hoy captures (admin, dispatch, delivery): needs the three people and their orders in the throwaway database
const ADMIN = { email: process.env.SCREENSHOT_ADMIN_EMAIL || '', password: process.env.SCREENSHOT_ADMIN_PASSWORD || '' };
const isLocal = ['127.0.0.1', 'localhost', '[::1]'].includes(new URL(BASE).hostname);
if ((ADMIN.email || PLACE_ORDER) && !isLocal) throw new Error('Admin credentials and order placing are only allowed against a local server.');

const response = await fetch(`${BASE}/api/products`, { headers: { Accept: 'application/json' } });
if (!response.ok) throw new Error(`${BASE}/api/products answered ${response.status}: is the app running?`);
const { data: products } = await response.json();
const isBuyable = product => product.variants.some(variant => variant.in_stock);
const promoted = products.find(product => isBuyable(product) && product.variants.some(variant => variant.original_price_cop));
const product = promoted || products.find(isBuyable);
if (!product) throw new Error('No product with stock to photograph: load some stock first.');
if (!promoted) console.warn('! No product with a current promotion: the product page is a normal one.');

rmSync(OUT, { recursive: true, force: true });
const browser = await chromium.launch();

for (const size of SIZES) {
    const dir = `${OUT}/${size.width}`;
    mkdirSync(dir, { recursive: true });
    const context = await browser.newContext({ viewport: size, locale: 'es-CO', timezoneId: 'America/Bogota' });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', async reply => { if (reply.url().includes('/api/orders') && reply.status() >= 400 && reply.request().method() === 'POST') errors.push(`${reply.status()} ${reply.url()} ${(await reply.text().catch(() => '')).slice(0, 160)}`); });
    const shot = async (name, options = {}) => { await page.waitForTimeout(500); await page.screenshot({ path: `${dir}/${name}.png`, ...options }); console.log(`${size.width}px  ${name}`); };
    const open = async path => { await page.goto(BASE + path, { waitUntil: 'load' }); await page.waitForSelector('.card, .promotions-empty, .product-detail, .empty-state', { timeout: 20000 }); };

    if (!HOY_ONLY) {
    await open('/'); await shot('1-home', { fullPage: true });
    await open('/promociones'); await page.waitForTimeout(1500); await shot('2-promociones-llegada');
    await page.evaluate(() => window.scrollTo(0, 0)); await shot('2-promociones', { fullPage: true });
    await open(`/producto/${product.slug}`); await shot('3-producto', { fullPage: true });

    // WhatsApp: the number comes from the server config (<meta name="shop-whatsapp">). Every place that used to hold it by hand must use it.
    const storeNumber = await page.evaluate(() => document.querySelector('meta[name="shop-whatsapp"]')?.content || '');
    const product_link = await page.locator('a:has-text("Consultar por WhatsApp")').first().getAttribute('href');
    await open('/');
    const footerLink = await page.locator('footer a[href^="https://wa.me/"]').first().getAttribute('href');
    await page.fill('#preguntas-frecuentes input[type=search]', 'zzzzqqxx');
    const faqLink = await page.locator('#preguntas-frecuentes a[href^="https://wa.me/"]').first().getAttribute('href');
    await page.fill('#preguntas-frecuentes input[type=search]', '');
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.click('.fab-mayorista');
    await page.waitForSelector('.whatsapp-menu');
    const optionLinks = await page.locator('.whatsapp-menu-option').evaluateAll(nodes => nodes.map(node => node.getAttribute('href')));
    const places = { 'product page': product_link, footer: footerLink, FAQ: faqLink, ...Object.fromEntries(optionLinks.map((href, index) => [`menu option ${index + 1}`, href])) };
    for (const [name, href] of Object.entries(places)) {
        const ok = href && href.startsWith(`https://wa.me/${storeNumber}`) && !decodeURIComponent(href).includes('\uFFFD');
        if (!storeNumber || !ok) console.warn(`! ${size.width}px WhatsApp ${name} does not use the configured number (${storeNumber || 'none'}): ${href}`);
    }
    console.log(`${size.width}px  WhatsApp links ok (${Object.keys(places).length} places, number ${storeNumber}); menu texts: ${optionLinks.map(href => decodeURIComponent(new URL(href).searchParams.get('text'))).join(' | ')}`);
    await shot('14-tienda-menu-whatsapp');
    // A click registers itself (204) and opens WhatsApp in a new tab.
    await context.route(/^https:\/\/(wa\.me|api\.whatsapp\.com)\//, route => route.fulfill({ status: 200, contentType: 'text/html', body: 'WhatsApp' })); // no real network
    const registered = page.waitForResponse(reply => reply.url().endsWith('/api/whatsapp/clicks'));
    const [popup] = await Promise.all([page.waitForEvent('popup'), page.locator('.whatsapp-menu-option').first().click()]);
    const status = (await registered).status();
    if (status !== 204 || !popup.url().startsWith('https://wa.me/')) console.warn(`! ${size.width}px menu click: status ${status}, popup ${popup.url()}`);
    await popup.close();
    await page.waitForSelector('.whatsapp-menu', { state: 'detached' });
    await open(`/producto/${product.slug}`); // back to the product page for the cart steps below

    // Cart: add from the product page (the drawer opens by itself).
    await page.getByRole('button', { name: /^Agregar al carrito/ }).click();
    await page.waitForSelector('.drawer-item');
    await shot('4-carrito');

    // Checkout, step 3 (summary).
    await page.getByRole('button', { name: /Continuar con el pedido/ }).click();
    await page.fill('#co-customer_name', 'María González');
    await page.fill('#co-customer_email', 'maria@example.com');
    await page.fill('#co-customer_phone', '3001234567');
    await page.getByRole('button', { name: /^Continuar/ }).click();
    await page.selectOption('#co-delivery_department', 'Cundinamarca');
    await page.fill('#co-delivery_city', 'Bogotá');
    await page.fill('#co-delivery_address', 'Calle 45 # 12-34');
    await page.getByRole('button', { name: /^Continuar/ }).click();
    await page.waitForSelector('.co-order-total');
    await shot('5-checkout-paso3');
    await page.evaluate(() => document.querySelector('.co-modal')?.scrollTo(0, 99999));
    await shot('5-checkout-paso3-final');

    // Coupons: an invalid code, then (when SCREENSHOT_COUPON is set) a valid one.
    const apply = async code => { await page.fill('#co-coupon', code); await page.getByRole('button', { name: /^Aplicar/ }).click(); };
    await apply('NOEXISTE'); await page.waitForSelector('#co-coupon-message');
    await page.evaluate(() => document.querySelector('.co-guide-box')?.scrollIntoView({ block: 'center' }));
    await shot('6-checkout-cupon-invalido');
    if (COUPON) {
        await apply(COUPON);
        await page.waitForSelector('.co-coupon-chip', { timeout: 10000 });
        await page.evaluate(() => document.querySelector('.co-coupon-chip')?.scrollIntoView({ block: 'center' }));
        await shot('7-checkout-cupon-valido');
        await page.evaluate(() => document.querySelector('.co-modal')?.scrollTo(0, 0));
        await shot('7-checkout-cupon-valido-resumen');
        if (PLACE_ORDER) {
            await page.check('#co-terms_accepted');
            await page.check('#co-data_consent');
            await page.getByRole('button', { name: /^Confirmar pedido/ }).click();
            await page.waitForSelector('.co-done', { timeout: 15000 }).catch(async error => { console.warn(`! ${size.width}px the order was not confirmed:`, errors.join(' | ')); throw error; });
            await shot('8-checkout-paso4');
        }
    }

    }

    if (errors.length) console.warn(`! ${size.width}px page errors:`, errors.join(' | '));
    await context.close();

    // Admin: the coupons page (empty state or rows) and its drawer.
    if (ADMIN.email) {
        const admin = await browser.newContext({ viewport: size, locale: 'es-CO', timezoneId: 'America/Bogota' });
        const adminPage = await admin.newPage();
        const adminErrors = [];
        adminPage.on('pageerror', error => adminErrors.push(error.message));
        // Admin pages draw "Cargando…" until /admin/session answers: wait for the page title before looking at anything.
        const adminGoto = async url => { await adminPage.goto(url, { waitUntil: 'load' }); await adminPage.waitForSelector('.dashboard-content h1', { timeout: 20000 }); };
        const adminShot = async (name, options = {}) => { await adminPage.waitForTimeout(500); await adminPage.screenshot({ path: `${dir}/${name}.png`, ...options }); console.log(`${size.width}px  ${name}`); };
        await adminPage.goto(`${BASE}/admin/login`, { waitUntil: 'load' });
        await adminPage.fill('#admin-email', ADMIN.email);
        await adminPage.fill('#admin-password', ADMIN.password);
        await Promise.all([adminPage.waitForURL(url => ['/admin', '/admin/today'].includes(url.pathname), { timeout: 15000 }), adminPage.getByRole('button', { name: /Iniciar sesión/ }).click()]);
        if (!HOY_ONLY) {
        await adminGoto(`${BASE}/admin/coupons`);
        await adminPage.waitForSelector('.admin-coupons table, .admin-promotions-empty', { timeout: 20000 });
        const empty = await adminPage.locator('.admin-promotions-empty').count();
        await adminShot(empty ? '9-admin-cupones-vacio' : '9-admin-cupones', { fullPage: true });
        await adminPage.getByRole('button', { name: /\+ Nuevo cupón/ }).first().click();
        await adminPage.waitForSelector('.admin-coupon-drawer');
        await adminPage.click('.admin-coupon-drawer button:has-text("Generar")');
        await adminPage.fill('#coupon-discount_value', '10');
        await adminPage.evaluate(() => document.querySelector('.admin-categories-drawer-body')?.scrollTo(0, 0));
        await adminShot('9-admin-cupones-drawer');
        await adminPage.evaluate(() => document.querySelector('.admin-categories-drawer-body')?.scrollTo(0, 99999));
        await adminShot('9-admin-cupones-drawer-final');
        await adminGoto(`${BASE}/admin/orders`);
        await adminPage.waitForFunction(() => !document.body.innerText.includes('Cargando pedidos'), null, { timeout: 20000 });
        await adminShot('10-admin-pedidos', { fullPage: true });
        // Customers: empty state or the list, one capture per filter pill, the detail drawer and the WhatsApp notice.
        await adminGoto(`${BASE}/admin/customers`);
        const loaded = () => adminPage.waitForFunction(() => !document.body.innerText.includes('Cargando clientes'), null, { timeout: 20000 });
        await loaded();
        if (await adminPage.locator('.admin-promotions-empty').count()) {
            await adminShot('11-clientes-vacio', { fullPage: true });
        } else {
            await adminShot('11-clientes', { fullPage: true });
            for (const [key, name] of [['recurrentes', /^Recurrentes/], ['por-recuperar', /^Por recuperar/], ['nuevos', /^Nuevos/], ['promociones', /^Aceptan promociones/]]) {
                await adminPage.getByRole('button', { name }).click();
                await adminPage.waitForTimeout(400); await loaded();
                await adminShot(`11-clientes-filtro-${key}`, { fullPage: true });
            }
            await adminPage.getByRole('button', { name: /^Todos/ }).click();
            await adminPage.waitForTimeout(400); await loaded();
            await adminPage.locator('.admin-customers-name').first().click();
            await adminPage.waitForSelector('.admin-customer-drawer .admin-customer-section');
            await adminShot('12-cliente-drawer');
            await adminPage.evaluate(() => document.querySelector('.admin-customer-drawer .admin-categories-drawer-body')?.scrollTo(0, 99999));
            await adminShot('12-cliente-drawer-final');
            await adminPage.keyboard.press('Escape');
            await adminPage.waitForSelector('.admin-customer-drawer', { state: 'detached' });
            // WhatsApp opens directly (no notice): check the link of a customer who did not accept promotions.
            const link = adminPage.locator('tr:has(.admin-customers-none) a.admin-customers-whatsapp').first();
            const href = await link.getAttribute('href');
            const text = decodeURIComponent(new URL(href).searchParams.get('text'));
            if (!/^https:\/\/wa\.me\/573\d{9}\?text=/.test(href) || text.includes('\uFFFD') || !text.includes('Café Finca Helena')) console.warn(`! ${size.width}px bad WhatsApp link: ${href}`);
            else console.log(`${size.width}px  WhatsApp link ok: ${text}`);
            await link.scrollIntoViewIfNeeded();
            await adminShot('13-clientes-whatsapp-enlace');
        }
        // WhatsApp module: list (empty or with requests), copy button, quick replies in edit mode and the request drawer.
        await adminGoto(`${BASE}/admin/whatsapp`);
        await adminPage.waitForFunction(() => !document.body.innerText.includes('Cargando solicitudes'), null, { timeout: 20000 });
        const hasRequests = await adminPage.locator('.admin-wa-item').count();
        await adminShot(hasRequests ? '15-admin-whatsapp' : '15-admin-whatsapp-vacio', { fullPage: true });
        await adminPage.locator('.admin-wa-copy').first().click();
        await adminPage.waitForSelector('.admin-wa-copy.is-copied');
        await adminPage.locator('.admin-wa-reply').first().scrollIntoViewIfNeeded();
        await adminShot('15-admin-whatsapp-copiado');
        await adminPage.getByRole('button', { name: 'Editar respuestas' }).click();
        await adminPage.waitForSelector('.admin-wa-preview');
        await adminPage.locator('.admin-wa-reply').first().scrollIntoViewIfNeeded();
        await adminShot('15-admin-whatsapp-editar');
        await adminPage.getByRole('button', { name: 'Listo' }).click();
        if (hasRequests) {
            await adminPage.locator('.admin-wa-item').first().click();
            await adminPage.waitForSelector('.admin-wa-drawer .admin-customer-section');
            await adminShot('16-admin-whatsapp-drawer');
            await adminPage.evaluate(() => document.querySelector('.admin-wa-drawer .admin-categories-drawer-body')?.scrollTo(0, 99999));
            await adminShot('16-admin-whatsapp-drawer-final');
            await adminPage.keyboard.press('Escape');
        }
        // History (audit trail): list, with accesses, the record drawer with its changes, the filters applied and the empty state.
        await adminGoto(`${BASE}/admin/activity`);
        const historyLoaded = () => adminPage.waitForFunction(() => !document.body.innerText.includes('Cargando historial'), null, { timeout: 20000 });
        await historyLoaded();
        await adminShot('17-historial', { fullPage: true });
        await adminPage.getByLabel('Incluir accesos').check();
        await adminPage.getByRole('button', { name: 'Aplicar filtros' }).click();
        await adminPage.waitForTimeout(400); await historyLoaded();
        await adminShot('17-historial-accesos', { fullPage: true });
        await adminPage.getByLabel('Incluir accesos').uncheck();
        await adminPage.getByLabel('Módulo').selectOption('products');
        await adminPage.getByRole('button', { name: 'Aplicar filtros' }).click();
        await adminPage.waitForTimeout(400); await historyLoaded();
        await adminShot('17-historial-filtrado', { fullPage: true });
        await adminPage.locator('tbody:has-text("Actualizó") tr').first().click();
        await adminPage.waitForSelector('.admin-activity-drawer .admin-activity-changes');
        await adminShot('18-historial-drawer');
        await adminPage.evaluate(() => document.querySelector('.admin-activity-drawer .admin-categories-drawer-body')?.scrollTo(0, 99999));
        await adminShot('18-historial-drawer-final');
        await adminPage.keyboard.press('Escape');
        await adminPage.waitForSelector('.admin-activity-drawer', { state: 'detached' });
        await adminGoto(`${BASE}/admin/activity?from=2030-01-01`);
        await adminPage.waitForSelector('.admin-promotions-empty', { timeout: 20000 });
        await adminShot('19-historial-vacio', { fullPage: true });
        // Users and roles: list, create drawer (role cards), temporary password notice, row menu.
        await adminGoto(`${BASE}/admin/users`);
        await adminPage.waitForFunction(() => !document.body.innerText.includes('Cargando personas'), null, { timeout: 20000 });
        await adminShot('20-usuarios', { fullPage: true });
        await adminPage.locator('.admin-users-more').nth(1).click();
        await adminShot('20-usuarios-menu');
        await adminPage.keyboard.press('Escape');
        await adminPage.getByRole('button', { name: '+ Agregar persona' }).click();
        await adminPage.waitForSelector('.admin-users-drawer #user-name');
        await adminPage.fill("#user-name", `Persona Prueba${Date.now() % 1000000}`);
        await adminPage.click('.admin-users-drawer button:has-text("Generar")');
        await adminPage.waitForSelector('.admin-users-check.is-ok');
        await adminShot('21-usuarios-crear-drawer');
        await adminPage.evaluate(() => document.querySelector('.admin-users-drawer .admin-categories-drawer-body')?.scrollTo(0, 99999));
        await adminShot('21-usuarios-crear-drawer-final');
        await adminPage.getByRole('button', { name: 'Crear usuario' }).click();
        await adminPage.waitForSelector('.admin-users-temp');
        await adminShot('22-usuarios-contrasena-temporal');
        await adminPage.getByRole('button', { name: 'Listo' }).click();
        await adminPage.waitForTimeout(500);
        }
        // Hoy as admin: the four sections (with an amber and a red card), the empty state (the real screen with an empty answer), the "Enviar" panel,
        // the order detail with its stage button and the menu.
        await adminGoto(`${BASE}/admin/today`);
        await adminPage.waitForSelector('.today-section', { timeout: 20000 });
        await adminShot('27-hoy-admin', { fullPage: true });
        const lateCards = await adminPage.locator('.today-age.is-slow, .today-age.is-late').count();
        if (!lateCards) console.warn(`! ${size.width}px Hoy has no amber/red card: load an old order to photograph them`);
        await adminPage.locator('.today-age.is-slow').first().scrollIntoViewIfNeeded().catch(() => {});
        await adminShot('27-hoy-admin-tarjeta-ambar');
        await adminPage.locator('.today-age.is-late').first().scrollIntoViewIfNeeded().catch(() => {});
        await adminShot('27-hoy-admin-tarjeta-roja');
        await adminPage.locator('#today-ready .today-action').first().click();
        await adminPage.waitForSelector('.admin-confirm-dialog');
        await adminShot('27-hoy-panel-enviar');
        await adminPage.keyboard.press('Escape');
        await adminPage.waitForSelector('.admin-confirm-dialog', { state: 'detached' });
        await adminPage.locator('#today-new .today-card h3 a').first().click();
        await adminPage.waitForSelector('.admin-fulfillment', { timeout: 20000 });
        await adminShot('27-hoy-pedido-detalle-boton-etapa', { fullPage: true });
        if (size.width < 768) { await adminPage.click('.dashboard-menu-button'); await adminPage.waitForSelector('.dashboard-sidebar.is-open'); }
        await adminShot('27-menu-admin');
        const emptyContext = await browser.newContext({ viewport: size, locale: 'es-CO', timezoneId: 'America/Bogota', storageState: await admin.storageState() });
        const emptyPage = await emptyContext.newPage();
        await emptyPage.route('**/admin/today/data', route => route.fulfill({ json: { sections: { new: [], preparing: [], ready: [], shipping: [] }, counts: { new: 0, preparing: 0, ready: 0, shipping: 0 }, couriers: [], generated_at: new Date().toISOString() } }));
        await emptyPage.goto(`${BASE}/admin/today`, { waitUntil: 'load' });
        await emptyPage.waitForSelector('.today-section', { timeout: 20000 });
        await emptyPage.waitForTimeout(500);
        await emptyPage.screenshot({ path: `${dir}/27-hoy-admin-vacio.png`, fullPage: true });
        console.log(`${size.width}px  27-hoy-admin-vacio`);
        await emptyContext.close();
        // The same pages seen by the other roles (each one signs in on its own).
        const asRole = async (login, work) => {
            const ctx = await browser.newContext({ viewport: size, locale: 'es-CO', timezoneId: 'America/Bogota' });
            const pg = await ctx.newPage();
            const shot = async (name, options = {}) => { await pg.waitForTimeout(500); await pg.screenshot({ path: `${dir}/${name}.png`, ...options }); console.log(`${size.width}px  ${name}`); };
            await pg.goto(`${BASE}/admin/login`, { waitUntil: 'load' });
            await pg.fill('#admin-email', login);
            await pg.fill('#admin-password', process.env.SCREENSHOT_ROLE_PASSWORD || '');
            await Promise.all([pg.waitForURL(url => url.pathname !== '/admin/login', { timeout: 15000 }), pg.getByRole('button', { name: /Iniciar sesión/ }).click()]);
            await pg.waitForSelector('.dashboard-content h1, #new-password', { timeout: 20000 });
            await work(pg, shot);
            await ctx.close();
        };
        if (!HOY_ONLY) {
        await asRole('nuevo.temporal', async (pg, shot) => {
            await pg.waitForSelector('#new-password', { timeout: 20000 });
            await shot('23-cambiar-contrasena');
        });
        }
        await asRole('carlos.rojas', async (pg, shot) => {
            if (new URL(pg.url()).pathname !== '/admin/today') console.warn(`! ${size.width}px dispatch landed on ${pg.url()}, not on Hoy`);
            await pg.waitForSelector('.today-section', { timeout: 20000 });
            await shot('24-despacho-hoy', { fullPage: true });
            const leakToday = await pg.evaluate(() => /\$\s?\d|@[a-z0-9-]+\.[a-z]|HELENA10/i.test(document.querySelector('.dashboard-main').innerText));
            if (leakToday) console.warn(`! ${size.width}px dispatch Hoy shows an email, a price or the coupon`);
            if (size.width < 768) { await pg.click('.dashboard-menu-button'); await pg.waitForSelector('.dashboard-sidebar.is-open'); }
            await shot('24-menu-despacho');
            if (size.width < 768) await pg.keyboard.press('Escape');
            await pg.goto(`${BASE}/admin/orders`, { waitUntil: 'load' });
            await pg.waitForFunction(() => !document.body.innerText.includes('Cargando pedidos'), null, { timeout: 20000 });
            await shot('24-despacho-pedidos', { fullPage: true });
            const leak = await pg.evaluate(() => /\$\s?\d|@[a-z0-9-]+\.[a-z]/i.test(document.querySelector('.dashboard-main').innerText));
            if (leak) console.warn(`! ${size.width}px dispatch list shows an email or a price`);
            await pg.locator('.admin-orders-table tbody tr').filter({ hasText: 'En preparación' }).first().getByRole('link', { name: /Ver detalle/ }).click();
            await pg.waitForSelector('.admin-fulfillment', { timeout: 20000 });
            const leakDetail = await pg.evaluate(() => /\$\s?\d|@[a-z0-9-]+\.[a-z]|HELENA10/i.test(document.querySelector('.dashboard-main').innerText));
            if (leakDetail) console.warn(`! ${size.width}px dispatch detail shows an email, a price or the coupon`);
            await shot('24-despacho-pedido-detalle', { fullPage: true });
            const inventory = await pg.goto(`${BASE}/admin/inventory`, { waitUntil: 'load' });
            if (inventory.status() !== 404) console.warn(`! ${size.width}px dispatch /admin/inventory answered ${inventory.status()}, expected 404`);
            await pg.goto(`${BASE}/admin/users`, { waitUntil: 'load' });
            await pg.waitForSelector('.admin-noaccess', { timeout: 20000 });
            await shot('26-sin-acceso');
        });
        await asRole('luis.mora', async (pg, shot) => {
            if (new URL(pg.url()).pathname !== '/admin/today') console.warn(`! ${size.width}px delivery landed on ${pg.url()}, not on Hoy`);
            await pg.waitForSelector('.today-section', { timeout: 20000 });
            const sections = await pg.locator('.today-section').count();
            if (sections !== 1) console.warn(`! ${size.width}px delivery sees ${sections} sections in Hoy`);
            await shot('25-hoy-repartidor', { fullPage: true });
            if (size.width < 768) { await pg.click('.dashboard-menu-button'); await pg.waitForSelector('.dashboard-sidebar.is-open'); }
            await shot('25-menu-repartidor');
            if (size.width < 768) await pg.keyboard.press('Escape');
            await pg.goto(`${BASE}/admin/orders`, { waitUntil: 'load' });
            await pg.waitForFunction(() => !document.body.innerText.includes('Cargando pedidos'), null, { timeout: 20000 });
            await shot('25-repartidor-pedidos', { fullPage: true });
            const redirect = await pg.goto(`${BASE}/admin/deliveries`, { waitUntil: 'load' });
            if (new URL(pg.url()).pathname !== '/admin/today') console.warn(`! ${size.width}px /admin/deliveries did not end on Hoy (${pg.url()}, ${redirect.status()})`);
        });
        if (adminErrors.length) console.warn(`! ${size.width}px admin page errors:`, adminErrors.join(' | '));
        await admin.close();
    }
}
await browser.close();
console.log(`Screenshots in ${OUT}/ (product used: ${product.slug}${promoted ? ', with promotion' : ''})`);
