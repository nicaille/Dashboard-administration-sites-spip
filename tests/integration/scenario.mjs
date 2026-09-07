/**
 * Parcours fonctionnel complet sur un SPIP réellement installé.
 *
 * Le tableau de bord et l'agent sont ici sur le même site : le dashboard
 * s'appaire avec l'agent local, ce qui exerce tout le protocole signé sans
 * dépendre d'un second hébergement.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';

const base = process.env.BASE_URL || 'http://127.0.0.1:8321';
const site = process.env.SITE_DIR;
const bdd = `${site}/config/bases/spip.sqlite`;

let echecs = 0;
const dit = (titre, ok, detail = '') => {
	if (!ok) { echecs++; }
	console.log(`  ${ok ? 'ok   ' : 'ÉCHEC'} ${titre}${detail ? ' — ' + detail : ''}`);
};

/** Interroge la base du site installé. */
const sql = (requete) => execFileSync('php', ['-r',
	`$db=new SQLite3(getenv("BDD"));$r=$db->query(getenv("REQ"));$o=[];while($x=$r->fetchArray(SQLITE3_ASSOC))$o[]=$x;echo json_encode($o);`,
], { env: { ...process.env, BDD: bdd, REQ: requete } }).toString();

/** Une page SPIP en échec affiche une trace PHP ou un bloc d'erreur de squelette. */
async function erreurs(page) {
	const t = await page.locator('body').innerText();
	const pb = [];
	const php = t.match(/(Fatal error|Uncaught|Parse error|Warning:|Notice:)[^\n]{0,200}/);
	if (php) { pb.push('PHP : ' + php[0]); }
	const spip = t.match(/(Erreur SQL|Table SQL[^\n]{0,80}inconnue|Erreur\(s\) dans le squelette)[^\n]{0,160}/i);
	if (spip) { pb.push('SPIP : ' + spip[0]); }
	return pb;
}

async function ouvrir(page, titre, url) {
	await page.goto(base + url, { waitUntil: 'domcontentloaded' });
	const pb = await erreurs(page);
	dit(titre, pb.length === 0, pb.join(' ; '));
	return page.locator('body').innerText();
}

/** Suit un bouton du plugin (les libellés du menu de SPIP se ressemblent). */
async function bouton(page, libelle) {
	const lien = page.locator('a.dashboard-bouton', { hasText: libelle }).first();
	if (!(await lien.count())) { dit(`bouton « ${libelle} »`, false, 'introuvable'); return ''; }
	// Pas de Promise.all avec la navigation : si le serveur tarde, l'attente
	// conjointe échoue au lieu de laisser l'opération se terminer.
	await lien.click();
	await page.waitForLoadState('domcontentloaded').catch(() => {});
	await page.waitForTimeout(2500);
	const pb = await erreurs(page);
	dit(`opération « ${libelle} »`, pb.length === 0, pb.join(' ; '));
	return page.locator('body').innerText();
}

const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1500, height: 3000 } })).newPage();
page.on('pageerror', (e) => dit('erreur JavaScript', false, e.message));

console.log('\n### Connexion');
await page.goto(base + '/spip.php?page=login', { waitUntil: 'domcontentloaded' });
await page.fill('input[name="var_login"]', 'admin');
await page.fill('input[name="password"]', 'motdepasse-de-test-1234');
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('input[type=submit],button[type=submit]').last().click()]);
await page.waitForTimeout(400);
dit('espace privé accessible', page.url().includes('exec='), page.url());

console.log('\n### Tables installées');
const tables = JSON.parse(sql("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%dash%' ORDER BY name")).map((t) => t.name);
for (const attendue of ['spip_dashagent_journal', 'spip_dashagent_nonces', 'spip_dashboard_journal',
	'spip_dashboard_plugins', 'spip_dashboard_sauvegardes', 'spip_dashboard_sites']) {
	dit(attendue, tables.includes(attendue));
}

console.log('\n### Pages de l’espace privé');
await ouvrir(page, 'vue du parc', '/ecrire/?exec=dashboard');
await ouvrir(page, 'configuration du tableau de bord', '/ecrire/?exec=configurer_dashboard');
await ouvrir(page, 'configuration de l’agent', '/ecrire/?exec=configurer_dashagent');
await ouvrir(page, 'formulaire de création', '/ecrire/?exec=dashboard_site&new=oui');

console.log('\n### Création d’un site');
await page.fill('[name="titre"]', 'Site de test');
await page.fill('[name="url_site"]', base + '/');
await page.fill('[name="url_agent"]', base + '/spip.php?action=dashagent');
await page.check('[name="generer_secret"]').catch(() => {});
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').last().click()]);
await page.waitForTimeout(700);
dit('enregistrement sans erreur', (await erreurs(page)).length === 0, (await erreurs(page)).join(' ; '));
const sites = JSON.parse(sql('SELECT titre, statut, substr(secret,1,3) AS prefixe FROM spip_dashboard_sites'));
dit('site enregistré en base', sites.length === 1, JSON.stringify(sites));
dit('secret chiffré, jamais en clair', sites[0]?.prefixe === 'c2:', 'préfixe ' + (sites[0]?.prefixe || '?'));

console.log('\n### Appairage avec l’agent local');
await page.goto(base + '/ecrire/?exec=configurer_dashagent', { waitUntil: 'domcontentloaded' });
await page.check('[name="generer"]');
for (const op of ['op_infos', 'op_purger', 'op_sauvegarde']) { await page.check(`[name="${op}"]`).catch(() => {}); }
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').last().click()]);
await page.waitForTimeout(500);
const secret = ((await page.locator('body').innerText()).match(/([a-f0-9]{64})/) || [])[1];
dit('secret de l’agent généré', !!secret);

await page.goto(base + '/ecrire/?exec=configurer_dashboard', { waitUntil: 'domcontentloaded' });
await page.check('[name="autoriser_http"]');
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').first().click()]);
await page.waitForTimeout(400);

await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1&modifier=oui', { waitUntil: 'domcontentloaded' });
await page.fill('[name="secret_clair"]', secret || '');
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').last().click()]);
await page.waitForTimeout(500);
dit('fiche appairée', (await erreurs(page)).length === 0);

console.log('\n### Synchronisation signée');
await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'domcontentloaded' });
await bouton(page, 'Synchroniser');
const etat = JSON.parse(sql('SELECT etat, version_spip, php_version, nb_plugins, substr(erreur,1,60) AS erreur FROM spip_dashboard_sites'))[0] || {};
dit('site joignable', etat.etat === 'ok', etat.erreur || '');
dit('version du core remontée', /^\d+\.\d+\.\d+/.test(etat.version_spip || ''), etat.version_spip);
dit('inventaire des plugins remonté', Number(etat.nb_plugins) > 0, etat.nb_plugins + ' plugins');

console.log('\n### Opérations à distance');
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
const apresPurge = await bouton(page, 'Vider');
dit('caches réellement vidés', /Caches vidés\s*:\s*\d+/.test(apresPurge), (apresPurge.match(/Caches vidés[^\n]{0,40}/) || [''])[0]);

await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
const apresSauvegarde = await bouton(page, 'Sauvegarder la base');
dit('sauvegarde créée et rapatriée', /rapatri/i.test(apresSauvegarde), (apresSauvegarde.match(/Sauvegarde[^\n]{0,80}/) || [''])[0]);

const sauvegardes = JSON.parse(sql('SELECT fichier, octets, statut FROM spip_dashboard_sauvegardes'));
dit('sauvegarde enregistrée localement', sauvegardes.some((s) => s.statut === 'locale' && Number(s.octets) > 0), JSON.stringify(sauvegardes));

console.log('\n### Restauration du dump');
try {
	const verdict = execFileSync('php', ['-r', `
		$dir = getenv('SITE') . '/tmp/dashboard/sauvegardes';
		$f = null;
		foreach (glob($dir . '/*/*.sql.gz') ?: [] as $c) { $f = $c; }
		if (!$f) { echo 'AUCUN_FICHIER'; exit; }
		$sql = gzdecode(file_get_contents($f));
		$cible = getenv('TRAVAIL') . '/restauration.sqlite';
		@unlink($cible);
		$db = new SQLite3($cible);
		if (!$db->exec($sql)) { echo 'ERREUR:' . $db->lastErrorMsg(); exit; }
		$n = $db->querySingle('SELECT COUNT(*) FROM sqlite_master WHERE type="table"');
		echo 'OK:' . $n;
	`], { env: { ...process.env, SITE: site } }).toString();
	dit('dump rejouable dans une base neuve', verdict.startsWith('OK:'), verdict);
} catch (e) {
	dit('dump rejouable dans une base neuve', false, e.message.slice(0, 120));
}

console.log('\n' + (echecs ? `=== ${echecs} ÉCHEC(S) ===` : '=== PARCOURS COMPLET SANS ERREUR ==='));
await nav.close();
process.exit(echecs ? 1 : 0);
