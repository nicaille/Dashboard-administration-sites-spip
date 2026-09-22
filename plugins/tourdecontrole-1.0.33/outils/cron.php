<?php
/**
 * Déclenche les tâches de fond de SPIP depuis une tâche planifiée.
 *
 * SPIP attend des visites : la file de travaux (`spip_jobs`) est relancée à la
 * fin de chaque hit. Sur une tour de contrôle que personne ne visite, la
 * synchronisation du parc n'a donc jamais lieu — et c'est précisément le site
 * dont on attend qu'il travaille tout seul.
 *
 * La méthode officielle est un appel HTTP à `spip.php?action=cron`, et elle
 * convient partout où l'on dispose d'un vrai cron Unix. Elle a un défaut sur un
 * hébergement mutualisé : la requête traverse le frontal, dont la patience est
 * plus courte que celle de PHP — soixante secondes chez la plupart —, et une
 * synchronisation de dix sites en prend davantage. Le frontal coupe, le travail
 * est interrompu au milieu, et rien ne le dit.
 *
 * Ce script fait la même chose **sans passer par le réseau** : il amorce SPIP
 * en ligne de commande et appelle `cron()` directement. Aucun frontal, aucun
 * `max_execution_time` de serveur web.
 *
 * Usage :
 *
 *     php cron.php [--duree=120] [--tours=20] [--verbeux]
 *
 * `--duree` borne le temps total en secondes, `--tours` le nombre d'appels à
 * `cron()` — les deux, parce qu'un tour qui ne trouve rien à faire ne coûte
 * rien et que le temps ne le bornerait donc pas. Le script s'arrête de
 * lui-même dès que la file annonce n'avoir plus rien d'échu. Choisir une durée
 * plus courte que ce que l'hébergeur accorde à une tâche planifiée.
 *
 * Le fichier vit dans l'espace web — une tâche planifiée d'hébergeur ne sait
 * exécuter que ce qui s'y trouve. Il refuse donc tout autre SAPI que la ligne
 * de commande : atteint par le navigateur, il rend 404 et ne fait rien.
 *
 * @package SPIP\Dashboard\Outils
 */

// Le garde-fou d'abord, avant d'amorcer quoi que ce soit : ce fichier est
// servi par le serveur web comme n'importe quel autre.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

$options = cron_options($argv ?? []);

$racine = cron_racine(__DIR__);
if ($racine === '') {
	fwrite(STDERR, "Racine SPIP introuvable : aucun ecrire/inc_version.php au-dessus de " . __DIR__ . "\n");
	exit(1);
}

chdir($racine);

// SPIP lit $_SERVER même hors du web. Les valeurs manquantes ne le font pas
// échouer, mais elles se retrouvent dans les journaux et dans les URL qu'il
// fabrique ; autant qu'elles soient inertes plutôt qu'absentes.
$_SERVER += [
	'REQUEST_TIME'   => time(),
	'REQUEST_METHOD' => 'GET',
	'REQUEST_URI'    => '/',
	'SCRIPT_NAME'    => '/spip.php',
	'HTTP_HOST'      => 'localhost',
	'REMOTE_ADDR'    => '127.0.0.1',
];

// `cron()` refuse de travailler sans cette constante : c'est elle qui distingue
// un déclenchement voulu d'un appel de passage. SPIP la pose lui-même quand la
// file déborde (`queue_update_next_job_time()`), d'où la garde : sans elle,
// chaque tour ajoutait un avertissement « Constant already defined ».
if (!defined('_DIRECT_CRON_FORCE')) {
	define('_DIRECT_CRON_FORCE', true);
}

cron_amorcer($racine);

if (!function_exists('cron')) {
	fwrite(STDERR, "SPIP amorcé, mais cron() reste introuvable.\n");
	exit(1);
}

$depart   = time();
$echeance = $depart + $options['duree'];
$tours = 0;
$interrompus = 0;

// Deux bornes, et non une seule. Le temps ne suffit pas : un tour qui ne trouve
// rien à faire coûte une milliseconde, et la boucle en a enchaîné soixante-huit
// mille en vingt-cinq secondes lors du premier essai. Le nombre de tours borne
// ce que le temps ne borne pas.
while (time() < $echeance && $tours < $options['tours']) {
	$tours++;

	// La file compare les dates des travaux à `$_SERVER['REQUEST_TIME']`, et
	// non à `time()`. Posée une fois pour toutes à l'amorçage, cette « heure »
	// ne bougerait plus du processus entier : après trois minutes de
	// synchronisation, la file annoncerait encore un prochain travail « dans
	// six secondes » — six secondes passées depuis longtemps —, et le script
	// s'arrêterait en laissant le reste du parc pour la fois d'après. Sous le
	// web la question ne se pose pas : un hit, une requête, une heure.
	$_SERVER['REQUEST_TIME'] = time();

	$attente = queue_sleep_time_to_next_job(true);

	// `null` n'est pas « rien à faire » : c'est « je ne sais pas », le fichier
	// d'échéance n'ayant pas encore été écrit. On va voir plutôt que de
	// conclure — et `null > 0` étant faux, s'en remettre au test suffirait
	// à passer outre sans l'avoir décidé.
	if ($attente !== null && $attente > 0) {
		cron_dire($options, 'rien à faire, prochain travail dans ' . $attente . ' s');
		break;
	}

	cron_dire($options, 'tour ' . $tours . ' (attente ' . var_export($attente, true) . ')');

	// Un génie tiers qui explose ne doit pas emporter les nôtres. SPIP réinsère
	// le travail au statut « en cours » *avant* de l'exécuter, et ne le repasse
	// à « planifié » qu'une fois fini : un travail mort en route n'est donc pas
	// repris au tour suivant, et la boucle ne peut pas s'y enfermer. Rencontré
	// dès le premier essai — le génie de SVP a levé une `ValueError` sur un
	// catalogue injoignable, et sans cette reprise la synchronisation du parc
	// n'aurait jamais eu lieu.
	try {
		cron();
	} catch (Throwable $e) {
		fwrite(STDERR, '[cron] travail interrompu : ' . get_class($e) . ' — '
			. $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n");
		$interrompus++;
	}
}

cron_dire($options, $tours . ' tour(s) en ' . (time() - $depart) . ' s');

// Un code de sortie non nul signale la casse à l'hébergeur, qui la fait
// remonter dans son rapport. Un cron muet qui échoue tous les jours est pire
// que pas de cron du tout.
exit($interrompus ? 1 : 0);

/**
 * Lit les options de la ligne de commande.
 *
 * @param array $argv
 * @return array{duree: int, tours: int, verbeux: bool}
 */
function cron_options($argv) {
	$options = ['duree' => 120, 'tours' => 20, 'verbeux' => false];

	foreach ($argv as $argument) {
		if (preg_match('/^--duree=(\d+)$/', (string) $argument, $m)) {
			$options['duree'] = max(1, (int) $m[1]);
		} elseif (preg_match('/^--tours=(\d+)$/', (string) $argument, $m)) {
			$options['tours'] = max(1, (int) $m[1]);
		} elseif ((string) $argument === '--verbeux') {
			$options['verbeux'] = true;
		}
	}

	return $options;
}

/**
 * Remonte les répertoires jusqu'à trouver une racine SPIP.
 *
 * Le fichier peut être posé à la racine du site comme dans un sous-répertoire :
 * on cherche plutôt que d'imposer un emplacement.
 *
 * @param string $depart
 * @return string Chemin avec barre oblique finale, ou chaîne vide
 */
function cron_racine($depart) {
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
 * Charge SPIP.
 *
 * SPIP 4.4 s'amorce par son autochargeur Composer, qui seul sait où se trouve
 * `ecrire/` — le répertoire est devenu un réglage. Les versions d'avant n'ont
 * ni `vendor/` ni noyau déplaçable : `ecrire/` y est un fait.
 *
 * @param string $racine
 * @return void
 */
function cron_amorcer($racine) {
	if (is_readable($racine . 'vendor/autoload.php')) {
		require_once $racine . 'vendor/autoload.php';

		if (function_exists('SpipLeague\Component\Kernel\param')) {
			$ecrire = (string) SpipLeague\Component\Kernel\param('spip.dirs.core');
			if ($ecrire !== '' && is_readable($ecrire . 'inc_version.php')) {
				include_once $ecrire . 'inc_version.php';

				return;
			}
		}
	}

	include_once $racine . 'ecrire/inc_version.php';
}

/**
 * Dit où l'on en est, si on l'a demandé.
 *
 * Sur STDERR : la sortie standard d'une tâche planifiée est souvent postée par
 * courriel, et un rapport quotidien qui n'a rien à dire n'a pas à en produire.
 *
 * @param array $options
 * @param string $message
 * @return void
 */
function cron_dire($options, $message) {
	if (!empty($options['verbeux'])) {
		fwrite(STDERR, '[cron] ' . $message . "\n");
	}
}
