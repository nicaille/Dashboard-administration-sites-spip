/**
 * Parcours fonctionnel complet sur un SPIP réellement installé.
 *
 * Le tableau de bord et l'agent sont ici sur le même site : le dashboard
 * s'appaire avec l'agent local, ce qui exerce tout le protocole signé sans
 * dépendre d'un second hébergement.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync, writeFileSync, existsSync } from 'node:fs';

const base = process.env.BASE_URL || 'http://127.0.0.1:8321';
const site = process.env.SITE_DIR;
const bdd = `${site}/config/bases/spip.sqlite`;
const lu = (relatif) => { try { return readFileSync(`${site}/${relatif}`, 'utf8'); } catch { return null; } };

let echecs = 0;
const dit = (titre, ok, detail = '') => {
	if (!ok) { echecs++; }
	console.log(`  ${ok ? 'ok   ' : 'ÉCHEC'} ${titre}${detail ? ' — ' + detail : ''}`);
};

/** Interroge la base du site installé. */
const sql = (requete) => execFileSync('php', ['-r',
	`$db=new SQLite3(getenv("BDD"));$r=$db->query(getenv("REQ"));$o=[];while($x=$r->fetchArray(SQLITE3_ASSOC))$o[]=$x;echo json_encode($o);`,
], { env: { ...process.env, BDD: bdd, REQ: requete } }).toString();

/** Écrire dans la base du site, pour poser un état que le parcours ne produit pas. */
const sqlEcrire = (requete) => execFileSync('php', ['-r',
	`$db=new SQLite3(getenv("BDD"));$db->exec(getenv("REQ"));`,
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

/**
 * Déclenche un bouton du plugin (les libellés du menu de SPIP se ressemblent).
 *
 * Les actions sont des formulaires POST — le balisage de #BOUTON_ACTION — et
 * non plus des liens : une action qui change l'état d'un site ne se déclenche
 * pas en suivant un lien.
 */
async function bouton(page, libelle) {
	const lien = page.locator('form.bouton_action_post button', { hasText: libelle }).first();
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

// Le site géré doit voir ce qui a été pris chez lui. Ces fichiers vivent sous
// tmp/, hors espace web : sans ce tableau, son webmestre pouvait refuser qu'on
// en produise, mais pas constater leur existence ni s'en défaire.
const dossierSauvegardes = site + '/tmp/dashagent/sauvegardes';
const surDisque = () => readdirSync(dossierSauvegardes).filter((n) => /\.sql\.gz$/.test(n));

await page.goto(base + '/ecrire/?exec=configurer_dashagent', { waitUntil: 'domcontentloaded' });
const tableauSauvegardes = page.locator('#sauvegardes');
dit('le site géré liste ses sauvegardes',
	(await tableauSauvegardes.locator('tbody tr').count()) === surDisque().length
		&& surDisque().length > 0,
	(await tableauSauvegardes.locator('tbody tr').count()) + ' ligne(s) pour ' + surDisque().length + ' fichier(s)');
dit('la légende donne le poids total',
	/[0-9].*(o|io)\b/.test(await tableauSauvegardes.locator('caption').innerText()),
	(await tableauSauvegardes.locator('caption').innerText()).replace(/\s+/g, ' ').trim());
dit('aucune balise ne ressort en clair sur la page de l’agent',
	!/#[A-Z_]{3,}|URL_ACTION_AUTEUR/.test(await page.locator('body').innerText()));

// La suppression : une action POST signée, pas un lien.
const supprimer = tableauSauvegardes.locator('form.bouton_action_post button').first();
dit('la suppression est un bouton d’action du thème',
	/\bbtn\b/.test((await supprimer.getAttribute('class')) || '')
		&& /\bbtn_danger\b/.test((await supprimer.getAttribute('class')) || ''),
	(await supprimer.getAttribute('class')) || '');
const avantSuppression = surDisque().length;
// `once` et non `on` : un gestionnaire permanent happerait la confirmation de
// la mise à jour du core, plus loin, qui a déjà le sien — et Playwright refuse
// qu'un même dialogue soit accepté deux fois.
page.once('dialog', (d) => d.accept());
await supprimer.click();
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(1200);
dit('le fichier est réellement supprimé du site',
	surDisque().length === avantSuppression - 1,
	avantSuppression + ' → ' + surDisque().length);
dit('la suppression rend la main sous son encadré',
	page.url().includes('#sauvegardes'), page.url());
dit('la suppression est annoncée',
	/supprim/i.test(await page.locator('.reponse_formulaire').last().innerText()),
	(await page.locator('.reponse_formulaire').last().innerText()).trim());
dit('la suppression est inscrite au journal du site',
	/sauvegarde_supprimer/.test(await page.locator('body').innerText()));

console.log('\n### Une sauvegarde qui se découpe en tranches');

/*
 * L'export se fait par tranches bornées en temps, pour passer sous la patience
 * d'un cache en frontal. Sur une base de test, la première tranche suffirait —
 * on ramène donc le budget à zéro le temps de ce passage, ce qui force une
 * tranche par lot de lignes et exerce pour de bon la reprise : troncature au
 * dernier point de contrôle, structure non réécrite, rang repris là où il en
 * était.
 */
const optionsChemin = site + '/config/mes_options.php';
const optionsAvant = readFileSync(optionsChemin, 'utf8');
writeFileSync(optionsChemin, optionsAvant + "\ndefine('_DASHAGENT_SAUVEGARDE_BUDGET', 0);\n");
/* Le serveur intégré tourne sous le SAPI « cli-server », pour lequel opcache
   est gouverné par `opcache.enable` — et non par `opcache.enable_cli`, qu'on
   croit seul en cause. Un fichier réécrit peut donc rester invisible le temps
   de `opcache.revalidate_freq`, deux secondes par défaut : le parcours ne
   voyait alors aucun découpage, sans que rien ne désigne le coupable. Le
   lanceur met ce délai à zéro ; ce battement couvre le serveur lancé à la
   main. */
await page.waitForTimeout(2500);

await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
const apresDecoupee = await bouton(page, 'Sauvegarder la base');
// Le libellé du bouton commence lui aussi par « Sauvegarde… » : c'est la ligne
// du compte rendu qu'on veut, celle qui porte le poids entre parenthèses.
const compteRendu = (apresDecoupee.match(/Sauvegarde (?:créée|retrouvée)[^\n]{0,160}/) || [''])[0];
dit('un export découpé aboutit et se rapatrie', /rapatri/i.test(apresDecoupee), compteRendu);
dit('et il a réellement pris plusieurs tranches',
	/\b([2-9]|\d{2,}) tranches/.test(apresDecoupee), compteRendu || apresDecoupee.slice(0, 160));

writeFileSync(optionsChemin, optionsAvant);
await page.waitForTimeout(2500);

/*
 * Le fichier produit porte un membre gzip par tranche. Le contrôle au
 * rapatriement l'a déjà accepté — c'est ce que dit « rapatri » ci-dessus ; on
 * le relit ici d'une autre main, pour voir les membres et retrouver la marque
 * de fin. Piège à retenir : `gzdecode()` ne rend que le **premier** membre.
 */
try {
	const verdict = execFileSync('php', ['-r', `
		$dir = getenv('SITE') . '/tmp/dashboard/sauvegardes';
		$f = null;
		foreach (glob($dir . '/*/*.sql.gz') ?: [] as $c) { $f = $c; }
		if (!$f) { echo 'AUCUN_FICHIER'; exit; }
		$brut = file_get_contents($f);
		$sql = ''; $depart = 0; $membres = 0;
		while ($depart < strlen($brut)) {
			$ctx = inflate_init(ZLIB_ENCODING_GZIP);
			$morceau = @inflate_add($ctx, substr($brut, $depart));
			if ($morceau === false) { echo 'REFUS_ZLIB'; exit; }
			$sql .= $morceau;
			$lu = (int) inflate_get_read_len($ctx);
			if ($lu <= 0) { echo 'MEMBRE_VIDE'; exit; }
			$depart += $lu; $membres++;
		}
		if (strpos($sql, '-- fin de sauvegarde') === false) { echo 'SANS_MARQUE:' . $membres; exit; }
		if (strlen($sql) <= strlen((string) gzdecode($brut))) { echo 'UN_SEUL_MEMBRE:' . $membres; exit; }
		echo 'OK:' . $membres . ' membres, ' . strlen($sql) . ' octets';
	`], { env: { ...process.env, SITE: site } }).toString();
	dit('l’archive rapatriée porte plusieurs membres et se relit en entier',
		verdict.startsWith('OK:'), verdict);
} catch (e) {
	dit('l’archive rapatriée porte plusieurs membres et se relit en entier', false, e.message.slice(0, 160));
}

console.log('\n### Mise à jour d’un plugin');
await page.goto(base + '/ecrire/?exec=configurer_dashagent', { waitUntil: 'domcontentloaded' });
await page.check('[name="op_plugin_maj"]').catch(() => {});
await page.locator('form input[type=submit]').last().click();
await page.waitForTimeout(700);

await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'domcontentloaded' });
await bouton(page, 'Synchroniser');

console.log('\n### Vue d’ensemble : relire les dépôts du parc');

// Pour voir un catalogue périmé il faut en avoir un : on coupe le
// rafraîchissement automatique, on vieillit le dépôt de trois jours, puis on
// relève l'inventaire — qui n'y touchera donc pas. Couper le rafraîchissement
// ne fait pas taire l'avertissement : c'est le cas où plus rien ne rajeunit le
// catalogue, et l'âge s'apprécie alors sur la validité par défaut d'un jour.
await page.goto(base + '/ecrire/?exec=configurer_dashboard', { waitUntil: 'domcontentloaded' });
await page.fill('[name="fraicheur_depots"]', '0');
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').last().click()]);
await page.waitForTimeout(500);
sqlEcrire("UPDATE spip_depots SET maj = datetime('now', '-3 days')");
await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'domcontentloaded' });
await bouton(page, 'Synchroniser');

const fraicheur = page.locator('#depots');
dit('la fraîcheur des catalogues est affichée sur le parc',
	/3 jour/.test(await fraicheur.innerText()), (await fraicheur.innerText()).replace(/\s+/g, ' ').trim());
dit('un catalogue périmé est signalé',
	(await page.locator('#depots.dashboard-depots-perimes').count()) === 1);
dit('le compte du parc est celui des sites',
	(await page.locator('.dashboard-synthese li').last().innerText()).trim().startsWith(
		String(JSON.parse(sql('SELECT SUM(nb_plugins_maj) AS n FROM spip_dashboard_sites'))[0].n)),
	(await page.locator('.dashboard-synthese li').last().innerText()).replace(/\s+/g, ' ').trim());

// Le tour du parc, un site à la fois : c'est ce qui rend le compte réel.
const relire = page.locator('[data-parc-action="depots"]');
dit('un bouton relit les dépôts de tout le parc', (await relire.count()) === 1);
await relire.click();
await page.waitForFunction(() => !document.querySelector('[data-parc-action="depots"]')
	|| !document.querySelector('.dashboard-depots-perimes'), null, { timeout: 60000 }).catch(() => {});
await page.waitForTimeout(2500);

const relu = JSON.parse(sql("SELECT maj FROM spip_depots ORDER BY maj DESC LIMIT 1"))[0].maj;
dit('le catalogue a bien été relu', (Date.now() - Date.parse(relu.replace(' ', 'T'))) < 10 * 60 * 1000, relu);
await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'domcontentloaded' });
dit('plus de catalogue périmé après le tour du parc',
	(await page.locator('#depots.dashboard-depots-perimes').count()) === 0,
	(await page.locator('#depots').innerText()).replace(/\s+/g, ' ').trim());
// Un second tour, sur un catalogue qui n'a pas changé depuis le premier. C'est
// le cas qui figeait le parc : SVP réécrit alors le même `sha_paquets` en
// comptant sur ON UPDATE CURRENT_TIMESTAMP pour dater le dépôt, ce que MySQL ne
// fait pas quand l'UPDATE n'altère rien. Le dépôt restait « à relire », et le
// navigateur redemandait le même site sans fin.
//
// Le dépôt est vieilli d'abord : sans cela il a moins d'une minute et le
// plancher de fraîcheur le déclare à jour, si bien que rien ne serait relu.
// Ce banc tourne sur SQLite, où SPIP date la ligne de lui-même : le test
// n'oppose donc pas les deux moteurs, il vérifie l'invariant qui compte —
// après un tour, le dépôt est daté, et le tour s'achève.
sqlEcrire("UPDATE spip_depots SET maj = datetime('now', '-2 days')");
await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'domcontentloaded' });
const avantSecondTour = JSON.parse(sql('SELECT maj FROM spip_depots ORDER BY id_depot LIMIT 1'))[0].maj;
await page.locator('[data-parc-action="depots"]').click();
await page.waitForFunction(() => /Termin|suivant/.test(
	(document.querySelector('.dashboard-depots-avancement') || {}).textContent || ''), null, { timeout: 60000 }).catch(() => {});
await page.waitForTimeout(2500);
const apresSecondTour = JSON.parse(sql('SELECT maj FROM spip_depots ORDER BY id_depot LIMIT 1'))[0].maj;
dit('un catalogue inchangé est daté quand même',
	apresSecondTour !== avantSecondTour, avantSecondTour + ' → ' + apresSecondTour);
dit('le dépôt n’est plus annoncé à relire',
	(await page.locator('#depots.dashboard-depots-perimes').count()) === 0,
	(await page.locator('#depots').innerText()).replace(/\s+/g, ' ').trim());

dit('le compte du parc reste celui des sites',
	(await page.locator('.dashboard-synthese li').last().innerText()).trim().startsWith(
		String(JSON.parse(sql('SELECT SUM(nb_plugins_maj) AS n FROM spip_dashboard_sites'))[0].n)),
	(await page.locator('.dashboard-synthese li').last().innerText()).replace(/\s+/g, ' ').trim());

// Le badge « N à mettre à jour » du tableau du parc nomme au survol les plugins
// concernés : le décompte seul obligeait à ouvrir la fiche du site pour savoir
// lesquels. Vérifié ici, tant que zzztest attend encore sa 1.0.1.
const badgeMaj = page.locator('td .dashboard-survol').first();
dit('le badge des mises à jour est présent dans le tableau du parc', (await badgeMaj.count()) === 1);

if (await badgeMaj.count()) {
	const liste = badgeMaj.locator('.dashboard-survol-liste');

	// Fermée au repos : une liste toujours visible encombrerait le tableau.
	dit('la liste des plugins est masquée au repos', !(await liste.isVisible()));

	await badgeMaj.hover();
	const nomme = (await liste.innerText()).trim();
	dit('le survol du badge nomme les plugins à mettre à jour',
		/Plugin de test/.test(nomme), nomme.replace(/\s+/g, ' ').slice(0, 80));

	// Un nom par ligne : autant de <br /> que d'intervalles entre les noms.
	const sauts = await liste.locator('br').count();
	const noms = nomme.split('\n').map((l) => l.trim()).filter(Boolean).length;
	dit('un plugin par ligne', sauts === Math.max(0, noms - 1), `${noms} nom(s), ${sauts} saut(s)`);

	// Le clavier ouvre la même liste : un survol à la souris n'est pas une
	// interface. La souris est écartée d'abord, sans quoi le survol masquerait
	// le résultat du focus.
	await page.mouse.move(0, 0);
	dit('la liste se referme quand la souris s’éloigne', !(await liste.isVisible()));
	await badgeMaj.focus();
	dit('le focus clavier ouvre la même liste', await liste.isVisible());

	// Le badge et sa liste se répondent, pour un lecteur d'écran.
	const decrit = await badgeMaj.getAttribute('aria-describedby');
	dit('le badge désigne sa liste', !!decrit && decrit === (await liste.getAttribute('id')), decrit || 'aucun');

	// Ce qui vient d'un site géré reste inerte, ici comme ailleurs.
	dit('la liste survolée ne porte aucun balisage venu du site',
		!/<(svg|script|img|details|iframe)/i.test(await liste.innerHTML()));

	/* Avec un seul plugin en attente, le séparateur n'est jamais exercé : le
	   test passerait aussi bien si le <br /> était mal placé. On en ajoute donc
	   un second, le temps de la vérification, avec un nom qui prouve au passage
	   que rien de ce qui vient du site ne s'exécute. */
	sqlEcrire("INSERT INTO spip_dashboard_plugins"
		+ " (id_dashboard_site, prefixe, nom, version, version_disponible, maj_disponible, distribue)"
		+ " VALUES (1, 'ZZZDEUX', 'Deuxieme <svg onload=alert(1)> plugin', '1.0.0', '2.0.0', 'oui', 'non')");
	await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'domcontentloaded' });

	const badgeDeux = page.locator('td .dashboard-survol').first();
	const listeDeux = badgeDeux.locator('.dashboard-survol-liste');
	await badgeDeux.hover();
	const deuxNoms = (await listeDeux.innerText()).trim().split('\n').map((l) => l.trim()).filter(Boolean);

	dit('deux plugins en attente donnent deux noms',
		deuxNoms.length === 2, deuxNoms.join(' | '));
	dit('un saut de ligne sépare les deux noms, sans en ajouter à la fin',
		(await listeDeux.locator('br').count()) === 1,
		String(await listeDeux.locator('br').count()));
	/* Le tri est bien celui des noms. Le premier n'est pas comparé par son début :
	   SPIP préfixe de son marqueur « ⚠️ » un texte qu'il juge dangereux, ce que
	   fait ici le nom hostile injecté exprès. */
	dit('les noms sont triés',
		/Deuxieme/.test(deuxNoms[0]) && /^Plugin de test$/.test(deuxNoms[1]), deuxNoms.join(' | '));
	dit('un nom de plugin hostile reste inerte dans la liste',
		!/<svg/i.test(await listeDeux.innerHTML()) && /onload/.test(await listeDeux.innerText()),
		(await listeDeux.innerHTML()).replace(/\s+/g, ' ').slice(0, 120));

	sqlEcrire("DELETE FROM spip_dashboard_plugins WHERE prefixe = 'ZZZDEUX'");
	await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'domcontentloaded' });
}

// Rendre au parc son rafraîchissement automatique pour la suite du parcours.
await page.goto(base + '/ecrire/?exec=configurer_dashboard', { waitUntil: 'domcontentloaded' });
await page.fill('[name="fraicheur_depots"]', '86400');
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').last().click()]);
await page.waitForTimeout(500);

await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });

const ligneMaj = page.locator('tr', { hasText: 'ZZZTEST' });
dit('mise à jour proposée pour ZZZTEST', await ligneMaj.count() > 0);

// L'onglet porte deux décomptes tant qu'un plugin est en retard : le total, et
// le nombre à mettre à jour — la seule information de cet onglet qui appelle un
// geste, d'où les couleurs de l'alerte.
const compteurs = page.locator('#onglet-plugins .dashboard-compteur');
dit('l’onglet « Plugins » porte deux décomptes', (await compteurs.count()) === 2,
	(await compteurs.allInnerTexts()).map((t) => t.trim()).join(' | '));
const enRetard = page.locator('#onglet-plugins .dashboard-compteur-maj');
const surlignees = await page.locator('#panneau-plugins tr.dashboard-ligne-maj').count();
dit('le décompte des mises à jour est celui de la liste',
	(await enRetard.innerText()).trim() === String(surlignees),
	(await enRetard.innerText()).trim() + ' en pastille, ' + surlignees + ' lignes surlignées');
dit('la pastille des mises à jour s’explique au survol',
	/mettre à jour/.test((await enRetard.getAttribute('title')) || ''),
	(await enRetard.getAttribute('title')) || '');

// La pastille compte ce qu'on peut faire, pas ce qui existe : un plugin livré
// avec SPIP suit le core, il n'a pas de bouton et « Tout mettre à jour » ne le
// prend pas. L'y compter laisserait la pastille allumée après une mise à jour
// réussie, sans rien pour l'éteindre.
const realisables = JSON.parse(sql(
	"SELECT COUNT(*) AS n FROM spip_dashboard_plugins WHERE maj_disponible = 'oui' AND distribue = 'non'"))[0].n;
const enregistre = JSON.parse(sql(
	'SELECT nb_plugins_maj AS n FROM spip_dashboard_sites WHERE id_dashboard_site = 1'))[0].n;
dit('le décompte enregistré est celui des mises à jour réalisables',
	String(enregistre) === String(realisables),
	enregistre + ' enregistré(s) pour ' + realisables + ' réalisable(s)');

// La couleur est le message : vérifier la classe ne prouverait rien, le thème
// du privé ayant déjà donné du blanc sur blanc à des boutons bien classés.
const teinte = await enRetard.evaluate((n) => {
	const s = getComputedStyle(n);
	return { fond: s.backgroundColor, texte: s.color };
});
dit('la pastille est jaune et son texte rouge',
	teinte.fond === 'rgb(255, 233, 176)' && teinte.texte === 'rgb(164, 0, 28)',
	'fond ' + teinte.fond + ', texte ' + teinte.texte);

// Le bouton d'ensemble annonce combien de plugins il va reprendre. Ce décompte
// dans le libellé est ce qui avait cassé l'appel : une balise entre parenthèses
// dans l'argument d'une autre, et tout ressortait en clair sur la page.
const toutMaj = page.locator('#panneau-plugins form.bouton_action_post button', { hasText: 'Tout mettre à jour' });
dit('le bouton d’ensemble porte son décompte',
	(await toutMaj.count()) === 1
		&& (await toutMaj.innerText()).trim() === 'Tout mettre à jour (' + surlignees + ')',
	(await toutMaj.count()) ? (await toutMaj.innerText()).trim() : 'bouton absent');
const boutonMaj = ligneMaj.locator('form.bouton_action_post button').first();
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
	// C'est SVP qui a travaillé, pas notre déploiement d'archive : il connaît
	// les dépendances, et il range les plugins là où il sait les retrouver.
	dit('la mise à jour est passée par SVP', lignesPlugin.some((m) => /par SVP/.test(m)),
		lignesPlugin.join(' | '));
	dit('le journal nomme le dossier retenu', lignesPlugin.some((m) => /dossier auto\/zzztest\/v1\.0\.1/.test(m)),
		lignesPlugin.join(' | '));
} else {
	dit('bouton de mise à jour présent', false);
}

// SVP dépose la nouvelle version dans plugins/auto/<prefixe>/v<version>, sa
// convention : rien n'est écrasé, et le dossier dit quelle version il contient.
const versionFichier = execFileSync('php', ['-r',
	`$x=@file_get_contents(getenv('SITE').'/plugins/auto/zzztest/v1.0.1/paquet.xml'); preg_match('/version="([^"]+)"/',(string)$x,$m); echo $m[1] ?? '?';`,
], { env: { ...process.env, SITE: site } }).toString();
dit('la nouvelle version est dans le dossier versionné de SVP', versionFichier === '1.0.1',
	'paquet.xml en ' + versionFichier);
dit('le marqueur 1.0.1 accompagne la nouvelle version',
	(lu('plugins/auto/zzztest/v1.0.1/marqueur.txt') || '').includes('1.0.1'),
	String(lu('plugins/auto/zzztest/v1.0.1/marqueur.txt')));
dit('l’ancienne version est toujours là, intacte',
	(lu('plugins/zzztest/marqueur.txt') || '').includes('1.0.0'), String(lu('plugins/zzztest/marqueur.txt')));

// L'inventaire de SVP doit avoir suivi : sans cela le site géré continue
// d'annoncer l'ancienne version à son propre administrateur.
// Le catalogue du dépôt est relu avant toute décision de mise à jour : c'est lui
// qui dit quelles versions existent, et il ne se rafraîchit pas tout seul. Sa
// date de relecture le prouve mieux qu'un message aperçu au vol.
const depotRelu = execFileSync('php', ['-r',
	`$db=new SQLite3(getenv('BDD'));echo (string) $db->querySingle('SELECT maj FROM spip_depots ORDER BY maj DESC LIMIT 1');`,
], { env: { ...process.env, BDD: bdd } }).toString().trim();
dit('le catalogue du dépôt a été relu à l’instant',
	depotRelu !== '' && (Date.now() - Date.parse(depotRelu.replace(' ', 'T'))) < 10 * 60 * 1000, depotRelu);

// Et le tableau de bord en garde la fraîcheur, pour pouvoir la montrer.
const depotsVus = execFileSync('php', ['-r',
	`$db=new SQLite3(getenv('BDD'));$i=json_decode((string) $db->querySingle('SELECT infos FROM spip_dashboard_sites WHERE id_dashboard_site=1'),true);echo count($i['depots'] ?? []),':',($i['depots'][0]['age'] ?? 'nul');`,
], { env: { ...process.env, BDD: bdd } }).toString().trim();
dit('la fraîcheur des dépôts est remontée au parc', /^1:\d+$/.test(depotsVus), depotsVus);

// SVP range les versions normalisées : « 1.0.1 » s'y écrit « 001.000.001 ».
const paquetLocal = execFileSync('php', ['-r',
	`$db=new SQLite3(getenv('BDD'));$r=$db->querySingle('SELECT pa.version FROM spip_paquets pa JOIN spip_plugins pl ON pl.id_plugin=pa.id_plugin WHERE pa.id_depot=0 AND pl.prefixe="ZZZTEST" ORDER BY pa.version DESC',true);echo $r['version'] ?? '?';`,
], { env: { ...process.env, BDD: bdd } }).toString();
dit('l’inventaire local de SVP a suivi', paquetLocal === '001.000.001', paquetLocal);

// Le remplacement des fichiers ne doit pas désactiver les plugins : « raz »
// sur une liste partielle couperait le dashboard et l'agent eux-mêmes. Et
// changer de dossier ne doit pas non plus les perdre : SPIP tient sa liste par
// dossier, pas par préfixe.
const dossierActif = execFileSync('php', ['-r',
	`$db=new SQLite3(getenv('BDD'));$a=unserialize($db->querySingle('SELECT valeur FROM spip_meta WHERE nom="plugin"'));echo $a['ZZZTEST']['dir'] ?? '?';`,
], { env: { ...process.env, BDD: bdd } }).toString();
dit('SPIP a suivi le plugin dans son nouveau dossier', dossierActif === 'auto/zzztest/v1.0.1', dossierActif);

const actifs = execFileSync('php', ['-r',
	`$db=new SQLite3(getenv('BDD'));$k=array_keys(unserialize($db->querySingle('SELECT valeur FROM spip_meta WHERE nom="plugin"')));echo implode(',',array_intersect(['TOURDECONTROLE','TOURDECONTROLE_AGENT','ZZZTEST'],$k));`,
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

console.log('\n### Habillage repris du privé');

// Les boutons du plugin doivent être ceux du thème : sans `.btn`, ils héritent
// du style des `button` nus, dont le texte est blanc — d'où des libellés
// invisibles sur les fonds clairs qu'on leur donnait.
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
const classesBoutons = await page.locator('form.bouton_action_post button').evaluateAll(
	(b) => b.map((n) => n.className));
dit('des actions sont proposées', classesBoutons.length > 3, classesBoutons.length + ' boutons');
dit('chaque bouton d’action porte la classe du thème',
	classesBoutons.every((c) => /\bbtn\b/.test(c)),
	classesBoutons.filter((c) => !/\bbtn\b/.test(c)).join(' | '));
dit('aucun bouton maison ne subsiste',
	(await page.locator('.dashboard-bouton').count()) === 0);

// Un libellé qui porte un décompte se calcule à part : une balise entre
// parenthèses glissée dans l'argument d'une autre désorganise l'analyse, et
// c'est alors tout l'appel qui ressort en clair au milieu de la page.
const corpsFiche = await page.locator('#panneau-plugins').innerText();
dit('aucun appel de balise ne ressort en clair',
	!/URL_ACTION_AUTEUR|BOUTON_ACTION|#[A-Z_]{3,}/.test(corpsFiche),
	(corpsFiche.match(/(URL_ACTION_AUTEUR|BOUTON_ACTION|#[A-Z_]{3,})/) || [''])[0]);

// L'encadré de la colonne de gauche : le titre vit dans le corps de la boîte.
const encadre = await page.locator('.box.nav-dashboard').innerHTML();
dit('le titre de l’encadré est dans le corps de la boîte',
	/<div class="box__body clearfix">\s*<h2 class="box__title">/.test(encadre),
	encadre.replace(/\s+/g, ' ').slice(0, 90));

// Chaque action rend la main sous l'encadré qui l'a déclenchée, sans quoi la
// page revient en haut et le compte rendu reste hors de vue.
const ancres = await page.locator('form.bouton_action_post').evaluateAll(
	(f) => f.map((n) => decodeURIComponent(n.getAttribute('action') || '')));
dit('les actions reviennent à leur encadré',
	ancres.filter((a) => /#(etat|caches|plugins|sauvegardes|core)\b/.test(a)).length >= 4,
	ancres.filter((a) => !/#/.test(a)).length + ' sans ancre');

// Les trois vues de la liste des plugins.
const filtres = page.locator('.dashboard-filtres a, .dashboard-filtres .on');
dit('trois vues pour la liste des plugins', (await filtres.count()) === 3,
	(await filtres.count()) + ' vues');
const compte = async (url) => {
	await page.goto(base + url, { waitUntil: 'domcontentloaded' });
	return page.locator('#panneau-plugins tbody tr').count();
};
const tous = await compte('/ecrire/?exec=dashboard_site&id_dashboard_site=1&vue=tous');
const installes = await compte('/ecrire/?exec=dashboard_site&id_dashboard_site=1&vue=installes');
const livres = await compte('/ecrire/?exec=dashboard_site&id_dashboard_site=1&vue=distribues');
dit('la vue « installés » écarte les plugins livrés avec SPIP',
	installes > 0 && installes < tous, installes + ' sur ' + tous);

// Sans rien demander, on arrive sur ce que l'hébergeur du site a installé :
// c'est la seule vue où un bouton de mise à jour a un sens.
const defaut = await compte('/ecrire/?exec=dashboard_site&id_dashboard_site=1');
dit('« installés » est la vue par défaut', defaut === installes && defaut < tous,
	defaut + ' lignes, contre ' + installes + ' pour « installés » et ' + tous + ' pour « tous »');
dit('la vue par défaut est celle qui est exposée',
	(await page.locator('.dashboard-filtres li').first().innerText()).trim()
		=== (await page.locator('.dashboard-filtres li span, .dashboard-filtres li strong').first().innerText()).trim(),
	(await page.locator('.dashboard-filtres li span, .dashboard-filtres li strong').allInnerTexts()).join(' | '));
dit('la vue « livrés avec SPIP » écarte les autres',
	livres > 0 && livres < tous, livres + ' sur ' + tous);
dit('les deux vues se partagent la liste', installes + livres === tous,
	installes + ' + ' + livres + ' = ' + tous);

console.log('\n### Onglets « Plugins » et « PHP »');
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
const panneauPlugins = page.locator('#panneau-plugins');
const panneauPhp = page.locator('#panneau-php');
// Trois onglets, quatre quand le site géré a le plugin SPIP WAF : celui-là
// n'apparaît que là où il a un sens.
const ongletsAttendus = 3 + (await page.locator('#onglet-waf').count());
dit('les onglets attendus sont présents',
	(await page.locator('[data-dashboard-onglets] [role="tab"]').count()) === ongletsAttendus,
	(await page.locator('[data-dashboard-onglets] [role="tab"]').allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim()).join(' | '));
dit('« Plugins » ouvert par défaut', (await panneauPlugins.isVisible()) && !(await panneauPhp.isVisible()));

// Le parc est à jour à ce stade : la pastille d'alerte a disparu. Une pastille
// à zéro serait du bruit, et l'œil finirait par ne plus la voir du tout.
dit('un parc à jour n’affiche pas de pastille d’alerte',
	(await page.locator('#onglet-plugins .dashboard-compteur-maj').count()) === 0
		&& (await page.locator('#onglet-plugins .dashboard-compteur').count()) === 1,
	(await page.locator('#onglet-plugins').innerText()).replace(/\s+/g, ' ').trim());

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

// Et vers la droite, jusqu'au troisième onglet.
await page.locator('#onglet-plugins').press('ArrowRight');
await page.locator('#onglet-php').press('ArrowRight');
await page.waitForTimeout(200);
dit('flèche droite : jusqu’à « Serveur »',
	(await page.locator('#panneau-serveur').isVisible()) && !(await panneauPhp.isVisible()));
// Le dernier onglet dépend du site : « Serveur », ou « SPIP WAF » s'il l'a.
let dernier = '#onglet-serveur';
if (await page.locator('#onglet-waf').count()) {
	await page.locator('#onglet-serveur').press('ArrowRight');
	await page.waitForTimeout(200);
	dernier = '#onglet-waf';
	dit('flèche droite : jusqu’à « SPIP WAF »', await page.locator('#panneau-waf').isVisible());
}
await page.locator(dernier).press('ArrowRight');
await page.waitForTimeout(200);
dit('la navigation au clavier boucle', await panneauPlugins.isVisible());
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
	const liens = await page.locator('form.bouton_action_post').evaluateAll(
		(f) => f.map((form) => form.getAttribute('action') || ''));
	// Les URL de retour portent une ancre en minuscules (`%23plugins`) : ce
	// n'est pas une balise non compilée. Une balise, elle, est en capitales.
	const brutes = liens.filter((h) => /%23[A-Z_]{3,}|#[A-Z_]{3,}/.test(h));
	dit(`${nom} : aucune balise non compilée dans les URL d’action`, brutes.length === 0,
		brutes.map((h) => (h.match(/%23[A-Z_:]+|#[A-Z_]{3,}/) || [''])[0]).join(', '));
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

console.log('\n### Onglet « Serveur »');

// L'onglet est refusé tant que le site géré ne l'a pas explicitement autorisé :
// c'est la plus indiscrète des permissions, et elle s'accorde à part.
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
const appelsServeur = [];
page.on('response', (r) => {
	if (/action=dashboard_serveur/.test(r.url())) { appelsServeur.push(r.status()); }
});
dit('onglet « Serveur » présent', (await page.locator('#onglet-serveur').count()) > 0);
dit('rien n’est demandé au site avant d’ouvrir l’onglet', appelsServeur.length === 0, appelsServeur.join(','));

await page.locator('#onglet-serveur').click();
await page.waitForTimeout(2500);
const refus = await page.locator('[data-serveur-bloc="resume"]').innerText();
dit('consultation refusée tant qu’elle n’est pas autorisée', /désactivée|autoris/i.test(refus), refus.trim().slice(0, 90));

await page.goto(base + '/ecrire/?exec=configurer_dashagent', { waitUntil: 'domcontentloaded' });
await page.check('[name="op_serveur"]').catch(() => {});
await page.locator('form input[type=submit]').last().click();
await page.waitForTimeout(700);
dit('consultation autorisée sur le site géré',
	await page.locator('[name="op_serveur"]').isChecked().catch(() => false));

await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
await page.locator('#onglet-serveur').click();
await page.waitForTimeout(3000);

const resume = await page.locator('[data-serveur-bloc="resume"]').innerText();
dit('résumé : version de PHP', /PHP\s*\d+\.\d+/.test(resume), resume.split('\n')[0]);
dit('résumé : base de données', /sqlite|mysql|maria/i.test(resume));
dit('résumé : poids de la base', /Poids de la base\s*[\d.]+\s*(o|Ko|Mo|Go)/.test(resume),
	(resume.match(/Poids de la base[^\n]*/) || [''])[0]);
dit('résumé : extensions PHP listées', /\d+ extensions PHP chargées/.test(resume),
	(resume.match(/\d+ extensions PHP chargées/) || [''])[0]);

// Parcours d'une table : pagination, tri, filtre.
const bloc = page.locator('[data-serveur-bloc="tables"]');
const choix = bloc.locator('select').first();
dit('les tables du site sont listées', (await choix.locator('option').count()) > 10,
	(await choix.locator('option').count()) + ' entrées');
await choix.selectOption('spip_meta');
await page.waitForTimeout(1500);
// La pagination reprend le balisage de SPIP : `nav.pagination`, une liste
// `.pagination-items` et des `.pagination-item`. C'est ce qui lui vaut d'hériter
// des styles du privé au lieu d'écrire du blanc sur du blanc.
const pagination = bloc.locator('nav.pagination');
const total = Number(((await pagination.innerText()).match(/sur (\d+)/) || [0, 0])[1]);
dit('le contenu d’une table s’affiche', (await bloc.locator('tbody tr').count()) > 0,
	(await bloc.locator('tbody tr').count()) + ' lignes sur ' + total);
dit('la pagination reprend le balisage de SPIP',
	(await pagination.locator('ul.pagination-items li.pagination-item').count()) >= 2,
	(await pagination.locator('li.pagination-item').count()) + ' éléments');
// Le même type que les boucles paginées de la page : précédent, les numéros de
// page, suivant. Cette liste-ci ne vient d'aucune boucle, elle est bâtie en
// JavaScript — raison de plus pour qu'elle en reprenne le balisage exactement.
dit('la pagination du parcours de table porte des numéros de page',
	(await pagination.locator('ul.pagination_page_precedent_suivant').count()) === 1
		&& (await pagination.locator('li.pagination-item.on.active').innerText()).trim() === '1',
	(await pagination.locator('ul.pagination-items').innerText()).replace(/\s+/g, ' ').trim());

await bloc.locator('thead th button').first().click();
await page.waitForTimeout(1200);
dit('le tri s’applique sur une colonne', /[↑↓]/.test(await bloc.locator('thead th').first().innerText()));

if (total > 50) {
	await pagination.locator('.pagination-item.next a').click();
	await page.waitForTimeout(1200);
	dit('la pagination avance',
		/^51/.test((await pagination.innerText()).trim()),
		(await pagination.innerText()).replace(/\s+/g, ' ').trim());
}

await bloc.locator('select').nth(1).selectOption('nom');
await bloc.locator('input[type=search]').fill('version');
await page.waitForTimeout(1500);
const filtre = Number(((await pagination.innerText()).match(/sur (\d+)/) || [0, 0])[1]);
dit('le filtre restreint la sélection', filtre > 0 && filtre < total, filtre + ' sur ' + total);

// Le point qui compte : rien de secret ne doit atteindre l'écran.
await choix.selectOption('spip_auteurs');
await page.waitForTimeout(1500);
const entetes = await bloc.locator('thead th').allInnerTexts();
dit('les colonnes sensibles sont annoncées comme masquées',
	entetes.filter((h) => /masquée/.test(h)).length >= 3,
	entetes.filter((h) => /masquée/.test(h)).map((h) => h.split('(')[0].trim()).join(', '));
const corpsAuteurs = await bloc.locator('tbody').innerText();
dit('aucune empreinte de mot de passe à l’écran', !/\$2y\$|\$argon|\$1\$/.test(corpsAuteurs));
dit('les colonnes utiles restent lisibles', /admin/.test(corpsAuteurs));

await choix.selectOption('spip_meta');
await page.waitForTimeout(1500);
await bloc.locator('select').nth(1).selectOption('nom');
await bloc.locator('input[type=search]').fill('dashagent');
await page.waitForTimeout(1500);
dit('le secret partagé de l’agent ne s’affiche pas',
	!/c2:/.test(await bloc.locator('tbody').innerText()),
	(await bloc.locator('tbody').innerText()).replace(/\s+/g, ' ').trim().slice(0, 70));

// Fichiers de configuration.
const fichiers = page.locator('[data-serveur-bloc="fichiers"] details');
dit('trois fichiers proposés', (await fichiers.count()) === 3);
const options = fichiers.filter({ hasText: 'mes_options' }).first();
await options.locator('summary').click();
await page.waitForTimeout(1500);
const texteOptions = await options.innerText();
dit('le contenu de mes_options.php s’affiche', /_DASHAGENT_ARCHIVES_HTTP/.test(texteOptions),
	texteOptions.replace(/\s+/g, ' ').slice(0, 90));

// phpinfo, dans son cadre isolé.
await page.locator('[data-serveur-bloc="phpinfo"] summary').click();
await page.waitForTimeout(3000);
const cadre = page.locator('iframe.dashboard-serveur-phpinfo');
dit('phpinfo s’affiche dans un cadre isolé', (await cadre.count()) === 1);
if (await cadre.count()) {
	const dedans = page.frameLocator('iframe.dashboard-serveur-phpinfo');
	const texte = await dedans.locator('body').innerText();
	dit('phpinfo porte bien ses sections', (await dedans.locator('h2').count()) > 10,
		(await dedans.locator('h2').count()) + ' sections');
	dit('les variables d’environnement sensibles sont masquées',
		!/proxy-injected|sk-live|ghp_[A-Za-z0-9]/.test(texte),
		(texte.match(/[^\n]*(proxy-injected|sk-live|ghp_)[^\n]*/) || [''])[0].slice(0, 70));
}

console.log('\n### URL des boutons d’action');
await urlsDActions(page, 'parc', '/ecrire/?exec=dashboard');
await urlsDActions(page, 'fiche du site', '/ecrire/?exec=dashboard_site&id_dashboard_site=1');

console.log('\n### Mise à jour du core SPIP');
const coreCible = process.env.CORE_CIBLE || '4.4.99';

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
const boutonCore = page.locator('#core form.bouton_action_post button').first();
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

// Aucune notification de départ dans l'URL : elle s'y figerait sur l'étape du
// moment et survivrait à la fin du chantier — « étape 2/5 » sur une opération
// achevée se lit comme un blocage. C'est l'encadré qui rend compte pendant, et
// le bilan après.
dit('aucune notification de départ ne se fige dans l’URL',
	!/dashboard_message=/.test(page.url()), page.url());
dit('un encadré de chantier est affiché', encadreVu);

// Le pilote de la page mène l'opération à son terme, étape par étape.
const issue = await attendreChantier(page);
dit('le chantier arrive à son terme', issue.fini, 'dernière étape vue : ' + issue.dernier);
await page.waitForTimeout(1500);

const chantier = JSON.parse(sql(
	"SELECT statut, etape, tentatives, message FROM spip_dashboard_chantiers WHERE operation = 'core_maj' ORDER BY id_dashboard_chantier DESC LIMIT 1"
))[0] || {};
dit('le chantier est enregistré comme réussi', chantier.statut === 'ok', JSON.stringify(chantier));

// Le bilan prend le relais de l'encadré : sans lui, la page ne montrerait plus
// rien une fois l'opération finie, et c'est pourtant là que se dit l'essentiel —
// par exemple qu'une base attend encore sa migration.
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
const bilan = page.locator('#bilan');
dit('le compte rendu du chantier terminé est affiché', (await bilan.count()) === 1);
dit('le bilan dit ce que le chantier a conclu',
	(await bilan.innerText()).includes(String(chantier.message).slice(0, 40)),
	(await bilan.innerText()).replace(/\s+/g, ' ').trim().slice(0, 120));
dit('aucun encadré d’avancement ne subsiste',
	(await page.locator('[data-dashboard-chantier]').count()) === 0);
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
dit('plugin mis à jour non écrasé', (lu('plugins/auto/zzztest/v1.0.1/paquet.xml') || '').includes('1.0.1'));
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
	(await page.locator('#core').count()) === 0);

console.log('\n### Un site géré ne doit pas pouvoir écrire de code chez nous');

// Le tableau de bord affiche l'inventaire d'un site dans son espace privé, à un
// webmestre qui détient les secrets de tout le parc. L'échappement de SPIP ne
// couvre pas ce cas : interdire_scripts() laisse passer <svg onload> — vérifié
// sur SPIP 4.4.23. Un site compromis qui répondrait cela comme numéro de version
// prendrait donc la tour de contrôle, et avec elle le parc entier.
//
// La charge est écrite directement en base, donc *après* le filtre d'entrée :
// ce qui est éprouvé ici est le rendu, seul rempart pour les inventaires relevés
// avant le correctif.
const charge = '<svg onload="window.__xss=(window.__xss||0)+1">';
const injections = [
	['version de SPIP',        `UPDATE spip_dashboard_sites SET version_spip='${charge}' WHERE id_dashboard_site=1`],
	['version de PHP',         `UPDATE spip_dashboard_sites SET php_version='${charge}' WHERE id_dashboard_site=1`],
	['version de la base',     `UPDATE spip_dashboard_sites SET sql_version='${charge}' WHERE id_dashboard_site=1`],
	['version de l’agent',     `UPDATE spip_dashboard_sites SET agent_version='${charge}' WHERE id_dashboard_site=1`],
	['message d’erreur',       `UPDATE spip_dashboard_sites SET erreur='${charge}', etat='erreur' WHERE id_dashboard_site=1`],
	['nom d’un plugin',        `UPDATE spip_dashboard_plugins SET nom='${charge}' WHERE id_dashboard_site=1`],
	['version d’un plugin',    `UPDATE spip_dashboard_plugins SET version='${charge}' WHERE id_dashboard_site=1`],
	['version disponible',     `UPDATE spip_dashboard_plugins SET version_disponible='${charge}' WHERE id_dashboard_site=1`],
	['préfixe d’un plugin',    `UPDATE spip_dashboard_plugins SET prefixe='${charge}' WHERE id_dashboard_site=1`],
	['journal du parc',        `UPDATE spip_dashboard_journal SET message='${charge}'`],
];

for (const [quoi, requete] of injections) {
	sqlEcrire(requete);
	await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'networkidle' });
	const execute = await page.evaluate(() => { const n = window.__xss || 0; window.__xss = 0; return n; });
	const vif = (await page.content()).includes('<svg onload=');
	const ok = (execute === 0 && !vif);
	dit(`inerte : ${quoi}`, ok, ok ? '' : (execute ? 'script exécuté' : 'rendu en HTML vif'));
}

// L'inventaire est aussi affiché par des filtres qui le tirent du JSON mémorisé.
const infos = JSON.parse(JSON.parse(sql('SELECT infos FROM spip_dashboard_sites WHERE id_dashboard_site=1'))[0].infos);
infos.serveur = infos.serveur || {};
infos.serveur.memory_limit = charge;
if (Array.isArray(infos.procures) && infos.procures[0]) { infos.procures[0].nom = charge; }
sqlEcrire(`UPDATE spip_dashboard_sites SET infos='${JSON.stringify(infos).replace(/'/g, "''")}' WHERE id_dashboard_site=1`);
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'networkidle' });
dit('inerte : inventaire mémorisé',
	(await page.evaluate(() => window.__xss || 0)) === 0 && !(await page.content()).includes('<svg onload='));

// Et la vue d'ensemble, qui affiche les mêmes champs pour tout le parc.
await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'networkidle' });
dit('inerte : vue d’ensemble du parc',
	(await page.evaluate(() => window.__xss || 0)) === 0 && !(await page.content()).includes('<svg onload='));

// Le filtre d'entrée, maintenant : une synchronisation réelle doit rendre inerte
// ce que l'agent a répondu, sans que le rendu ait à s'en occuper.
await bouton(page, 'Synchroniser');
const apresSync = JSON.parse(sql('SELECT version_spip, infos FROM spip_dashboard_sites WHERE id_dashboard_site=1'))[0];
dit('la synchronisation a rétabli un inventaire propre',
	!/[<>]/.test(apresSync.version_spip) && !/<svg/.test(apresSync.infos), apresSync.version_spip);

// Le lien public d'un site, dans le tableau du parc : ses parenthèses sortaient
// telles quelles, et le navigateur relisait « (https://…) » comme une adresse
// relative — le lien ramenait sur l'espace privé du parc.
await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'domcontentloaded' });
const lienPublic = page.locator('a.dashboard-lien-public').first();
if (await lienPublic.count()) {
	const href = (await lienPublic.getAttribute('href')) || '';
	dit('le lien public du parc est une vraie adresse',
		/^https?:\/\//.test(href) && !/[()]/.test(href), href || 'vide');
}

console.log('\n### Onglet SPIP WAF');

// Le plugin n'est pas livré avec ce dépôt : la section ne tourne que si le banc
// l'a installé (WAF_ZIP=… tests/integration/executer.sh …).
const wafInstalle = JSON.parse(sql(
	"SELECT name FROM sqlite_master WHERE type='table' AND name='spip_waf_events'")).length > 0;

if (!wafInstalle) {
	console.log('  (sauté) plugin SPIP WAF absent du banc — relancer avec WAF_ZIP=/chemin/waf-vX.Y.Z.zip');
} else {
	/* De quoi faire parler la tendance : des blocages étalés sur plusieurs jours,
	   avec un jour creux au milieu. Sans cela, la série tiendrait sur une seule
	   colonne et ne dirait rien de ce que le graphique doit montrer. */
	for (const [jours, ip, type, motif] of [
		[9, '203.0.113.21', 'BLOCKED', 'SQLI'], [9, '203.0.113.22', 'BLOCKED', 'XSS'],
		[6, '198.51.100.31', 'BLOCKED', 'SQLI'],
		// Rien le 5e jour : le trou doit ressortir à zéro, pas disparaître.
		[4, '198.51.100.32', 'BLOCKED', 'XSS'], [4, '198.51.100.33', 'BLOCKED', 'SQLI'],
		[4, '198.51.100.34', 'BAN', 'BANNED_IP'],
		[2, '192.0.2.44', 'BLOCKED', 'LOGIN_FAIL'],
	]) {
		sqlEcrire(`INSERT INTO spip_waf_events (date_event, ip, type, reason, trigger_info, method, uri, ua, extra)`
			+ ` VALUES (datetime('now','-${jours} days'), '${ip}', '${type}', '${motif}', '', 'GET',`
			+ ` '/spip.php?page=x', 'curl/8.5', '')`);
	}

	// De quoi faire parler le tableau : des blocages, une adresse bannie.
	for (const [heure, ip, type, motif] of [
		[1, '203.0.113.5', 'BLOCKED', 'SQLI'], [2, '203.0.113.5', 'BLOCKED', 'XSS'],
		[3, '198.51.100.9', 'BLOCKED', 'BLOCKLISTED_IP'], [4, '203.0.113.5', 'BAN', 'BANNED_IP'],
		[5, '192.0.2.7', 'BLOCKED', 'LOGIN_FAIL'],
	]) {
		sqlEcrire(`INSERT INTO spip_waf_events (date_event, ip, type, reason, trigger_info, method, uri, ua, extra)`
			+ ` VALUES (datetime('now','-${heure} hours'), '${ip}', '${type}', '${motif}', '', 'GET',`
			+ ` '/spip.php?page=x&q=${'A'.repeat(40)}', 'curl/8.5', '')`);
	}

	await page.goto(base + '/ecrire/?exec=configurer_dashagent', { waitUntil: 'domcontentloaded' });
	await page.check('[name="op_waf"]').catch(() => {});
	await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').last().click()]);
	await page.waitForTimeout(600);

	// L'inventaire doit d'abord apprendre que le site a ce plugin : c'est lui qui
	// décide de l'apparition de l'onglet.
	await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
	await bouton(page, 'Synchroniser');
	await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });

	dit('l’onglet SPIP WAF apparaît sur un site qui a le plugin',
		(await page.locator('#onglet-waf').count()) === 1);
	// Les tableaux du graphique ne comptent pas : ils sont bâtis depuis notre
	// propre base, remplie à la synchronisation précédente, sans rien demander au
	// site géré. Ce qu'on veut établir ici, c'est qu'aucun chiffre **du pare-feu
	// distant** n'est allé se chercher avant que l'onglet ne soit ouvert.
	const tablesDistantes = await page.evaluate(() =>
		Array.from(document.querySelectorAll('#panneau-waf table'))
			.filter((t) => !t.closest('[data-dashboard-waf-graphe]')).length);
	dit('rien n’est demandé au site avant d’ouvrir l’onglet',
		/Le site sera interrogé|attente|Interrogation/i.test(await page.locator('#panneau-waf').innerText())
			|| tablesDistantes === 0);

	await page.locator('#onglet-waf').click();
	await page.waitForTimeout(4000);
	const waf = page.locator('#panneau-waf');
	const texteWaf = await waf.innerText();
	dit('les chiffres du pare-feu sont affichés', /Requêtes bloquées/.test(texteWaf),
		texteWaf.replace(/\s+/g, ' ').slice(0, 90));
	dit('les motifs de blocage sont listés', /SQLI|LOGIN_FAIL/.test(texteWaf));
	dit('les bannissements sont listés', /BANNED_IP/.test(texteWaf));
	dit('le journal est paginé comme le reste du privé',
		(await waf.locator('ul.pagination_page_precedent_suivant').count()) === 1);

	// La règle du dépôt : ce qui vient d'un site géré n'est jamais du balisage.
	// Une URI d'attaque contient volontiers du HTML — elle doit rester du texte.
	sqlEcrire("INSERT INTO spip_waf_events (date_event, ip, type, reason, trigger_info, method, uri, ua, extra)"
		+ " VALUES (datetime('now'), '203.0.113.99', 'BLOCKED', 'XSS', '', 'GET',"
		+ " '/spip.php?q=<svg onload=\"window.__xss=1\">', '<svg onload=\"window.__xss=1\">', '')");
	await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
	await page.locator('#onglet-waf').click();
	await page.waitForTimeout(4000);
	dit('une charge utile enregistrée par le pare-feu reste inerte',
		(await page.evaluate(() => window.__xss || 0)) === 0
			&& !(await waf.innerHTML()).includes('<svg onload='),
		(await waf.innerText()).includes('svg onload') ? 'rendue en texte' : 'absente');

	/* La tendance. Elle vient de notre base, remplie à la synchronisation : une
	   synchro est donc nécessaire avant qu'il y ait quoi que ce soit à tracer. */
	await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
	await page.locator('form.bouton_action_post button', { hasText: 'Synchroniser' }).first().click();
	await page.waitForLoadState('domcontentloaded');
	await page.waitForTimeout(2000);

	const jours = JSON.parse(sql('SELECT COUNT(*) AS n FROM spip_dashboard_waf_jours'))[0].n;
	dit('la synchronisation range l’activité du WAF jour par jour', Number(jours) > 0, `${jours} journée(s)`);

	await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
	await page.locator('#onglet-waf').click();
	await page.waitForTimeout(1500);

	const graphe = page.locator('[data-dashboard-waf-graphe]').first();
	dit('le bloc de tendance est présent sur la fiche', (await graphe.count()) === 1);

	if (await graphe.count()) {
		// Deux graphiques distincts, jamais un double axe : « requêtes » et
		// « adresses » n'ont pas le même ordre de grandeur.
		dit('deux graphiques, pas un seul à double axe',
			(await graphe.locator('canvas').count()) === 2);

		// Chart.js a réellement dessiné : un canvas non peint reste vide.
		const peint = await page.evaluate(() => {
			const c = document.querySelector('[data-dashboard-waf-graphe] canvas');
			if (!c || !c.width) { return false; }
			const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
			for (let i = 3; i < d.length; i += 4) { if (d[i] !== 0) { return true; } }
			return false;
		});
		dit('les courbes sont effectivement tracées', peint);

		// La série porte la fenêtre large ; le sélecteur y découpe sans requête.
		const points = await page.evaluate(() => {
			const j = document.querySelector('[data-dashboard-waf-graphe] script[type="application/json"]');
			return JSON.parse(j.textContent).points.length;
		});
		dit('la page reçoit la fenêtre large en une fois', points === 90, `${points} points`);

		const trous = await page.evaluate(() => {
			const j = document.querySelector('[data-dashboard-waf-graphe] script[type="application/json"]');
			const p = JSON.parse(j.textContent).points;
			for (let i = 1; i < p.length; i++) {
				const attendu = new Date(Date.parse(p[i - 1].jour) + 86400000).toISOString().slice(0, 10);
				if (p[i].jour !== attendu) { return p[i].jour; }
			}
			return '';
		});
		dit('la série n’a aucun trou de jour', trous === '', trous || 'continue');

		// Le sélecteur change la fenêtre sans recharger la page.
		await graphe.locator('[data-fenetre="7"]').click();
		await page.waitForTimeout(400);
		dit('le sélecteur retient la fenêtre choisie',
			(await graphe.locator('[data-fenetre="7"]').getAttribute('aria-pressed')) === 'true');

		// Le tableau qui double les courbes, pour qui ne les voit pas.
		await graphe.locator('details summary').click();
		await page.waitForTimeout(300);
		const lignes = await graphe.locator('details tbody tr').count();
		dit('un tableau double les courbes, réduit à la fenêtre choisie', lignes === 7, `${lignes} ligne(s)`);
	}

	// Et la tendance du parc, sur la vue d'ensemble.
	await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(1200);
	const grapheParc = page.locator('#waf [data-dashboard-waf-graphe]');
	dit('la vue d’ensemble porte la tendance du parc', (await grapheParc.count()) === 1);
	if (await grapheParc.count()) {
		dit('elle aussi en deux graphiques', (await grapheParc.locator('canvas').count()) === 2);

		/* Les mêmes contrôles que sur la fiche d'un site, et pour cause : ce
		   graphique-ci n'avait que sa présence de vérifiée, et il a tracé deux
		   courbes vides pendant une version entière. La cause était dans la
		   fenêtre — `#VAL|dashboard_waf_serie_parc` compile en
		   `dashboard_waf_serie_parc('')`, la chaîne vide valait zéro, et la
		   série se réduisait au jour même. Un encadré présent, un JSON présent,
		   et rien à voir. */
		const pointsParc = await page.evaluate(() => {
			const j = document.querySelector('#waf [data-dashboard-waf-graphe] script[type="application/json"]');
			return j ? JSON.parse(j.textContent).points.length : 0;
		});
		dit('la tendance du parc porte la fenêtre entière', pointsParc === 90, `${pointsParc} points`);

		const nonNulsParc = await page.evaluate(() => {
			const j = document.querySelector('#waf [data-dashboard-waf-graphe] script[type="application/json"]');
			return j ? JSON.parse(j.textContent).points.filter((p) => p.requetes > 0).length : 0;
		});
		dit('elle porte des chiffres, pas seulement des zéros', nonNulsParc > 0, `${nonNulsParc} journée(s)`);

		const peintParc = await page.evaluate(() => {
			const c = document.querySelector('#waf [data-dashboard-waf-graphe] canvas');
			if (!c || !c.width) { return false; }
			const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
			for (let i = 3; i < d.length; i += 4) { if (d[i] !== 0) { return true; } }
			return false;
		});
		dit('les courbes du parc sont effectivement tracées', peintParc);
	}

	// Chart.js est servi par le plugin, jamais par un CDN.
	const sources = await page.evaluate(() =>
		Array.from(document.querySelectorAll('script[src]')).map((s) => s.getAttribute('src')));
	dit('aucun script n’est chargé depuis un hôte extérieur',
		!sources.some((u) => /^https?:\/\//.test(u) && !u.includes('127.0.0.1')),
		sources.filter((u) => /^https?:/.test(u)).join(' ') || 'tous locaux');
}

console.log('\n### Actions groupées sur la vue d’ensemble');

await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'domcontentloaded' });

const cases = page.locator('[data-parc-site]');
dit('chaque site du tableau porte une case à cocher', (await cases.count()) >= 1,
	`${await cases.count()} case(s)`);

// La case ne fait que désigner : c'est la file d'adresses signées qui dit ce
// qu'un site peut recevoir. Lui coller l'adresse aurait fait de la case un
// droit.
const portee = await page.evaluate(() => {
	const c = document.querySelector('[data-parc-site]');
	return c ? Array.from(c.attributes).map((a) => a.name).join(' ') : '';
});
dit('une case ne porte aucune adresse d’action', !/url|href|action=/.test(portee), portee);

const filesVues = await page.evaluate(() =>
	Array.from(document.querySelectorAll('[data-parc-file]'))
		.map((u) => u.getAttribute('data-parc-file')).sort().join(','));
dit('les files d’adresses sont présentes', filesVues === 'agent_maj,depots,purger,sync', filesVues);

const signees = await page.evaluate(() => {
	const liens = Array.from(document.querySelectorAll('[data-parc-file] a'));
	return liens.length && liens.every((a) => /action=dashboard_parc/.test(a.getAttribute('href'))
		&& /hash=/.test(a.getAttribute('href')));
});
dit('chaque adresse de file est signée pour son action', signees);

// Le décompte suit la sélection, et « tout cocher » ne coche que la page.
dit('rien n’est sélectionné au départ',
	/^0 /.test((await page.locator('[data-parc-compte]').innerText()).trim()),
	(await page.locator('[data-parc-compte]').innerText()).trim());
dit('les boutons de sélection sont inertes sans sélection',
	await page.locator('[data-parc-action="sync"][data-parc-selection]').isDisabled());

await page.locator('[data-parc-tout]').check();
await page.waitForTimeout(200);
const nbCases = await cases.count();
dit('« tout cocher » coche la page affichée',
	(await page.locator('[data-parc-compte]').innerText()).trim().startsWith(String(nbCases)),
	(await page.locator('[data-parc-compte]').innerText()).trim());
dit('les boutons de sélection s’activent',
	!(await page.locator('[data-parc-action="sync"][data-parc-selection]').isDisabled()));

// Synchroniser la sélection : ce qui prouve que l'opération a bien atteint le
// site géré, c'est la date d'inventaire qui bouge.
sqlEcrire("UPDATE spip_dashboard_sites SET date_sync_ok = '2020-01-01 00:00:00'");
await page.locator('[data-parc-action="sync"]').click();
await page.waitForFunction(() => /Termin/.test(
	(document.querySelector('.dashboard-parc-groupe [data-parc-avancement]') || {}).textContent || ''),
	null, { timeout: 120000 }).catch(() => {});
await page.waitForTimeout(3000);
const syncApres = JSON.parse(sql('SELECT date_sync_ok FROM spip_dashboard_sites WHERE id_dashboard_site = 1'))[0].date_sync_ok;
dit('la synchronisation groupée a bien atteint le site', syncApres > '2020-01-01 00:00:00', syncApres);

// Vider les caches de la sélection : on pose un fichier de cache et on regarde
// s'il disparaît. Le même contrôle que la purge d'un seul site, en groupe.
const temoinCache = site + '/tmp/cache/zz-parc-temoin.txt';
writeFileSync(temoinCache, 'temoin');
await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'domcontentloaded' });
await page.locator('[data-parc-tout]').check();
await page.waitForTimeout(200);
await page.locator('[data-parc-action="purger"]').click();
await page.waitForFunction(() => /Termin/.test(
	(document.querySelector('.dashboard-parc-groupe [data-parc-avancement]') || {}).textContent || ''),
	null, { timeout: 120000 }).catch(() => {});
await page.waitForTimeout(3000);
dit('la purge groupée a bien vidé le cache du site', !existsSync(temoinCache));

console.log('\n### Mise à jour de l’agent sur tout le parc');

/* Le dépôt local publie une version de l'agent supérieure d'un cran. Le code
   est le même à l'identique : ce qu'on éprouve ici, c'est qu'un plugin puisse
   se remplacer lui-même pendant qu'il répond — le site se tait alors au beau
   milieu de l'opération, et ce silence ne prouve rien. */
const agentCible = process.env.AGENT_CIBLE || '';

/* La nouvelle version paraît maintenant : on sert le catalogue qui l'annonce,
   on vieillit le dépôt, et on fait relire le parc. C'est la chaîne réelle —
   SVP relit le catalogue, l'inventaire le reprend, et la vue d'ensemble s'en
   aperçoit. Publier dès le départ aurait faussé tout ce qui précède, où le
   parcours vérifie qu'un parc à jour n'annonce plus rien. */
writeFileSync(site + '/zzztest-archives/paquets.xml',
	readFileSync(site + '/zzztest-archives/paquets-agent.xml', 'utf8'));
sqlEcrire("UPDATE spip_depots SET maj = datetime('now', '-3 days')");
await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'domcontentloaded' });
await page.locator('[data-parc-action="depots"]').click();
await page.waitForFunction(() => /Termin|suivant/.test(
	(document.querySelector('#depots [data-parc-avancement]') || {}).textContent || ''),
	null, { timeout: 120000 }).catch(() => {});
await page.waitForTimeout(3000);

const agentAvant = JSON.parse(sql(
	"SELECT version, version_disponible FROM spip_dashboard_plugins"
	+ " WHERE prefixe = 'TOURDECONTROLE_AGENT'"))[0] || {};
dit('la parution est arrivée jusqu’à l’inventaire',
	agentAvant.version_disponible === agentCible,
	`${agentAvant.version || '?'} → ${agentAvant.version_disponible || '(aucune)'}`);

await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'domcontentloaded' });
const boutonAgent = page.locator('[data-parc-action="agent_maj"]');
dit('un bouton propose de mettre à jour l’agent du parc', (await boutonAgent.count()) === 1);

if (await boutonAgent.count()) {
	dit('le bouton porte le nombre de sites concernés',
		/\(1 site/.test(await boutonAgent.innerText()), (await boutonAgent.innerText()).trim());

	// Remplacer les fichiers d'un site sans prévenir n'est pas une interface :
	// la confirmation dit ce qui va se passer, sauvegarde comprise.
	const confirmation = await boutonAgent.getAttribute('data-parc-confirmer');
	dit('la confirmation annonce la sauvegarde et le silence',
		/sauvegard/i.test(confirmation) && /tair/i.test(confirmation), confirmation);

	const sauvegardesAvant = Number(JSON.parse(sql('SELECT COUNT(*) AS n FROM spip_dashboard_sauvegardes'))[0].n);

	// `once` et non `on` : un écouteur laissé en place répondrait aussi aux
	// confirmations des sections suivantes, qui ont les leurs — et Playwright
	// refuse qu'une même boîte soit acceptée deux fois.
	page.once('dialog', (d) => d.accept());
	await boutonAgent.click();
	// Le chantier fait bien plus d'allers-retours qu'une relecture de dépôts :
	// cinq étapes, dont une qui rejoue tant que SVP n'a pas fini.
	await page.waitForFunction(() => /Termin|suivant/.test(
		(document.querySelector('.dashboard-agent-parc [data-parc-avancement]') || {}).textContent || ''),
		null, { timeout: 600000 }).catch(() => {});
	await page.waitForTimeout(4000);

	const chantier = JSON.parse(sql(
		"SELECT operation, cible, statut, message FROM spip_dashboard_chantiers"
		+ " ORDER BY id_dashboard_chantier DESC LIMIT 1"))[0] || {};
	dit('un chantier de mise à jour a visé l’agent',
		chantier.operation === 'plugin_maj' && chantier.cible === 'TOURDECONTROLE_AGENT',
		`${chantier.operation || '?'} / ${chantier.cible || '?'}`);
	dit('le chantier est allé à son terme', chantier.statut === 'ok',
		`${chantier.statut || '?'} — ${(chantier.message || '').slice(0, 90)}`);

	// Toute mise à jour commence par une sauvegarde : celle de l'agent n'y
	// échappe pas, et c'est le seul filet quand le plugin qui répond est celui
	// qu'on remplace.
	dit('une sauvegarde a précédé le remplacement',
		Number(JSON.parse(sql('SELECT COUNT(*) AS n FROM spip_dashboard_sauvegardes'))[0].n) > sauvegardesAvant);

	// Sur le disque : SVP range le plugin sous plugins/auto/<prefixe>/v<version>,
	// à côté de l'ancien plutôt que par-dessus.
	dit('la nouvelle version est déployée à côté de l’ancienne',
		existsSync(`${site}/plugins/auto/tourdecontrole_agent/v${agentCible}`),
		`plugins/auto/tourdecontrole_agent/v${agentCible}`);
	dit('l’ancien dossier de l’agent est intact',
		readdirSync(`${site}/plugins`).some((d) => /^tourdecontrole_agent-/.test(d)),
		readdirSync(`${site}/plugins`).filter((d) => /^tourdecontrole_agent/.test(d)).join(' '));

	// Et surtout : l'agent répond encore. Un plugin qui se remplace lui-même
	// peut très bien avoir laissé le site sur le carreau.
	await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
	await bouton(page, 'Synchroniser');
	const apres = JSON.parse(sql(
		'SELECT etat, agent_version FROM spip_dashboard_sites WHERE id_dashboard_site = 1'))[0];
	dit('l’agent répond encore après s’être remplacé', apres.etat === 'ok', apres.etat);
	dit('le parc enregistre la nouvelle version de l’agent',
		apres.agent_version === agentCible, `${apres.agent_version} (attendu ${agentCible})`);

	// Un second passage ne doit rien refaire : plus de version supérieure, donc
	// plus de chantier — et surtout plus de sauvegarde sur chaque site du parc.
	await page.goto(base + '/ecrire/?exec=dashboard', { waitUntil: 'domcontentloaded' });
	dit('le bouton disparaît une fois le parc à jour',
		(await page.locator('[data-parc-action="agent_maj"]').count()) === 0);
}

console.log('\n### Script d’installation (spip_loader.php)');

// Le fichier est servi en .txt : le serveur de test exécuterait un .php au lieu
// d'en rendre la source, et l'agent recevrait « SPIP loader » au lieu du script.
// Le contrôle de contenu s'en aperçoit — c'est d'ailleurs lui qui l'a montré.
const loaderSource = site + '/core-archives/spip_loader.txt';
// Le vrai script est un stub de PHAR : en-tête PHP lisible, archive binaire à
// la suite, et des constantes de classe qui le datent. La copie de test reprend
// cet en-tête à l'identique — c'est lui que l'agent lit.
writeFileSync(loaderSource,
	"<?php\n\nnamespace Spip\\Loader;\n\nuse Phar;\n\nfinal class Stub\n{\n"
	+ "    public const VERSION = '8.0.5';\n\n"
	+ "    public const FULL_VERSION = '8.0.5';\n\n"
	+ "    public const DATE = '2026-09-04 06:44:10';\n\n"
	+ "    public const NAME = 'spip_loader.phar';\n}\n");

await page.goto(base + '/ecrire/?exec=configurer_dashboard', { waitUntil: 'domcontentloaded' });
await page.fill('[name="url_spip_loader"]', base + '/core-archives/spip_loader.txt');
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').first().click()]);
await page.waitForTimeout(600);

// L'opération est refusée tant que le site géré ne l'a pas accordée.
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
dit('l’encadré du spip_loader est présent', (await page.locator('#loader').count()) === 1);
// Un lien de navigation vers le script du site, pas une action : un `a`, qui
// s'ouvre à côté. Son adresse se construit sur l'URL publique du site géré.
const lienLoader = page.locator('#loader a.btn[target="_blank"]');
dit('un lien mène au spip_loader du site',
	(await lienLoader.count()) === 1
		&& /\/spip_loader\.php$/.test((await lienLoader.getAttribute('href')) || ''),
	(await lienLoader.getAttribute('href')) || 'absent');
page.once('dialog', (d) => d.accept());
await page.locator('#loader form.bouton_action_post button').first().click();
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(1500);
dit('le dépôt est refusé tant que le site ne l’autorise pas',
	/désactivée|autoris/i.test(await page.locator('body').innerText())
		&& !existsSync(site + '/spip_loader.php'),
	existsSync(site + '/spip_loader.php') ? 'fichier déposé malgré le refus' : 'refus');

// Accordée, l'opération dépose le fichier à la racine.
await page.goto(base + '/ecrire/?exec=configurer_dashagent', { waitUntil: 'domcontentloaded' });
await page.check('[name="op_loader"]').catch(() => {});
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').last().click()]);
await page.waitForTimeout(600);

await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
await page.locator('[data-dashboard-loader-etat]').click();
await page.waitForTimeout(2000);
dit('l’état du spip_loader distant est lisible',
	/Présent/.test(await page.locator('#loader [data-loader-bloc="etat"]').innerText()),
	(await page.locator('#loader [data-loader-bloc="etat"]').innerText()).replace(/\s+/g, ' ').trim());

// Après le dépôt, l'état doit nommer la version et la date que le script annonce
// lui-même : la date du fichier ne dit que le jour où on l'a posé.


page.once('dialog', (d) => d.accept());
await page.locator('#loader form.bouton_action_post button').first().click();
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(2500);
dit('spip_loader.php est déposé à la racine du site', existsSync(site + '/spip_loader.php'));
dit('la version déposée est annoncée',
	/8\.0\.5/.test(await page.locator('body').innerText()),
	(await page.locator('.reponse_formulaire').first().innerText().catch(() => '—')).trim());
dit('le dépôt revient sous son encadré', page.url().includes('#loader'), page.url());

// Un second dépôt met l'ancien de côté plutôt que de l'effacer.
page.once('dialog', (d) => d.accept());
await page.locator('#loader form.bouton_action_post button').first().click();
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(2500);
await page.locator('[data-dashboard-loader-etat]').click();
await page.waitForTimeout(2000);
const etatLoader = (await page.locator('#loader [data-loader-bloc="etat"]').innerText()).replace(/\s+/g, ' ');
dit('la version annoncée par le script est lue', /8\.0\.5/.test(etatLoader), etatLoader.trim());
dit('la date de publication du script est lue', /2026-09-04/.test(etatLoader), etatLoader.trim());

dit('l’ancien spip_loader est conservé',
	readdirSync(site).some((n) => /^\.spip_loader\.php\.dashagent-\d{14}$/.test(n)),
	readdirSync(site).filter((n) => /spip_loader/.test(n)).join(', '));

// Et ce qui n'est pas un spip_loader ne s'installe pas.
writeFileSync(site + '/core-archives/faux.txt', "<?php\nunlink(__FILE__);\n");
await page.goto(base + '/ecrire/?exec=configurer_dashboard', { waitUntil: 'domcontentloaded' });
await page.fill('[name="url_spip_loader"]', base + '/core-archives/faux.txt');
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').first().click()]);
await page.waitForTimeout(600);
const avantFaux = readFileSync(site + '/spip_loader.php', 'utf8');
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
page.once('dialog', (d) => d.accept());
await page.locator('#loader form.bouton_action_post button').first().click();
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(2000);
dit('un fichier qui n’est pas un spip_loader est refusé',
	readFileSync(site + '/spip_loader.php', 'utf8') === avantFaux
		&& /ressemble|PHP/i.test(await page.locator('body').innerText()),
	(await page.locator('.reponse_formulaire').first().innerText().catch(() => '—')).trim());

/* Le retrait du spip_loader : c'est le fichier le plus dangereux d'un site SPIP,
   et il n'a de raison d'être que le jour où l'on s'en sert. Supprimé pour les
   mêmes raisons que le spip_check, et retéléchargeable d'un clic. */
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
await page.goto(base + '/ecrire/?exec=configurer_dashboard', { waitUntil: 'domcontentloaded' });
await page.fill('[name="url_spip_loader"]', base + '/core-archives/spip_loader.txt');
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').first().click()]);
await page.waitForTimeout(600);
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
page.once('dialog', (d) => d.accept());
await page.locator('#loader form.bouton_action_post').first().locator('button').click();
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(2500);
dit('le spip_loader est de nouveau en place avant le test de retrait',
	existsSync(site + '/spip_loader.php'));

await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
page.once('dialog', (d) => d.accept());
await page.locator('#loader form.bouton_action_post').last().locator('button').click();
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(2000);
dit('spip_loader.php n’est plus à la racine', !existsSync(site + '/spip_loader.php'));
dit('le retrait du spip_loader revient sous son encadré', page.url().includes('#loader'), page.url());
dit('un retrait sans rien à retirer n’est pas une erreur (spip_loader)',
	await (async () => {
		page.once('dialog', (d) => d.accept());
		await page.locator('#loader form.bouton_action_post').last().locator('button').click();
		await page.waitForLoadState('domcontentloaded').catch(() => {});
		await page.waitForTimeout(1500);
		return /Aucun spip_loader/i.test(await page.locator('body').innerText());
	})(),
	(await page.locator('.reponse_formulaire').first().innerText().catch(() => '—')).trim());

/* Et le ménage : `glob('*.dashagent-…')` n'atteint pas un nom commençant par un
   point. Les anciens spip_loader écartés s'accumulaient donc indéfiniment à la
   racine, invisibles. Le balayage nomme désormais les deux orthographes. */
const entretien = readFileSync(
	site + '/' + readdirSync(site + '/plugins').filter((d) => /^tourdecontrole_agent-/.test(d))[0]
		.replace(/^/, 'plugins/') + '/genie/dashagent_entretien.php', 'utf8');
dit('le ménage de la racine nomme aussi les fichiers cachés',
	/glob\(\$racine \. '\.\*\.dashagent/.test(entretien));

console.log('\n### Contrôle d’intégrité (spip_check.php)');

/* Un livrable de test, servi en .txt pour la même raison que le spip_loader :
   le serveur exécuterait un .php au lieu d'en rendre la source.

   Sa forme reprend celle du vrai : les bibliothèques embarquées occupent tout
   le début du fichier, et les deux fonctions que le gabarit engendre viennent
   après. C'est ce qui oblige l'agent à relire la **queue** du fichier, et non
   son en-tête comme pour le spip_loader. Le remplissage le vérifie. */
const checkSource = site + '/core-archives/spip_check.txt';
writeFileSync(checkSource,
	"<?php\n\nnamespace Jfcherng\\Utility {\n\tclass MbString extends \\ArrayObject {}\n}\n\n"
	+ '/* ' + 'x'.repeat(300000) + " */\n\n"
	+ "function spipCheckToolVersion(): string {\n\treturn '2.4.0';\n}\n\n"
	+ "function spipCheckEdition(): string {\n\treturn 'complete';\n}\n");

await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
dit('l’encadré de SPIP Check est présent', (await page.locator('#check').count()) === 1);

/* Le réglage porte une adresse par défaut, celle de la forge. On la vide le
   temps de vérifier le cas contraire : sans adresse, aucun bouton de dépôt —
   une fonction qui échouerait ne se propose pas. Ce cas se produit pour de bon
   dès qu'un parc préfère un miroir et efface la valeur avant de la remplacer. */
await page.goto(base + '/ecrire/?exec=configurer_dashboard', { waitUntil: 'domcontentloaded' });
dit('le réglage porte l’adresse de la forge par défaut',
	/git\.spip\.net/.test(await page.inputValue('[name="url_spip_check"]')),
	await page.inputValue('[name="url_spip_check"]'));
await page.fill('[name="url_spip_check"]', '');
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').first().click()]);
await page.waitForTimeout(600);

await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
dit('sans adresse réglée, aucun dépôt n’est proposé',
	(await page.locator('#check form.bouton_action_post').count()) === 0
		&& /adresse de téléchargement/i.test(await page.locator('#check').innerText()),
	(await page.locator('#check').innerText()).replace(/\s+/g, ' ').slice(0, 80));

await page.goto(base + '/ecrire/?exec=configurer_dashboard', { waitUntil: 'domcontentloaded' });
await page.fill('[name="url_spip_check"]', base + '/core-archives/spip_check.txt');
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').first().click()]);
await page.waitForTimeout(600);

await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
// Un lien de navigation vers l'outil chez le site géré, pas une action.
const lienCheck = page.locator('#check a.btn[target="_blank"]');
dit('un lien mène au spip_check du site',
	(await lienCheck.count()) === 1
		&& /\/spip_check\.php$/.test((await lienCheck.getAttribute('href')) || ''),
	(await lienCheck.getAttribute('href')) || 'absent');

// Refusé tant que le site géré ne l'a pas accordé — et sous une autorisation
// qui lui est propre : op_loader, déjà accordé plus haut, ne l'ouvre pas.
page.once('dialog', (d) => d.accept());
await page.locator('#check form.bouton_action_post button').first().click();
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(1500);
dit('le dépôt est refusé tant que le site ne l’autorise pas',
	/désactivée|autoris/i.test(await page.locator('body').innerText())
		&& !existsSync(site + '/spip_check.php'),
	existsSync(site + '/spip_check.php') ? 'fichier déposé malgré le refus' : 'refus');

await page.goto(base + '/ecrire/?exec=configurer_dashagent', { waitUntil: 'domcontentloaded' });
await page.check('[name="op_check"]').catch(() => {});
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').last().click()]);
await page.waitForTimeout(600);

await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
await page.locator('[data-dashboard-check-etat]').click();
await page.waitForTimeout(2000);
const avantDepot = (await page.locator('#check [data-check-bloc="etat"]').innerText()).replace(/\s+/g, ' ');
dit('l’état annonce l’absence avant dépôt', /Présent\s*:\s*non/i.test(avantDepot), avantDepot.trim());

page.once('dialog', (d) => d.accept());
await page.locator('#check form.bouton_action_post').first().locator('button').click();
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(2500);
dit('spip_check.php est déposé à la racine du site', existsSync(site + '/spip_check.php'));
dit('la version et l’édition déposées sont annoncées',
	/2\.4\.0/.test(await page.locator('body').innerText())
		&& /complete/.test(await page.locator('body').innerText()),
	(await page.locator('.reponse_formulaire').first().innerText().catch(() => '—')).trim());
dit('le dépôt revient sous son encadré', page.url().includes('#check'), page.url());

await page.locator('[data-dashboard-check-etat]').click();
await page.waitForTimeout(2000);
const etatCheck = (await page.locator('#check [data-check-bloc="etat"]').innerText()).replace(/\s+/g, ' ');
// La version est tout à la fin d'un fichier de 300 Ko : c'est la queue relue
// qui la trouve, pas l'en-tête.
dit('la version est lue dans la queue du fichier', /2\.4\.0/.test(etatCheck), etatCheck.trim());
dit('l’édition du livrable est lue', /complete/.test(etatCheck), etatCheck.trim());
// Sans fichier de configuration, SPIP Check n'ouvre son interface qu'à
// l'auteur nº 1 du site géré : le dire évite un clic pour rien.
dit('l’accès à l’outil est annoncé', /Acc[eè]s/i.test(etatCheck), etatCheck.trim());

// Un second dépôt met l'ancien de côté plutôt que de l'effacer.
page.once('dialog', (d) => d.accept());
await page.locator('#check form.bouton_action_post').first().locator('button').click();
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(2500);
/* L'écart n'était qu'un filet le temps d'écrire : la nouvelle version en place,
   l'ancienne est effacée. Un script dont le nom commence par un point est
   précisément ce que SPIP Check signale comme dissimulation — lui laisser sa
   propre dépouille sous ce nom serait lui fabriquer son constat. */
dit('un second dépôt ne laisse pas d’ancien fichier caché',
	!readdirSync(site).some((n) => /^\.spip_check\.php\.dashagent-/.test(n)),
	readdirSync(site).filter((n) => /spip_check/.test(n)).join(', ') || 'seul le fichier en place');

// Ce qui n'est pas un spip_check ne s'installe pas — un spip_loader non plus,
// bien qu'il soit du PHP et qu'il parle de SPIP.
writeFileSync(site + '/core-archives/faux-check.txt',
	"<?php\n// spip_loader.php\n$spip_loader_version = '3.1.2';\necho 'SPIP';\n");
await page.goto(base + '/ecrire/?exec=configurer_dashboard', { waitUntil: 'domcontentloaded' });
await page.fill('[name="url_spip_check"]', base + '/core-archives/faux-check.txt');
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').first().click()]);
await page.waitForTimeout(600);
const avantFauxCheck = readFileSync(site + '/spip_check.php', 'utf8');
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
page.once('dialog', (d) => d.accept());
await page.locator('#check form.bouton_action_post').first().locator('button').click();
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(2000);
dit('un fichier qui n’est pas un spip_check est refusé',
	readFileSync(site + '/spip_check.php', 'utf8') === avantFauxCheck
		&& /ressemble|PHP/i.test(await page.locator('body').innerText()),
	(await page.locator('.reponse_formulaire').first().innerText().catch(() => '—')).trim());

/* Le retrait : cet outil n'a jamais eu vocation à rester. Supprimé, pas écarté
   sous un nom caché — il se retélécharge d'un clic, et un nom commençant par un
   point est ce qu'un contrôle d'intégrité signale comme dissimulation. */
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
await page.locator('#check form.bouton_action_post').last().locator('button').click();
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(2000);
dit('spip_check.php n’est plus à la racine', !existsSync(site + '/spip_check.php'));
dit('il ne reste aucune dépouille cachée',
	!readdirSync(site).some((n) => /spip_check/.test(n)),
	readdirSync(site).filter((n) => /spip_check/.test(n)).join(', ') || 'aucune');

await page.locator('[data-dashboard-check-etat]').click();
await page.waitForTimeout(2000);
dit('l’état confirme l’absence après retrait',
	/Présent\s*:\s*non/i.test((await page.locator('#check [data-check-bloc="etat"]').innerText()).replace(/\s+/g, ' ')),
	(await page.locator('#check [data-check-bloc="etat"]').innerText()).replace(/\s+/g, ' ').trim());

// Un retrait sur un site qui n'a rien n'est pas une erreur.
await page.locator('#check form.bouton_action_post').last().locator('button').click();
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(1500);
dit('un retrait sans rien à retirer n’est pas une erreur',
	/Aucun spip_check/i.test(await page.locator('body').innerText()),
	(await page.locator('.reponse_formulaire').first().innerText().catch(() => '—')).trim());

console.log('\n### Un site derrière un htpasswd');

/* Le cas réel : le serveur répond 401 **avant que PHP ne s'exécute**, donc la
   signature du protocole n'y peut rien — le code qui la vérifie n'est jamais
   atteint. `php -S` ne sait pas faire de htpasswd ; ce gardien le simule à
   l'identique du point de vue du client : refus sec avant tout traitement, et
   passage à l'agent une fois les identifiants reconnus.

   On lit `HTTP_AUTHORIZATION` plutôt que `PHP_AUTH_USER`, que le serveur
   intégré ne peuple pas toujours. */
writeFileSync(site + '/gardien.php',
	"<?php\n"
	+ "$entete = $_SERVER['HTTP_AUTHORIZATION'] ?? '';\n"
	+ "$attendu = 'Basic ' . base64_encode('jean:ouvre-toi');\n"
	+ "if ($entete !== $attendu) {\n"
	+ "\theader('WWW-Authenticate: Basic realm=\"prive\"');\n"
	+ "\thttp_response_code(401);\n"
	+ "\techo 'Authorization Required';\n"
	+ "\texit;\n"
	+ "}\n"
	+ "$_GET['action'] = 'dashagent';\n"
	+ "require __DIR__ . '/spip.php';\n");

// Le gardien répond bien 401 à qui ne s'annonce pas — sinon le test qui suit
// ne prouverait rien.
const sansAuth = await page.evaluate(async (u) => {
	const r = await fetch(u, { method: 'POST' });
	return r.status;
}, base + '/gardien.php?action=dashagent');
dit('le gardien refuse une requête sans identifiants', sansAuth === 401, String(sansAuth));

/* L'adresse garde son `action=dashagent` : le formulaire de la fiche refuse une
   url_agent qui ne le porte pas, et il a raison — c'est la garde qui attrape
   l'erreur classique, coller l'adresse du site au lieu de celle de l'agent. Le
   gardien, lui, ignore la requête et pose l'action lui-même. */
const urlGardien = base + '/gardien.php?action=dashagent';
sqlEcrire("UPDATE spip_dashboard_sites SET url_agent = '" + urlGardien + "' WHERE id_dashboard_site = 1");

// Sans identifiants enregistrés, la synchronisation échoue — et le message le
// dit, plutôt que de laisser croire à une panne de l'agent.
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
await bouton(page, 'Synchroniser');
const apres401 = JSON.parse(sql('SELECT etat, erreur FROM spip_dashboard_sites WHERE id_dashboard_site = 1'))[0];
dit('sans identifiants, le site est en erreur', apres401.etat === 'erreur', apres401.etat);
dit('le 401 est rapporté tel quel', /401/.test(apres401.erreur), (apres401.erreur || '').slice(0, 80));

// On les renseigne par le formulaire, comme le ferait un humain.
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1&modifier=oui', { waitUntil: 'domcontentloaded' });
dit('le formulaire garde l’adresse du gardien',
	(await page.inputValue('[name="url_agent"]')) === urlGardien,
	await page.inputValue('[name="url_agent"]'));
await page.fill('[name="auth_user"]', 'jean');
await page.fill('[name="auth_pass_clair"]', 'ouvre-toi');
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').last().click()]);
await page.waitForTimeout(800);

const enBase = JSON.parse(sql('SELECT auth_user, auth_pass FROM spip_dashboard_sites WHERE id_dashboard_site = 1'))[0];
dit('le nom d’utilisateur est enregistré en clair', enBase.auth_user === 'jean', enBase.auth_user);
// Le mot de passe porte sa marque de stockage — chiffré si le core fournit sa
// clef, marqué `p0:` sinon, jamais écrit nu.
dit('le mot de passe porte sa marque de stockage',
	/^(c2|p0):/.test(enBase.auth_pass || ''), (enBase.auth_pass || '').slice(0, 3));

// Et le franchissement réel de la porte.
sqlEcrire("UPDATE spip_dashboard_sites SET date_sync_ok = '2020-01-01 00:00:00'");
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
await bouton(page, 'Synchroniser');
const apresAuth = JSON.parse(sql(
	'SELECT etat, date_sync_ok, version_spip FROM spip_dashboard_sites WHERE id_dashboard_site = 1'))[0];
dit('avec les identifiants, la synchronisation passe le htpasswd',
	apresAuth.etat === 'ok' && apresAuth.date_sync_ok > '2020-01-01 00:00:00',
	`${apresAuth.etat} — ${apresAuth.date_sync_ok}`);
dit('et l’inventaire est bien celui du site', /^\d+\./.test(apresAuth.version_spip || ''), apresAuth.version_spip);

// Le formulaire ne réaffiche jamais le mot de passe.
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1&modifier=oui', { waitUntil: 'domcontentloaded' });
dit('le mot de passe n’est jamais réinjecté dans le formulaire',
	(await page.inputValue('[name="auth_pass_clair"]')) === ''
		&& !(await page.locator('body').innerHTML()).includes('ouvre-toi'));
// Et un enregistrement sans toucher au champ le conserve.
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').last().click()]);
await page.waitForTimeout(800);
dit('un enregistrement sans rien saisir conserve le mot de passe',
	(JSON.parse(sql('SELECT auth_pass FROM spip_dashboard_sites WHERE id_dashboard_site = 1'))[0].auth_pass || '') !== '');

// Le retrait des identifiants referme la porte.
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1&modifier=oui', { waitUntil: 'domcontentloaded' });
await page.check('[name="auth_retirer"]');
await Promise.all([page.waitForLoadState('domcontentloaded'), page.locator('form input[type=submit]').last().click()]);
await page.waitForTimeout(800);
const retire = JSON.parse(sql('SELECT auth_user, auth_pass FROM spip_dashboard_sites WHERE id_dashboard_site = 1'))[0];
dit('le retrait efface les deux champs',
	(retire.auth_user || '') === '' && (retire.auth_pass || '') === '',
	`user=${retire.auth_user || '(vide)'} pass=${(retire.auth_pass || '(vide)').slice(0, 3)}`);
// Et la porte se referme pour de bon : sans identifiants, le gardien refuse.
sqlEcrire("UPDATE spip_dashboard_sites SET url_agent = '" + urlGardien + "' WHERE id_dashboard_site = 1");
await page.goto(base + '/ecrire/?exec=dashboard_site&id_dashboard_site=1', { waitUntil: 'domcontentloaded' });
await bouton(page, 'Synchroniser');
dit('après retrait, le site redevient injoignable',
	JSON.parse(sql('SELECT etat FROM spip_dashboard_sites WHERE id_dashboard_site = 1'))[0].etat === 'erreur');

// On remet le site sur son agent direct : la suite du parcours n'a pas à
// traverser le gardien.
sqlEcrire("UPDATE spip_dashboard_sites SET url_agent = '" + base + "/spip.php?action=dashagent' WHERE id_dashboard_site = 1");

console.log('\n### Restauration du dump');
try {
	const verdict = execFileSync('php', ['-r', `
		$dir = getenv('SITE') . '/tmp/dashboard/sauvegardes';
		$f = null;
		foreach (glob($dir . '/*/*.sql.gz') ?: [] as $c) { $f = $c; }
		if (!$f) { echo 'AUCUN_FICHIER'; exit; }
		/* Pas gzdecode() : il ne rend que le premier membre, et une sauvegarde
		   découpée en porte un par tranche. La base restaurée serait amputée
		   sans qu'aucune erreur ne le dise. */
		$brut = file_get_contents($f);
		$sql = ''; $depart = 0;
		while ($depart < strlen($brut)) {
			$ctx = inflate_init(ZLIB_ENCODING_GZIP);
			$morceau = @inflate_add($ctx, substr($brut, $depart));
			if ($morceau === false) { echo 'REFUS_ZLIB'; exit; }
			$sql .= $morceau;
			$lu = (int) inflate_get_read_len($ctx);
			if ($lu <= 0) { break; }
			$depart += $lu;
		}
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
