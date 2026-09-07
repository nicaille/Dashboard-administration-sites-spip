/**
 * Déroule l'installeur web de SPIP en base SQLite.
 * Utilisé par executer.sh ; ne teste rien par lui-même.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';

const base = process.env.BASE_URL || 'http://127.0.0.1:8321';
const valeurs = {
	adresse_db: 'localhost', login_db: '', pass_db: '',
	nom: 'Administrateur', email: 'admin@exemple.test', login: 'admin',
	pass: 'motdepasse-de-test-1234', pass_verif: 'motdepasse-de-test-1234',
};

const nav = await chromium.launch();
const page = await nav.newPage();
await page.goto(base + '/ecrire/?exec=install&etape=1&chmod=511', { waitUntil: 'domcontentloaded' });

for (let etape = 1; etape <= 10; etape++) {
	const sqlite = page.locator('input[type=radio][name="server_db"][value="sqlite3"]');
	if (await sqlite.count()) { await sqlite.first().check(); }

	const choix = page.locator('input[type=radio][name="choix_db"]');
	const n = await choix.count();
	if (n) {
		let fait = false;
		for (let i = 0; i < n; i++) {
			const v = await choix.nth(i).getAttribute('value');
			if (v === '' || v === 'new' || v === null) { await choix.nth(i).check(); fait = true; break; }
		}
		if (!fait) { await choix.last().check(); }
	}

	for (const [nom, valeur] of Object.entries(valeurs)) {
		const champ = page.locator(`[name="${nom}"]`);
		if (await champ.count()) { await champ.first().fill(valeur).catch(() => {}); }
	}
	for (const nom of ['table_new', 'tprefix']) {
		const champ = page.locator(`[name="${nom}"]`);
		if (await champ.count()) { await champ.first().fill('spip').catch(() => {}); }
	}

	const texte = await page.locator('body').innerText();
	if (etape > 2 && /C.est termin/i.test(texte)) { console.log('installation terminée'); break; }

	const submit = page.locator('input[type=submit], button[type=submit]');
	if (!(await submit.count())) { break; }
	await Promise.all([page.waitForLoadState('domcontentloaded'), submit.last().click()]);
	await page.waitForTimeout(400);
}

await nav.close();
