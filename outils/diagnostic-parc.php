<?php
/**
 * Chronomètre, un par un, les calculs de la vue d'ensemble du parc.
 *
 * Écrit le jour où `?exec=dashboard` a rendu une erreur 500 après une longue
 * attente, sur une tour en production. La page est en SQL pur — aucun appel
 * réseau, aucune boucle non bornée à la lecture —, si bien que rien, dans le
 * code, ne désigne le coupable. Il faut donc aller mesurer.
 *
 * Deux choix dictent toute la forme du fichier :
 *
 * - **il écrit au fil de l'eau et vide son tampon après chaque ligne.** Un
 *   rapport composé à la fin mourrait avec le processus, et c'est précisément
 *   le processus qui meurt. L'étape dont le résultat manque est la coupable ;
 * - **il rend compte depuis `register_shutdown_function()`**, seul endroit qui
 *   parle encore après un dépassement de mémoire ou de temps d'exécution. PHP
 *   n'affiche rien de lisible dans ces deux cas — le serveur web rend une 500
 *   nue —, et c'est exactement ce qu'on cherche à nommer.
 *
 * Il ne modifie rien : lectures seules, aucun appel sortant.
 *
 * Usage, au choix :
 *
 *     php diagnostic-parc.php          # déposé n'importe où sous la racine
 *     https://<tour>/diagnostic-parc.php   # connecté en webmestre
 *
 * Par le navigateur, il exige une session de **webmestre** — la même barre que
 * la page de configuration. Et il n'a pas vocation à rester en ligne : un
 * script qui récite l'état de la base n'a rien à faire en permanence à la
 * racine web, pas plus que le mégaoctet de SPIP Check. On le dépose, on lit,
 * on le retire.
 *
 * @package SPIP\Dashboard\Outils
 */

$diagnostic_etape = 'amorçage';
$diagnostic_web   = PHP_SAPI !== 'cli';

// Avant tout le reste : ce qui parlera encore quand PHP se fera tuer. Un
// dépassement de `memory_limit` ou de `max_execution_time` n'est pas une
// exception, on ne l'attrape pas ; la fonction d'extinction, elle, passe.
register_shutdown_function(function () use (&$diagnostic_etape, $diagnostic_web) {
	$fin = error_get_last();
	if (!$fin || !in_array($fin['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
		return;
	}

	$message = "\n*** MORT PENDANT : " . $diagnostic_etape . "\n"
		. '*** ' . $fin['message'] . "\n"
		. '*** ' . $fin['file'] . ':' . $fin['line'] . "\n"
		. '*** mémoire au plus haut : ' . round(memory_get_peak_usage(true) / 1048576, 1) . " Mo\n";

	echo $diagnostic_web ? '<pre>' . htmlspecialchars($message) . '</pre>' : $message;
});

/**
 * La racine SPIP, en remontant depuis le fichier.
 *
 * @param string $depart
 * @return string Chaîne vide si rien n'est trouvé
 */
function diagnostic_racine($depart) {
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
 * Une ligne de rapport, poussée hors des tampons immédiatement.
 *
 * @param string $texte
 * @return void
 */
function diagnostic_dire($texte) {
	global $diagnostic_web;

	echo $diagnostic_web ? htmlspecialchars($texte) . "\n" : $texte . "\n";

	// Trois couches de tampon peuvent retenir la ligne : celui de PHP, celui de
	// la compression, celui du serveur. Sur un script qui va mourir, une ligne
	// retenue est une ligne perdue.
	if (function_exists('ob_get_level')) {
		while (ob_get_level() > 0) {
			@ob_end_flush();
		}
	}
	@flush();
}

/**
 * Chronomètre un appel et rend compte, quoi qu'il arrive.
 *
 * @param string $nom
 * @param callable $quoi
 * @return mixed Le résultat de l'appel, ou null s'il a levé
 */
function diagnostic_mesurer($nom, $quoi) {
	global $diagnostic_etape;

	$diagnostic_etape = $nom;
	$depart = microtime(true);

	try {
		$resultat = $quoi();
		$note = diagnostic_resumer($resultat);
	} catch (\Throwable $e) {
		$resultat = null;
		$note = 'LEVÉE : ' . get_class($e) . ' — ' . $e->getMessage();
	}

	diagnostic_dire(sprintf(
		'%-42s %7.2f s  %6.1f Mo   %s',
		$nom,
		microtime(true) - $depart,
		memory_get_peak_usage(true) / 1048576,
		$note
	));

	return $resultat;
}

/**
 * Un résultat, réduit à ce qui tient sur une ligne.
 *
 * @param mixed $valeur
 * @return string
 */
function diagnostic_resumer($valeur) {
	if (is_array($valeur)) {
		return count($valeur) . ' entrée(s)';
	}
	if (is_bool($valeur)) {
		return $valeur ? 'oui' : 'non';
	}
	if (is_scalar($valeur)) {
		$texte = (string) $valeur;

		return strlen($texte) > 60 ? substr($texte, 0, 57) . '…' : $texte;
	}

	return gettype($valeur);
}

$racine = diagnostic_racine(__DIR__);
if ($racine === '') {
	diagnostic_dire('Racine SPIP introuvable au-dessus de ' . __DIR__);
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

if (is_readable($racine . 'vendor/autoload.php')) {
	require_once $racine . 'vendor/autoload.php';
}
include_once $racine . 'ecrire/inc_version.php';

if ($diagnostic_web) {
	header('Content-Type: text/html; charset=utf-8');
	echo "<!doctype html><meta charset=\"utf-8\"><title>Diagnostic du parc</title><pre>";

	// Par le navigateur, la même barre que la page de configuration. Un script
	// qui récite l'état de la base ne se laisse pas lire par un visiteur.
	include_spip('inc/autoriser');
	if (!autoriser('webmestre')) {
		http_response_code(403);
		diagnostic_dire('Réservé aux webmestres. Connectez-vous à l’espace privé, puis rechargez.');
		exit(1);
	}
}

// `ecrire/inc_version.php` amorce SPIP, il n'ouvre pas la base et ne charge pas
// les fonctions des plugins : hors squelette, personne ne le fait pour nous.
// Sans ces trois lignes, chaque mesure rendait « fonction non définie » — ce
// que le premier essai a montré, et qui aurait fait passer la sonde pour un
// verdict alors qu'elle n'avait rien mesuré.
include_spip('base/abstract_sql');
spip_connect();

include_spip('tourdecontrole_fonctions');
include_spip('inc/dashboard_client');
include_spip('inc/dashboard_catalogue');
include_spip('inc/dashboard_versions');

if (!function_exists('dashboard_synthese')) {
	diagnostic_dire('Le plugin tourdecontrole reste introuvable : rien à mesurer.');
	exit(1);
}

diagnostic_dire('PHP ' . PHP_VERSION . '  (' . PHP_SAPI . ')');
diagnostic_dire('memory_limit = ' . ini_get('memory_limit')
	. '   max_execution_time = ' . ini_get('max_execution_time'));
diagnostic_dire(str_repeat('-', 96));

// Les volumes d'abord : ce sont eux qui expliquent une durée, et les connaître
// évite d'accuser une fonction qui n'a fait que lire ce qu'on lui a donné.
diagnostic_mesurer('volume : sites du parc', function () {
	return (int) sql_countsel('spip_dashboard_sites', 'statut != ' . sql_quote('poubelle'));
});

diagnostic_mesurer('volume : spip_paquets (catalogue tour)', function () {
	$tables = sql_alltable('%');
	if (!is_array($tables) || !in_array('spip_paquets', $tables, true)) {
		return 'table absente (SVP non installé sur la tour)';
	}

	return (int) sql_countsel('spip_paquets', 'id_depot > 0') . ' paquet(s) de dépôt';
});

diagnostic_mesurer('volume : plus gros inventaire de site', function () {
	$lignes = sql_allfetsel(
		['id_dashboard_site', 'LENGTH(infos) AS taille'],
		'spip_dashboard_sites',
		'statut = ' . sql_quote('publie'),
		'',
		'taille DESC',
		'0,1'
	);
	if (!$lignes) {
		return 'aucun';
	}

	return 'site ' . (int) $lignes[0]['id_dashboard_site']
		. ' : ' . round(((int) $lignes[0]['taille']) / 1024) . ' Ko';
});

diagnostic_dire(str_repeat('-', 96));

// Puis les calculs de la page, dans l'ordre où elle les demande.
diagnostic_mesurer('dashboard_tables_presentes()', 'dashboard_tables_presentes');
diagnostic_mesurer('dashboard_synthese()', 'dashboard_synthese');
diagnostic_mesurer('dashboard_maj_rapportees_sites()', 'dashboard_maj_rapportees_sites');
diagnostic_mesurer('dashboard_catalogue_tour()', 'dashboard_catalogue_tour');
diagnostic_mesurer('dashboard_depots_inconnus_parc()', 'dashboard_depots_inconnus_parc');
diagnostic_mesurer('dashboard_depots_parc()', 'dashboard_depots_parc');
diagnostic_mesurer('dashboard_agent_maj_parc()', 'dashboard_agent_maj_parc');
diagnostic_mesurer('dashboard_waf_parc_present()', 'dashboard_waf_parc_present');
diagnostic_mesurer('dashboard_waf_serie_parc()', 'dashboard_waf_serie_parc');

diagnostic_dire(str_repeat('-', 96));

// La file de travaux de SPIP. Elle n'appartient pas au plugin, mais notre cron
// la sollicite plus que la moyenne — une tâche déclarée à deux minutes —, et
// `queue_affichage_cron()` la consulte à la fin de **chaque** page.
diagnostic_mesurer('file : travaux en attente', function () {
	$tables = sql_alltable('%');
	if (!is_array($tables) || !in_array('spip_jobs', $tables, true)) {
		return 'table absente';
	}

	return sql_countsel('spip_jobs') . ' travaux, dont '
		. sql_countsel('spip_jobs', 'date < ' . sql_quote(date('Y-m-d H:i:s'))) . ' échu(s)';
});

diagnostic_mesurer('file : liens de travaux', function () {
	$tables = sql_alltable('%');
	if (!is_array($tables) || !in_array('spip_jobs_liens', $tables, true)) {
		return 'table absente';
	}

	return sql_countsel('spip_jobs_liens') . ' lien(s)';
});

// Le détail de la file, et non son seul décompte. Un travail resté au statut
// « en cours » est la trace d'un processus tué pendant qu'il l'exécutait :
// SPIP l'inscrit à ce statut **avant** de lancer le génie et ne le repasse à
// « planifié » qu'une fois rendu. C'est la seule chose qui, dans cette base,
// garde le souvenir d'une exécution interrompue.
diagnostic_mesurer('file : le détail', function () {
	$tables = sql_alltable('%');
	if (!is_array($tables) || !in_array('spip_jobs', $tables, true)) {
		return 'table absente';
	}

	$lignes = sql_allfetsel(
		['fonction', 'date', 'status', 'priorite'],
		'spip_jobs',
		'',
		'',
		'date'
	);

	$maintenant = time();
	foreach ((array) $lignes as $ligne) {
		$quand = strtotime((string) $ligne['date']);
		$retard = $quand ? $maintenant - $quand : 0;

		diagnostic_dire(sprintf(
			'    %-28s %-20s %-12s %s',
			(string) $ligne['fonction'],
			(string) $ligne['date'],
			((int) $ligne['status'] === 1) ? 'planifié' : 'EN COURS',
			$retard > 0 ? 'échu depuis ' . round($retard / 60) . ' min' : 'à venir'
		));
	}

	return count((array) $lignes) . ' travaux détaillés ci-dessus';
});

diagnostic_dire(str_repeat('-', 96));
diagnostic_dire('Terminé. Mémoire au plus haut : '
	. round(memory_get_peak_usage(true) / 1048576, 1) . ' Mo');

if ($diagnostic_web) {
	echo '</pre>';
}
