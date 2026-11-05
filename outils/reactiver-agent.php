<?php
/**
 * Réactive l'agent sur un site géré, après une mise à jour qui l'a désactivé.
 *
 * À déposer **sur le site géré**, pas sur la tour de contrôle.
 *
 * Pourquoi il existe. Les dossiers de plugins portent leur version, et SVP
 * remplace `plugins/auto/<prefixe>/v<ancienne>` par la nouvelle **en effaçant
 * l'ancienne**. SPIP note le *dossier* d'un plugin actif, pas son préfixe : il
 * désigne donc un dossier disparu, et son recalcul écarte tout dossier absent.
 * Le plugin se retrouve **désactivé par son propre changement de nom** — et
 * pour l'agent, c'est le site qui sort du parc, puisque plus rien ne répond.
 *
 * L'agent 1.0.29 déclare le nouveau dossier et clôt le sujet. Mais le correctif
 * vit **dans le code qui exécute la mise à jour** : c'est donc l'agent *d'avant*
 * qui mène sa propre montée de version, et celle-là se désactive encore. Ce
 * script est le passage de relais, à jouer une fois par site.
 *
 * Ce qu'il fait, et rien d'autre :
 *
 * 1. cherche sur le **disque** le dossier qui porte le préfixe de l'agent — pas
 *    dans la liste des plugins actifs, qui est justement ce qui est faux ;
 * 2. ne croit pas le nom du dossier : c'est son `paquet.xml` qui dit quel
 *    préfixe il porte, et entre deux candidats on retient la version la plus
 *    élevée, puisque c'est ce que SPIP fera de toute façon ;
 * 3. déclare ce dossier à SPIP (`ecrire_plugin_actifs`, mode « ajoute », qui
 *    **ajoute** à la liste existante et ne désactive rien) ;
 * 4. vérifie que le fichier d'action est de nouveau atteignable, et le dit.
 *
 * **Il écrit**, contrairement aux sondes voisines : il modifie la liste des
 * plugins actifs du site. Il ne touche à rien d'autre — aucun fichier, aucune
 * configuration, aucune donnée. Par le navigateur, il exige une session de
 * **webmestre** du site.
 *
 * Usage, au choix :
 *
 *     php reactiver-agent.php                   # déposé sous la racine du site
 *     php reactiver-agent.php /chemin/du/site   # la racine nommée à la main
 *     https://<site-géré>/reactiver-agent.php   # connecté en webmestre du site
 *
 * On le dépose, on l'ouvre, on le retire.
 *
 * @package SPIP\Dashboard\Outils
 */

$agent_web = PHP_SAPI !== 'cli';
$prefixe   = 'TOURDECONTROLE_AGENT';

/**
 * La racine SPIP, en remontant depuis le fichier.
 *
 * @param string $depart
 * @return string Chaîne vide si rien n'est trouvé
 */
function reactiver_racine($depart) {
	$courant = rtrim((string) $depart, '/');

	for ($i = 0; $i < 8 && $courant !== ''; $i++) {
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
function reactiver_dire($texte) {
	global $agent_web;

	echo $agent_web ? htmlspecialchars($texte) . "\n" : $texte . "\n";
	@flush();
}

/**
 * Les dossiers du disque qui déclarent ce préfixe, avec leur version.
 *
 * Le plugin étant inactif, **aucune de ses fonctions n'est chargée** : ce script
 * porte donc sa propre recherche, volontairement courte. Deux dispositions,
 * parce qu'il y a deux installateurs — `auto/<prefixe>/v*` pour SVP, un dossier
 * plat pour celui de l'agent.
 *
 * @param string $racine_plugins
 * @param string $prefixe
 * @return array<string, string> Chemin relatif => version déclarée
 */
function reactiver_candidats($racine_plugins, $prefixe) {
	$candidats = [];
	$motifs = [
		$racine_plugins . 'auto/' . strtolower($prefixe) . '/*',
		$racine_plugins . '*',
	];

	foreach ($motifs as $motif) {
		foreach ((array) glob($motif, GLOB_ONLYDIR) as $chemin) {
			$paquet = $chemin . '/paquet.xml';
			if (!is_file($paquet)) {
				continue;
			}
			$xml = (string) @file_get_contents($paquet);
			if (!preg_match('/prefix\s*=\s*"([^"]+)"/i', $xml, $m)
				|| strtoupper($m[1]) !== strtoupper($prefixe)
			) {
				continue;
			}
			// Un dossier sans son fichier d'action ne servirait à rien : c'est
			// lui que la tour fait appeler, et le déclarer laisserait le site
			// injoignable en ayant l'air réparé.
			if (!is_file($chemin . '/action/dashagent.php')) {
				continue;
			}
			preg_match('/version\s*=\s*"([^"]+)"/i', $xml, $v);
			$relatif = ltrim(substr($chemin, strlen($racine_plugins)), '/');
			if ($relatif !== '') {
				$candidats[$relatif] = trim((string) ($v[1] ?? ''));
			}
		}
	}

	return $candidats;
}

$impose = (!$agent_web && isset($argv[1])) ? rtrim((string) $argv[1], '/') : '';
$racine = reactiver_racine($impose !== '' ? $impose : __DIR__);

if ($racine === '') {
	reactiver_dire('Racine SPIP introuvable' . ($impose !== '' ? ' sous ' . $impose : ' au-dessus de ' . __DIR__) . '.');
	reactiver_dire('Déposer ce script sous la racine du site géré, ou la nommer :');
	reactiver_dire('    php ' . __FILE__ . ' /chemin/du/site');
	exit(1);
}

chdir($racine);

$_SERVER += [
	'REQUEST_TIME'   => time(),
	'REQUEST_METHOD' => 'GET',
	'REQUEST_URI'    => '/',
	'SCRIPT_NAME'    => '/spip.php',
	'HTTP_HOST'      => 'localhost',
	'REMOTE_ADDR'    => '127.0.0.1',
];

// SPIP 4.4 revient en silence de `inc_version.php` sans l'autoload Composer.
if (is_readable($racine . 'vendor/autoload.php')) {
	require_once $racine . 'vendor/autoload.php';
}
include_once $racine . 'ecrire/inc_version.php';

if (!function_exists('include_spip')) {
	reactiver_dire('SPIP n’a pas pu être amorcé depuis ' . $racine . ' — vendor/autoload.php absent ?');
	exit(1);
}

if ($agent_web) {
	header('Content-Type: text/html; charset=utf-8');
	echo "<!doctype html><meta charset=\"utf-8\"><title>Réactiver l’agent</title><pre>";

	include_spip('inc/autoriser');
	if (!autoriser('webmestre')) {
		http_response_code(403);
		reactiver_dire('Réservé aux webmestres de ce site. Connectez-vous à son espace privé, puis rechargez.');
		exit(1);
	}
}

include_spip('base/abstract_sql');
spip_connect();
include_spip('inc/meta');

// `ecrire_plugin_actifs()` fait relire les `paquet.xml` au core, dont un nom de
// plugin peut porter du balisage multilingue : il le passe donc aux filtres de
// texte, qu'un script hors espace privé ne charge pas. Sans cette ligne, l'appel
// meurt sur « Call to undefined function typo() ».
include_spip('inc/texte');
include_spip('inc/filtres');
include_spip('inc/plugin');

reactiver_dire('SAPI : ' . PHP_SAPI . '   racine : ' . $racine);
reactiver_dire('SPIP ' . ($GLOBALS['spip_version_branche'] ?? '?'));
reactiver_dire(str_repeat('-', 72));
reactiver_dire('');

$dir_plugins = defined('_DIR_PLUGINS') ? _DIR_PLUGINS : 'plugins/';
$candidats = reactiver_candidats($dir_plugins, $prefixe);

if (!$candidats) {
	reactiver_dire('Aucun dossier de ' . $prefixe . ' trouvé sous ' . $dir_plugins . '.');
	reactiver_dire('');
	reactiver_dire('Les fichiers de l’agent ne sont donc pas en place : ce script ne peut');
	reactiver_dire('rien réactiver. Il faut d’abord les déposer — par le parc si un autre');
	reactiver_dire('site répond, sinon par FTP.');
	exit(1);
}

reactiver_dire('Dossiers trouvés sur le disque :');
foreach ($candidats as $dossier => $version) {
	reactiver_dire('  ' . $dossier . '   version ' . ($version !== '' ? $version : '(illisible)'));
}

// La version la plus élevée, parce que c'est celle que SPIP retiendra de toute
// façon (`plugin_valide_resume()`) : déclarer l'autre ferait raconter deux
// histoires différentes à la meta et au disque.
$retenu = '';
$haute  = '';
foreach ($candidats as $dossier => $version) {
	if ($retenu === '' || ($version !== '' && version_compare($version, $haute, '>'))) {
		$retenu = $dossier;
		$haute  = $version;
	}
}

reactiver_dire('');
reactiver_dire('Dossier déclaré : ' . $retenu . ($haute !== '' ? ' (version ' . $haute . ')' : ''));

// « ajoute » ajoute à la liste des plugins déjà actifs et n'en désactive aucun.
// Surtout pas « raz », qui prend la liste telle qu'on la fournit et couperait
// tout le reste du site.
ecrire_plugin_actifs([$prefixe => $retenu], false, 'ajoute');
lire_metas();

$actifs = $GLOBALS['meta']['plugin'] ?? '';
$actifs = is_string($actifs) ? (unserialize($actifs) ?: []) : [];
$fiche  = is_array($actifs) ? ($actifs[$prefixe] ?? null) : null;
$declare = $fiche ? rtrim((string) ($fiche['dir'] ?? ''), '/') : '';

// On ne demande pas à `find_in_path()` si le fichier d'action est atteignable :
// la liste des chemins a été bâtie à l'amorçage de **ce** processus, quand le
// plugin était encore absent, et elle y répondra donc toujours non. Ce qui se
// vérifie ici, c'est ce que la **prochaine** requête chargera — le cache de
// chemins que le recalcul vient de réécrire — et la présence du fichier sur le
// disque. Mesuré : réactivation faite, `find_in_path()` dit non dans le même
// processus et retrouve le fichier dans le suivant.
$cache = _DIR_CACHE . 'charger_plugins_chemins.php';
$vu_dans_cache = $declare !== ''
	&& is_readable($cache)
	&& strpos((string) file_get_contents($cache), $declare) !== false;
$fichier = $declare !== '' && is_file(_DIR_PLUGINS . $declare . '/action/dashagent.php');

reactiver_dire('');
reactiver_dire('RÉSULTAT');
reactiver_dire('');
reactiver_dire('  agent dans la liste des plugins actifs : ' . ($fiche ? 'oui, sur ' . ($declare ?: '?') : 'NON'));
reactiver_dire('  dossier dans le cache de chargement    : ' . ($vu_dans_cache ? 'oui' : 'NON'));
reactiver_dire('  fichier d’action présent sur le disque : ' . ($fichier ? 'oui' : 'NON'));

reactiver_dire('');
if ($fiche && $vu_dans_cache && $fichier) {
	reactiver_dire('C’est fait. Lancer une synchronisation depuis le parc pour le constater,');
	reactiver_dire('puis retirer ce script de la racine du site.');
} else {
	reactiver_dire('La réactivation n’a pas abouti. Regarder la page Gestion des plugins du');
	reactiver_dire('site : SPIP y indique pourquoi il refuse d’activer un plugin.');
}

if ($agent_web) {
	echo '</pre>';
}
