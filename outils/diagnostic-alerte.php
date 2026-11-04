<?php
/**
 * Montre l'alerte telle qu'elle partirait, et d'où vient chaque adresse.
 *
 * Écrit après deux correctifs annoncés et deux courriels revenus avec un lien
 * vers `http://localhost/`. Deux corrections devinées de suite, c'est une de
 * trop : il faut savoir **quel code tourne** sur la tour et **ce qu'il lit**,
 * au lieu de raisonner sur le dépôt.
 *
 * Trois questions, et le fichier ne sert qu'à y répondre :
 *
 * 1. **quelle version de la tour s'exécute ?** Pas celle du dossier, celle que
 *    SPIP a chargée. L'existence de `dashboard_adresse_locale()` le dit sans
 *    ambiguïté : elle n'existe qu'à partir de la 1.0.44 ;
 * 2. **que valent les deux sources d'adresse ?** La meta `adresse_site`, qui ne
 *    dépend d'aucune requête, et `url_de_base()`, qui en dépend entièrement ;
 * 3. **le lien du courriel est-il le nôtre ?** Le message est enveloppé par
 *    `inc/envoyer_mail` — le plugin *Facteur*, quand il est là —, qui ajoute en
 *    propre un en-tête et une signature. Un lien ajouté par cette enveloppe ne
 *    viendrait pas de notre code, et aucun correctif de notre côté ne l'ôterait.
 *    D'où l'impression du texte **exact** que nous fabriquons : ce qui figure
 *    dans le courriel reçu et pas ici vient de l'enveloppe.
 *
 * Il ne modifie rien et n'envoie rien : lectures seules, aucun appel sortant,
 * aucun courriel. L'empreinte de la dernière alerte n'est pas touchée, donc la
 * prochaine alerte réelle partira comme si de rien n'était.
 *
 * Usage, au choix :
 *
 *     php diagnostic-alerte.php              # déposé n'importe où sous la racine
 *     https://<tour>/diagnostic-alerte.php   # connecté en webmestre
 *
 * **La ligne de commande est celle qui compte** : l'alerte part du cron, et
 * c'est là que `url_de_base()` ment. Le lancer par le navigateur montre l'autre
 * moitié du tableau — ce que la même tour voit quand une requête existe.
 *
 * Comme sa voisine `diagnostic-parc.php`, il n'a pas vocation à rester en
 * ligne : on le dépose, on lit, on le retire.
 *
 * @package SPIP\Dashboard\Outils
 */

$alerte_web = PHP_SAPI !== 'cli';

/**
 * La racine SPIP, en remontant depuis le fichier.
 *
 * @param string $depart
 * @return string Chaîne vide si rien n'est trouvé
 */
function alerte_racine($depart) {
	$courant = rtrim((string) $depart, '/');

	for ($i = 0; $i < 6 && $courant !== ''; $i++) {
		if (is_readable($courant . '/ecrire/inc_version.php')) {
			return $courant . '/';
		}
		$parent = dirname($courant);
		if ($parent === $courant) {
			break;
		}
		$courant = $parent;
	}

	return '';
}

/**
 * Une ligne de rapport.
 *
 * @param string $texte
 * @return void
 */
function alerte_dire($texte) {
	global $alerte_web;

	echo $alerte_web ? htmlspecialchars($texte) . "\n" : $texte . "\n";
	@flush();
}

/**
 * Un couple « question : réponse », aligné.
 *
 * @param string $quoi
 * @param mixed $valeur
 * @return void
 */
function alerte_ligne($quoi, $valeur) {
	if ($valeur === true) {
		$valeur = 'oui';
	} elseif ($valeur === false) {
		$valeur = 'NON';
	} elseif ($valeur === '') {
		$valeur = '(vide)';
	} elseif ($valeur === null) {
		$valeur = '(absent)';
	}

	alerte_dire(sprintf('%-46s %s', $quoi . ' :', (string) $valeur));
}

$racine = alerte_racine(__DIR__);
if ($racine === '') {
	alerte_dire('Racine SPIP introuvable au-dessus de ' . __DIR__);
	exit(1);
}

chdir($racine);

// Exactement ce que pose `outils/cron.php` pour amorcer SPIP hors du web : le
// but est de reproduire le contexte du cron, pas de l'améliorer. En ligne de
// commande, `url_de_base()` dérivera donc « localhost » — et c'est le fait
// qu'on vient mesurer, pas un défaut de la sonde.
$_SERVER += [
	'REQUEST_TIME'   => time(),
	'REQUEST_METHOD' => 'GET',
	'REQUEST_URI'    => '/',
	'SCRIPT_NAME'    => '/spip.php',
	'HTTP_HOST'      => 'localhost',
	'REMOTE_ADDR'    => '127.0.0.1',
];

if (is_readable($racine . 'vendor/autoload.php')) {
	require_once $racine . 'vendor/autoload.php';
}
include_once $racine . 'ecrire/inc_version.php';

if ($alerte_web) {
	header('Content-Type: text/html; charset=utf-8');
	echo "<!doctype html><meta charset=\"utf-8\"><title>Diagnostic de l’alerte</title><pre>";

	include_spip('inc/autoriser');
	if (!autoriser('webmestre')) {
		http_response_code(403);
		alerte_dire('Réservé aux webmestres. Connectez-vous à l’espace privé, puis rechargez.');
		exit(1);
	}
}

include_spip('base/abstract_sql');
spip_connect();

include_spip('tourdecontrole_fonctions');
include_spip('inc/dashboard_client');
include_spip('inc/dashboard_alertes');
include_spip('inc/filtres');

alerte_dire('SAPI : ' . PHP_SAPI . '   racine : ' . $racine);
alerte_dire(str_repeat('-', 78));

/* --- 1. Quel code tourne ------------------------------------------------- */

alerte_dire('');
alerte_dire('QUEL CODE TOURNE');
alerte_dire('');

$actifs = $GLOBALS['meta']['plugin'] ?? '';
$actifs = is_string($actifs) ? (unserialize($actifs) ?: []) : (array) $actifs;
$fiche  = $actifs['TOURDECONTROLE'] ?? [];

alerte_ligne('version déclarée par le plugin actif', $fiche['version'] ?? null);
alerte_ligne('dossier chargé', $fiche['dir'] ?? (defined('_DIR_PLUGIN_TOURDECONTROLE') ? _DIR_PLUGIN_TOURDECONTROLE : null));

// La question qui tranche. `dashboard_adresse_tour()` existe depuis la 1.0.43,
// `dashboard_adresse_locale()` depuis la 1.0.44 seulement : c'est elle qui
// refuse une adresse désignant la machine. Son absence veut dire que le code
// qui tourne est antérieur, quoi qu'annonce le dossier.
alerte_ligne('dashboard_adresse_tour() existe (≥ 1.0.43)', function_exists('dashboard_adresse_tour'));
alerte_ligne('dashboard_adresse_locale() existe (≥ 1.0.44)', function_exists('dashboard_adresse_locale'));

/* --- 2. Les deux sources d'adresse --------------------------------------- */

alerte_dire('');
alerte_dire('D’OÙ VIENT L’ADRESSE');
alerte_dire('');

alerte_ligne('meta adresse_site', $GLOBALS['meta']['adresse_site'] ?? null);
alerte_ligne('meta nom_site', $GLOBALS['meta']['nom_site'] ?? null);
alerte_ligne('url_de_base()', function_exists('url_de_base') ? url_de_base() : '(fonction absente)');

if (function_exists('dashboard_adresse_tour')) {
	alerte_ligne('dashboard_adresse_tour()', dashboard_adresse_tour());
}
if (function_exists('dashboard_adresse_locale')) {
	alerte_ligne('…_locale(meta)', dashboard_adresse_locale((string) ($GLOBALS['meta']['adresse_site'] ?? '')));
	alerte_ligne('…_locale(url_de_base)', dashboard_adresse_locale(function_exists('url_de_base') ? (string) url_de_base() : ''));
}
if (function_exists('dashboard_user_agent')) {
	alerte_ligne('dashboard_user_agent()', dashboard_user_agent());
}

/* --- 3. Le lien que nous fabriquons -------------------------------------- */

alerte_dire('');
alerte_dire('LE LIEN QUE NOUS FABRIQUONS');
alerte_dire('');

$base = function_exists('dashboard_adresse_tour') ? dashboard_adresse_tour() : (string) url_de_base();

alerte_ligne('generer_url_ecrire(dashboard)', generer_url_ecrire('dashboard'));
alerte_ligne('base retenue', $base);
$url_parc = ($base === '') ? '' : url_absolue(generer_url_ecrire('dashboard'), $base);
alerte_ligne('url_parc', $url_parc);

/* --- 4. L'enveloppe du courriel ------------------------------------------ */

alerte_dire('');
alerte_dire('L’ENVELOPPE DU COURRIEL');
alerte_dire('');

// `charger_fonction('envoyer_mail', 'inc')` rend la fonction du plugin Facteur
// quand il est actif, celle du core sinon. Facteur enveloppe le texte : un
// en-tête au nom du site, une signature « Envoyé par … ». Si le courriel reçu
// porte un lien que la section suivante n'imprime pas, il vient de là — et
// aucun correctif de notre côté ne l'ôtera.
alerte_ligne('plugin FACTEUR actif', isset($actifs['FACTEUR']));
alerte_ligne('version de Facteur', $actifs['FACTEUR']['version'] ?? null);

$envoyer = charger_fonction('envoyer_mail', 'inc', true);
if ($envoyer) {
	$reflet = new ReflectionFunction($envoyer);
	alerte_ligne('inc/envoyer_mail fourni par', str_replace($racine, '', (string) $reflet->getFileName()));
} else {
	alerte_ligne('inc/envoyer_mail', '(introuvable)');
}

/* --- 5. L'alerte, mot pour mot ------------------------------------------- */

alerte_dire('');
alerte_dire('L’ALERTE TELLE QUE NOUS LA COMPOSONS');
alerte_dire('');

$etat = dashboard_alerte_etat();
alerte_ligne('sites publiés', $etat['sites'] ?? 0);
alerte_ligne('alerte vide', dashboard_alerte_vide($etat));
alerte_ligne('destinataires réglés', count(dashboard_alerte_destinataires()));

$message = dashboard_alerte_texte($etat, $url_parc);

alerte_dire('');
alerte_dire('--- sujet -------------------------------------------------------');
alerte_dire($message['sujet']);
alerte_dire('--- texte (ce que NOUS écrivons, enveloppe non comprise) -------');
alerte_dire($message['texte']);
alerte_dire('--- fin ---------------------------------------------------------');
alerte_dire('');
alerte_dire('Un lien présent dans le courriel reçu mais absent du texte ci-dessus');
alerte_dire('vient de l’enveloppe (Facteur), pas de la tour.');

if ($alerte_web) {
	echo '</pre>';
}
