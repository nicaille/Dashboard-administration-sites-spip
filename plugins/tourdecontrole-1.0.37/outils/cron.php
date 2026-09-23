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
 *     php cron.php [--duree=120] [--tours=20] [--taches=a,b] [--verbeux]
 *
 * Là où la tâche planifiée n'accepte qu'un chemin — OVH mutualisé —, les
 * mêmes options se posent dans un `cron.conf` voisin. Voir `cron_reglages()`.
 *
 * `--duree` borne le temps total en secondes, `--tours` le nombre d'appels à
 * `cron()` — les deux, parce qu'un tour qui ne trouve rien à faire ne coûte
 * rien et que le temps ne le bornerait donc pas. Le script s'arrête de
 * lui-même dès que la file annonce n'avoir plus rien d'échu. Choisir une durée
 * plus courte que ce que l'hébergeur accorde à une tâche planifiée.
 *
 * `--taches` **force** les tâches nommées à chaque tour, au lieu d'attendre
 * leur échéance. C'est la réponse à un hébergeur qui ne descend pas sous
 * l'heure — OVH mutualisé, notamment. Une tâche déclarée toutes les deux
 * minutes n'y passerait qu'une fois par heure : la forcer permet à un seul
 * déclenchement horaire de la rejouer pendant toute la durée accordée.
 *
 *     php cron.php --duree=300 --taches=dashboard_chantiers
 *
 * À réserver aux tâches qu'on sait courtes et sans effet quand il n'y a rien
 * à faire. `dashboard_chantiers` est le cas d'espèce : elle rend la main
 * aussitôt tant qu'aucune mise à jour n'a été laissée en plan.
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

$options = cron_options(array_merge(cron_reglages(__FILE__), $argv ?? []));

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

// Les tâches forcées, au format qu'attend `cron()` : nom => période. La
// période ne sert qu'à composer l'argument « date du dernier passage » donné
// au génie ; nos tâches l'ignorent, mais la clef doit exister.
//
// Un nom sans `genie/<nom>.php` derrière lui **tue le passage** : la file
// appelle `charger_fonction($nom, 'genie', false)`, dont le troisième argument
// à faux veut dire « meurs si tu ne trouves pas ». SPIP rend alors sa page
// d'erreur HTML sur la sortie standard — que l'hébergeur poste par courriel —
// et aucune des tâches suivantes ne passe. On vérifie donc d'abord, avec le
// même appel mais en mode tolérant.
$forcees = [];
foreach ($options['taches'] as $tache) {
	if (!charger_fonction($tache, 'genie', true)) {
		fwrite(STDERR, '[cron] tâche inconnue, ignorée : ' . $tache
			. " (aucun genie/$tache.php)\n");
		continue;
	}
	$forcees[$tache] = 60;
}

if ($options['taches'] && !$forcees) {
	fwrite(STDERR, "[cron] aucune des tâches demandées n'existe : rien à forcer.\n");
}
if ($forcees) {
	cron_dire($options, 'tâches forcées : ' . implode(', ', array_keys($forcees)));
}

// Deux bornes, et non une seule. Le temps ne suffit pas : un tour qui ne trouve
// rien à faire coûte une milliseconde, et la boucle en a enchaîné soixante-huit
// mille en vingt-cinq secondes lors du premier essai. Le nombre de tours borne
// ce que le temps ne borne pas.
while (time() < $echeance && $tours < $options['tours']) {

	// La file compare les dates des travaux à `$_SERVER['REQUEST_TIME']`, et
	// non à `time()`. Posée une fois pour toutes à l'amorçage, cette « heure »
	// ne bougerait plus du processus entier : après trois minutes de
	// synchronisation, la file annoncerait encore un prochain travail « dans
	// six secondes » — six secondes passées depuis longtemps —, et le script
	// s'arrêterait en laissant le reste du parc pour la fois d'après. Sous le
	// web la question ne se pose pas : un hit, une requête, une heure.
	$_SERVER['REQUEST_TIME'] = time();

	$prochain = cron_prochain();
	$attente  = ($prochain === null) ? null : ($prochain - time());

	// On redit à SPIP ce que la base vient de nous apprendre : sa statique
	// ment dans un processus qui dure, et `queue_schedule()` s'en sert pour
	// décider si elle daigne travailler. Sans cette injection, la file sortait
	// sans rien faire alors que des travaux étaient échus — cinquante tours en
	// sept secondes, et pas un seul génie exécuté.
	if ($prochain !== null) {
		queue_sleep_time_to_next_job($prochain);
	}

	// `null` : aucune tâche planifiée du tout. Cela n'arrive que sur un SPIP
	// sans génie — il n'y a alors rien à attendre, et rien à faire.
	if (!$forcees && $attente === null) {
		cron_dire($options, 'aucune tâche planifiée');
		break;
	}

	// Quand des tâches sont forcées, l'échéance ne nous concerne pas : on les
	// rejoue quoi qu'elle dise, c'est tout l'objet de `--taches`.
	if (!$forcees && $attente > 0) {
		$reste = $echeance - time();

		// **On attend, au lieu de rendre la main.** C'est à cela que sert une
		// durée : le script sortait en annonçant « prochain travail dans 120 s »
		// alors qu'il lui restait cinq minutes de budget, et ne faisait plus
		// rien de l'heure. Sur un hébergeur qui ne déclenche qu'une fois par
		// heure, une tâche déclarée toutes les deux minutes n'y passait donc
		// qu'une seule fois, quand le budget en permettait plusieurs.
		if ($attente < $reste - 1) {
			cron_dire($options, 'prochain travail dans ' . $attente . ' s, on patiente');
			sleep($attente + 1);
			continue;
		}

		cron_dire($options, 'rien à faire avant ' . $attente . ' s, au-delà du budget');
		break;
	}

	$tours++;
	cron_dire($options, 'tour ' . $tours . ' (attente ' . var_export($attente, true) . ')');

	// Un génie tiers qui explose ne doit pas emporter les nôtres. SPIP réinsère
	// le travail au statut « en cours » *avant* de l'exécuter, et ne le repasse
	// à « planifié » qu'une fois fini : un travail mort en route n'est donc pas
	// repris au tour suivant, et la boucle ne peut pas s'y enfermer. Rencontré
	// dès le premier essai — le génie de SVP a levé une `ValueError` sur un
	// catalogue injoignable, et sans cette reprise la synchronisation du parc
	// n'aurait jamais eu lieu.
	$avant = time();
	try {
		cron($forcees);
	} catch (Throwable $e) {
		fwrite(STDERR, '[cron] travail interrompu : ' . get_class($e) . ' — '
			. $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n");
		$interrompus++;
	}

	// Une tâche forcée qui n'a rien à faire rend la main tout de suite : sans
	// cette pause, les tours prévus seraient tous consommés dans la seconde et
	// le budget de temps ne servirait à rien. On ne dort pas au-delà de
	// l'échéance, et jamais sans tâche forcée — là, un tour immédiat signifie
	// qu'il reste du travail échu.
	if ($forcees && (time() - $avant) < 1 && time() < ($echeance - 1)) {
		sleep(1);
	}
}

cron_dire($options, $tours . ' tour(s) en ' . (time() - $depart) . ' s');

// Un code de sortie non nul signale la casse à l'hébergeur, qui la fait
// remonter dans son rapport. Un cron muet qui échoue tous les jours est pire
// que pas de cron du tout.
exit($interrompus ? 1 : 0);

/**
 * Dans combien de secondes le prochain travail planifié ?
 *
 * On interroge la base plutôt que `queue_sleep_time_to_next_job()`, et ce
 * n'est pas de la défiance : **cette fonction-là ment dans un processus qui
 * dure.** `queue_update_next_job_time()` garde la date du prochain travail
 * dans une statique (`static $next`) qu'elle ne recalcule que si elle est
 * nulle, puis la réécrit dans son fichier d'échéance. Sous le web la question
 * ne se pose pas — un processus par requête, donc une statique neuve à chaque
 * fois. Dans une boucle en ligne de commande, elle gèle à sa première valeur,
 * qui devient une date passée, et la file se déclare « en retard » pour
 * toujours.
 *
 * Observé en vrai : vingt tours en une seconde, sans un seul travail échu.
 * C'est la même famille que l'heure figée de `$_SERVER['REQUEST_TIME']`.
 *
 * `queue_schedule()` s'en sert aussi pour un test d'entrée — elle sort sans
 * rien faire quand la statique annonce un travail futur. Il ne suffit donc pas
 * de lire la vérité en base pour soi : il faut la **redire à SPIP** avant
 * chaque appel, sans quoi la file se croit à jour et ne travaille pas.
 *
 * @return int|null Horodatage absolu du prochain travail, null si rien n'est planifié
 */
function cron_prochain() {
	include_spip('inc/queue');
	include_spip('base/abstract_sql');

	$date = sql_getfetsel(
		'date',
		'spip_jobs',
		'status=' . intval(_JQ_SCHEDULED),
		'',
		'date',
		'0,1'
	);

	if (!$date) {
		return null;
	}

	return strtotime($date);
}

/**
 * Lit les réglages d'un fichier voisin, pour les hébergeurs sans arguments.
 *
 * Certaines tâches planifiées n'acceptent **qu'un chemin de fichier** — celles
 * des hébergements mutualisés d'OVH, notamment. Impossible d'y écrire
 * `cron.php --duree=300` : la ligne ne prend que `www/cron.php`.
 *
 * On lit donc les mêmes options dans un fichier portant le nom du script suivi
 * de `.conf`. Pour un `cron.php` posé à la racine, ce sera `cron.conf` à côté :
 *
 *     # une option par ligne, le tiret double est facultatif
 *     duree=300
 *     tours=200
 *
 * Ce que la ligne de commande passe l'emporte, quand les deux existent : un
 * essai à la main ne doit pas être contredit par un fichier oublié là.
 *
 * Le fichier ne contient que des durées et des noms de tâches — rien qui doive
 * rester secret, ce qui compte puisqu'il vit dans l'espace web.
 *
 * @param string $script Chemin du script courant
 * @return array Arguments, au format de la ligne de commande
 */
function cron_reglages($script) {
	$fichier = preg_replace('/\.php$/i', '', (string) $script) . '.conf';
	if (!is_readable($fichier)) {
		return [];
	}

	$arguments = [];
	foreach (file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
		$ligne = trim($ligne);
		// Les commentaires et les lignes vides, pour qu'un fichier de réglages
		// puisse s'expliquer lui-même.
		if ($ligne === '' || $ligne[0] === '#' || $ligne[0] === ';') {
			continue;
		}
		$arguments[] = (strpos($ligne, '--') === 0) ? $ligne : ('--' . $ligne);
	}

	return $arguments;
}

/**
 * Lit les options de la ligne de commande.
 *
 * @param array $argv
 * @return array{duree: int, tours: int, taches: array, verbeux: bool}
 */
function cron_options($argv) {
	$options = ['duree' => 120, 'tours' => 20, 'taches' => [], 'verbeux' => false];

	foreach ($argv as $argument) {
		if (preg_match('/^--duree=(\d+)$/', (string) $argument, $m)) {
			$options['duree'] = max(1, (int) $m[1]);
		} elseif (preg_match('/^--tours=(\d+)$/', (string) $argument, $m)) {
			$options['tours'] = max(1, (int) $m[1]);
		} elseif (preg_match('/^--taches=(.+)$/', (string) $argument, $m)) {
			// Des noms de génies, rien d'autre : ce qui est passé ici finit en
			// nom de fonction appelée par la file.
			foreach (preg_split('/[\s,]+/', $m[1], -1, PREG_SPLIT_NO_EMPTY) as $tache) {
				if (preg_match('/^[a-z0-9_]+$/i', $tache)) {
					$options['taches'][] = $tache;
				}
			}
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
