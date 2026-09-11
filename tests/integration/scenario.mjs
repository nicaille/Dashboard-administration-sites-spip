/**
 * Parcours fonctionnel complet sur un SPIP réellement installé.
 *
 * Le tableau de bord et l'agent sont ici sur le même site : le dashboard
 * s'appaire avec l'agent local, ce qui exerce tout le protocole signé sans
 * dépendre d'un second hébergement.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';

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

/**
 * Traque ce qui ne devrait jamais atteindre l'écran : chaîne de langue brute,
 * bloc de squelette non compilé, nom multilingue, version normalisée par SVP,
 * extension PHP prise pour un plugin.
 */
async function artefacts(page) {
	const t = await page.locator('body').innerText();
	const trouves = [];
	const regles = [
		[/<:[a-z]+:[a-z0-9_]+:>/, 'chaîne de langue non interprétée'],
		[/\[\(#?[a-zA-Z0-9_|={}\s]*\)/, 'bloc de squelette non compilé'],
		[/\[[a-z]{2}\][A-ZÀ-Ÿa-z]/, 'nom multilingue non résolu'],
		[/\b0\d\d\.\d/, 'version normalisée par SVP'],
		[/\bPHP:[A-Z]/, 'extension PHP listée comme plugin'],
	];
	for (const [motif, libelle] of regles) {
		const m = t.match(motif);
		if (m) {
			trouves.push(libelle + ' (' + m[0].slice(0, 40) + ')');
		}
	}
	return trouves;
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

// L'agent de test est sur la boucle locale, donc en http : le tableau de bord
// refuse cette URL tant que l'exception n'est pas accordée. À poser avant la
// création, puisque c'est la saisie du formulaire qui est validée.
console.log('\n### Autorisation du http local');
await page.goto(base + '/ecrire/?exec=configurer_dashboard', { waitUntil: 'domcontentloaded' });
await page.check('[name="autoriser_http"]');
await page.locator('form input[type=submit]').first().click();
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(500);
dit('http local autorisé', (await page.locator('body').innerText()).includes('enregistrée'));

await page.goto(base + '/ecrire/?exec=dashboard_site&new=oui', { waitUntil: 'domcontentloaded' });

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

console.log('\n### Mise à jour d’un plugin');
await page.goto(base + '/ecrire/?exec=configurer_dashagent', { waitUntil: 'domcontentloaded' });
await page.check('[name="op_plugin_maj"]').catch(() => {});
await page.locator('form input[type=submit]').last().click();
await page.waitForTimeout(700);

await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'domcontentloaded' });
await bouton(page, 'Synchroniser');
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });

const ligneMaj = page.locator('tr', { hasText: 'ZZZTEST' });
dit('mise à jour proposée pour ZZZTEST', await ligneMaj.count() > 0);
const boutonMaj = ligneMaj.locator('a.dashboard-bouton').first();
if (await boutonMaj.count()) {
	await boutonMaj.click();
	await page.waitForLoadState('domcontentloaded').catch(() => {});
	await page.waitForTimeout(1000);
	dit('mise à jour sans erreur', (await erreurs(page)).length === 0, (await erreurs(page)).join(' ; '));

	// Une mise à jour de plugin est elle aussi un chantier : sauvegarde d'abord,
	// remplacement ensuite, inventaire pour finir.
	const issuePlugin = await attendreChantier(page, 120);
	dit('le chantier du plugin arrive à son terme', issuePlugin.fini, 'dernière étape vue : ' + issuePlugin.dernier);
	await page.waitForTimeout(1000);

	// Deux lignes au journal : le remplacement, qui nomme la transition, puis la
	// conclusion du chantier.
	const lignesPlugin = JSON.parse(sql(
		"SELECT message FROM spip_dashboard_journal WHERE operation = 'plugin_maj' ORDER BY id_dashboard_journal"
	)).map((l) => String(l.message));
	dit('version passée de 1.0.0 à 1.0.1', lignesPlugin.some((m) => /1\.0\.0\s*→\s*1\.0\.1/.test(m)),
		lignesPlugin.join(' | '));
} else {
	dit('bouton de mise à jour présent', false);
}

// Le remplacement des fichiers ne doit pas désactiver les plugins : « raz »
// sur une liste partielle couperait le dashboard et l'agent eux-mêmes.
const versionFichier = execFileSync('php', ['-r',
	`$x=@file_get_contents(getenv('SITE').'/plugins/zzztest/paquet.xml'); preg_match('/version="([^"]+)"/',(string)$x,$m); echo $m[1] ?? '?';`,
], { env: { ...process.env, SITE: site } }).toString();
dit('fichiers réellement remplacés sur le disque', versionFichier === '1.0.1', 'paquet.xml en ' + versionFichier);

const actifs = execFileSync('php', ['-r',
	`$db=new SQLite3(getenv('BDD'));$k=array_keys(unserialize($db->querySingle('SELECT valeur FROM spip_meta WHERE nom="plugin"')));echo implode(',',array_intersect(['DASHBOARD','DASHAGENT','ZZZTEST'],$k));`,
], { env: { ...process.env, BDD: bdd } }).toString();
dit('aucun plugin désactivé par la mise à jour', actifs.split(',').filter(Boolean).length === 3, actifs);

console.log('\n### Rendu des pages');
for (const [nom, url] of [
	['parc', '/ecrire/?exec=dashboard'],
	['fiche du site', '/ecrire/?exec=dashboard_site&id_dashboard_site=1'],
	['configuration', '/ecrire/?exec=configurer_dashboard'],
	['configuration de l’agent', '/ecrire/?exec=configurer_dashagent'],
]) {
	await page.goto(base + url, { waitUntil: 'domcontentloaded' });
	const trouves = await artefacts(page);
	dit(`${nom} : rien d’étranger à l’écran`, trouves.length === 0, trouves.join(' | '));
}

console.log('\n### Onglets « Plugins » et « PHP »');
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
const panneauPlugins = page.locator('#panneau-plugins');
const panneauPhp = page.locator('#panneau-php');
dit('deux onglets présents', (await page.locator('[data-dashboard-onglets] [role="tab"]').count()) === 2);
dit('« Plugins » ouvert par défaut', (await panneauPlugins.isVisible()) && !(await panneauPhp.isVisible()));

await page.locator('#onglet-php').click();
await page.waitForTimeout(200);
dit('le clic bascule sur « PHP »', !(await panneauPlugins.isVisible()) && (await panneauPhp.isVisible()));
dit('onglet « PHP » marqué sélectionné', (await page.locator('#onglet-php').getAttribute('aria-selected')) === 'true');

const nbPhp = await page.locator('#panneau-php tbody tr').count();
dit('extensions PHP listées', nbPhp > 0, nbPhp + ' ligne(s)');
const compteurPhp = (await page.locator('#onglet-php .dashboard-compteur').innerText()).trim();
dit('compteur cohérent avec le tableau', compteurPhp === String(nbPhp), compteurPhp + ' vs ' + nbPhp);

const origines = await page.locator('#panneau-php tbody tr td:last-child').evaluateAll(
	(l) => [...new Set(l.map((c) => c.innerText.replace(/\s+/g, ' ').trim()))]);
dit('chaque ligne porte une origine', origines.length > 0 && !origines.includes(''), origines.slice(0, 3).join(' / '));

// La flèche gauche revient sur l'onglet précédent : la navigation au clavier
// fait partie du contrat d'un jeu d'onglets.
await page.locator('#onglet-php').press('ArrowLeft');
await page.waitForTimeout(200);
dit('flèche gauche : retour à « Plugins »', (await panneauPlugins.isVisible()) && !(await panneauPhp.isVisible()));
dit('aucune extension PHP dans l’onglet « Plugins »',
	!/\bphp:/i.test(await panneauPlugins.innerText()));

/**
 * Une balise laissée non compilée arrive dans l'URL sous la forme %23NOM, et
 * l'opération reçoit un nom de balise en guise d'identifiant. Le symptôme est
 * muet : la page revient avec « Site inconnu ». Le piège se tend tout seul —
 * une balise à accolades imbriquée dans les arguments d'une autre suffit — donc
 * on inspecte toutes les URL d'action plutôt qu'un bouton à la fois.
 */
async function urlsDActions(page, nom, url) {
	await page.goto(base + url, { waitUntil: 'domcontentloaded' });
	const liens = await page.locator('a.dashboard-bouton').evaluateAll((l) => l.map((a) => a.getAttribute('href') || ''));
	const brutes = liens.filter((h) => /%23|#[A-Z_]{3,}/.test(h));
	dit(`${nom} : aucune balise non compilée dans les URL d’action`, brutes.length === 0,
		brutes.map((h) => (h.match(/%23[A-Za-z_:]+|#[A-Z_]{3,}/) || [''])[0]).join(', '));
	const args = liens.map((h) => decodeURIComponent((h.match(/[?&]arg=([^&]*)/) || ['', ''])[1]))
		.filter((a) => /^(core_maj|plugin_maj|plugin_maj_tous|sync|purger|sauvegarde)\b/.test(a));
	const malFormes = args.filter((a) => !/^[a-z_]+\/\d+(\/|$)/.test(a));
	dit(`${nom} : chaque URL d’action porte un identifiant numérique`, malFormes.length === 0, malFormes.join(', '));
	return liens;
}

/**
 * Attend qu'un chantier arrive à son terme.
 *
 * C'est le pilote JavaScript de la page qui le fait avancer, un aller-retour à
 * la fois. Après le remplacement du noyau, la page elle-même ne peut plus se
 * rendre — SPIP bloque son espace privé tant que le schéma n'est pas migré —
 * mais les actions, elles, continuent de passer : le chantier va au bout.
 */
async function attendreChantier(page, secondes = 180) {
	const limite = Date.now() + secondes * 1000;
	let dernier = '';
	while (Date.now() < limite) {
		if ((await page.locator('[data-dashboard-chantier]').count()) === 0) {
			return { fini: true, dernier };
		}
		dernier = (await page.locator('.dashboard-chantier-rang').innerText().catch(() => '')).trim();
		await page.waitForTimeout(500);
	}

	return { fini: false, dernier };
}

console.log('\n### URL des boutons d’action');
await urlsDActions(page, 'parc', '/ecrire/?exec=dashboard');
await urlsDActions(page, 'fiche du site', '/ecrire/?exec=dashboard_site&id_dashboard_site=1');

console.log('\n### Mise à jour du core SPIP');
const coreCible = process.env.CORE_CIBLE || '4.4.99';
const lu = (relatif) => { try { return readFileSync(`${site}/${relatif}`, 'utf8'); } catch { return null; } };

// Le dépôt d'archives de core est local et en http : le tableau de bord ne
// l'accepte qu'avec l'autorisation explicite déjà cochée, et l'agent qu'avec
// _DASHAGENT_ARCHIVES_HTTP dans son mes_options.php. Deux accords distincts.
// Sur le site géré, la mise à jour du core est refusée par défaut : c'est une
// case à cocher à part, et le test la coche comme le ferait l'administrateur.
await page.goto(base + '/ecrire/?exec=configurer_dashagent', { waitUntil: 'domcontentloaded' });
await page.check('[name="op_core_maj"]').catch(() => {});
await page.locator('form input[type=submit]').last().click();
await page.waitForTimeout(700);
dit('mise à jour du core autorisée sur le site géré',
	await page.locator('[name="op_core_maj"]').isChecked().catch(() => false));

await page.goto(base + '/ecrire/?exec=configurer_dashboard', { waitUntil: 'domcontentloaded' });
await page.fill('[name="url_archives_spip"]', base + '/core-archives/');
await page.fill('[name="versions_manuelles"]', `4.4 = ${coreCible}`);
// Zéro seconde de validité : une sauvegarde neuve est exigée, ce qui vérifie
// que la règle « une sauvegarde avant toute mise à jour » n'a pas d'échappatoire.
await page.fill('[name="fraicheur_sauvegarde"]', '0');
await page.locator('form input[type=submit]').first().click();
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(500);
dit('dépôt d’archives de core configuré', (await page.locator('body').innerText()).includes('enregistrée'));

// Le retard de core est décidé à la synchronisation : il faut la rejouer pour
// que la nouvelle version cible soit prise en compte.
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
await bouton(page, 'Synchroniser');
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
await urlsDActions(page, 'fiche du site, mise à jour du core proposée',
	'/ecrire/?exec=dashboard_site&id_dashboard_site=1');
const boutonCore = page.locator('a.dashboard-bouton-danger').first();
dit('mise à jour du core proposée', (await boutonCore.count()) > 0);

// État d'avant, pour prouver ensuite que ce sont bien les fichiers de l'archive
// qui sont arrivés, et que rien d'autre n'a bougé.
const gardes = ['config', 'IMG', 'local', 'squelettes', 'plugins'];
const avant = Object.fromEntries(gardes.map((g) => [g, lu(`${g}/temoin-dashboard.txt`)]));
dit('témoins en place avant la mise à jour', Object.values(avant).every((v) => v !== null),
	JSON.stringify(Object.entries(avant).filter(([, v]) => v === null).map(([k]) => k)));
dit('témoin du core absent avant', lu('ecrire/temoin-core.txt') === null);

const sauvegardesAvant = JSON.parse(sql('SELECT count(*) AS n FROM spip_dashboard_sauvegardes'))[0].n;

page.once('dialog', (d) => d.accept());
await boutonCore.click();
await page.waitForLoadState('domcontentloaded').catch(() => {});

// Le pilote de la page peut mener un chantier court en une poignée de secondes :
// l'encadré est relevé tout de suite, avant qu'il n'ait eu le temps de partir.
const encadreVu = (await page.locator('[data-dashboard-chantier]').count()) > 0;
await page.waitForTimeout(1500);

dit('lancement sans erreur', (await erreurs(page)).length === 0, (await erreurs(page)).join(' ; '));

const retour = decodeURIComponent((page.url().match(/dashboard_message=([^&]*)/) || ['', ''])[1]).replace(/\+/g, ' ');
const statut = (page.url().match(/dashboard_statut=(\w+)/) || ['', ''])[1];
dit('l’opération démarre sans refus', statut === 'ok', statut + ' : ' + retour);
dit('la première étape est une sauvegarde', /[Ss]auvegarde/.test(retour), retour);
dit('un encadré de chantier est affiché', encadreVu);

// Le pilote de la page mène l'opération à son terme, étape par étape.
const issue = await attendreChantier(page);
dit('le chantier arrive à son terme', issue.fini, 'dernière étape vue : ' + issue.dernier);
await page.waitForTimeout(1500);

const chantier = JSON.parse(sql(
	"SELECT statut, etape, tentatives, message FROM spip_dashboard_chantiers WHERE operation = 'core_maj' ORDER BY id_dashboard_chantier DESC LIMIT 1"
))[0] || {};
dit('le chantier est enregistré comme réussi', chantier.statut === 'ok', JSON.stringify(chantier));
// Le journal porte deux lignes pour l'opération : le remplacement lui-même, qui
// nomme la transition, et la conclusion du chantier.
const lignesCore = JSON.parse(sql(
	"SELECT message FROM spip_dashboard_journal WHERE operation = 'core_maj' ORDER BY id_dashboard_journal"
)).map((l) => String(l.message));
dit(`le journal porte la transition 4.4.23 → ${coreCible}`,
	lignesCore.some((m) => new RegExp(`4\\.4\\.23\\s*→\\s*${coreCible.replace(/\./g, '\\.')}`).test(m)),
	lignesCore.join(' | '));
dit('le journal porte la conclusion du chantier',
	lignesCore.some((m) => /terminé/.test(m)), lignesCore.join(' | '));

const sauvegardesApres = JSON.parse(sql('SELECT count(*) AS n FROM spip_dashboard_sauvegardes'))[0].n;
dit('une sauvegarde neuve a précédé la mise à jour', Number(sauvegardesApres) > Number(sauvegardesAvant),
	sauvegardesAvant + ' → ' + sauvegardesApres);

dit('fichiers de l’archive réellement déployés', (lu('ecrire/temoin-core.txt') || '').includes(coreCible),
	String(lu('ecrire/temoin-core.txt')));
dit('version de branche remplacée sur le disque',
	new RegExp(`spip_version_branche\\s*=\\s*['"]${coreCible.replace(/\./g, '\\.')}`).test(lu('ecrire/inc_version.php') || ''));

const rollback = readdirSync(site).filter((n) => /^ecrire\.dashagent-\d{14}$/.test(n));
dit('ancien core conservé pour rollback', rollback.length === 1, rollback.join(', '));

for (const g of gardes) {
	dit(`${g}/ préservé`, lu(`${g}/temoin-dashboard.txt`) === avant[g]);
}
dit('plugin mis à jour non écrasé', (lu('plugins/zzztest/paquet.xml') || '').includes('1.0.1'));
dit('secret de l’agent préservé', (lu('config/mes_options.php') || '').includes('_DASHAGENT_ARCHIVES_HTTP'));

// Le site tourne désormais sur les fichiers déployés : s'il ne répondait plus,
// la mise à jour aurait « réussi » en cassant le site.
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
dit('l’espace privé répond encore après le remplacement', (await erreurs(page)).length === 0,
	(await erreurs(page)).join(' ; '));
// « la page contient 4.4.99 » ne prouverait rien : le badge « 4.4.23 → 4.4.99 »
// l'affiche aussi quand rien ne s'est passé. C'est la version enregistrée qui
// compte, et la disparition de la proposition de mise à jour.
const apres = JSON.parse(sql('SELECT version_spip, core_maj, base_maj FROM spip_dashboard_sites WHERE id_dashboard_site = 1'))[0];
dit('version du site mise à jour dans le parc', apres.version_spip === coreCible, JSON.stringify(apres));
dit('plus de retard de core signalé', apres.core_maj === 'non', apres.core_maj);

console.log('\n### Migration du schéma de base');
const baseCible = process.env.BASE_CIBLE || '2026090100';

// Remplacer les fichiers du noyau ne suffit pas : l'archive annonce un schéma
// plus récent, et SPIP bloque l'espace privé du site tant qu'il n'est pas migré.
// C'est l'étape « base » du chantier qui l'a joué, sans intervention humaine.
dit('la version de schéma attendue a bien changé',
	new RegExp(`spip_version_base\\s*=\\s*${baseCible}`).test(lu('ecrire/inc_version.php') || ''));
dit('le schéma enregistré en base a suivi',
	JSON.parse(sql("SELECT valeur FROM spip_meta WHERE nom = 'version_installee'"))[0]?.valeur === baseCible,
	JSON.parse(sql("SELECT valeur FROM spip_meta WHERE nom = 'version_installee'"))[0]?.valeur);

// Le palier ajouté à l'archive crée une colonne : c'est la preuve que la
// migration a réellement joué, et pas seulement écrit un numéro de version.
const colonnes = JSON.parse(sql("SELECT name FROM pragma_table_info('spip_jobs')")).map((c) => c.name);
dit('le palier de migration a bien été appliqué', colonnes.includes('temoin_dashboard'), colonnes.join(', '));

dit('plus de migration de base en attente', apres.base_maj === 'non', apres.base_maj);
dit('l’espace privé du site n’est plus bloqué',
	!/procédure de mise à jour doit être lancée/.test(await page.locator('body').innerText()));
dit('plus de mise à jour de core proposée',
	(await page.locator('a.dashboard-bouton-danger').count()) === 0);

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
