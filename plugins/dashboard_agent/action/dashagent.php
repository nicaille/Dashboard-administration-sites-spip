<?php
/**
 * Point d'entrée unique de l'agent Dashboard.
 *
 * URL : https://exemple.org/spip.php?action=dashagent
 *
 * Toutes les requêtes sont signées (voir docs/protocole-api.md). Ce fichier ne
 * fait que router : l'authentification est dans inc/dashagent_securite.php et
 * le travail dans les inc/ correspondants.
 *
 * @package SPIP\Dashagent\Action
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Routeur des opérations de l'agent.
 *
 * @return void
 */
function action_dashagent_dist() {
	include_spip('inc/dashagent');
	include_spip('inc/dashagent_securite');

	$debut   = microtime(true);
	$requete = dashagent_verifier_requete();
	$op      = $requete['op'];
	$args    = $requete['args'];

	// Les opérations lourdes peuvent dépasser le temps d'exécution par défaut.
	if (in_array($op, ['sauvegarde_creer', 'plugin_maj', 'core_maj'], true)) {
		@set_time_limit(600);
		@ini_set('memory_limit', '512M');
	}

	try {
		$resultat = dashagent_executer($op, $args);
	} catch (Throwable $e) {
		dashagent_journaliser($op, 'erreur', $e->getMessage(), null, dashagent_duree($debut));
		dashagent_erreur('exception', 'Erreur interne : ' . $e->getMessage(), 500);
	}

	$duree = dashagent_duree($debut);
	$ok    = !isset($resultat['ok']) || $resultat['ok'];

	dashagent_journaliser(
		$op,
		$ok ? 'ok' : 'erreur',
		(string) ($resultat['erreur'] ?? ''),
		['args' => $args],
		$duree
	);

	if (!$ok) {
		dashagent_erreur('operation_echouee', (string) ($resultat['erreur'] ?? 'Opération échouée'), 422, $resultat);
	}

	dashagent_repondre(['data' => $resultat, 'duree_ms' => $duree]);
}

/**
 * Exécute une opération déjà authentifiée et autorisée.
 *
 * @param string $op
 * @param array $args
 * @return array
 */
function dashagent_executer($op, $args) {
	switch ($op) {
		case 'ping':
			return dashagent_op_ping();

		case 'infos':
			include_spip('inc/dashagent_infos');

			return ['ok' => true, 'infos' => dashagent_infos_collecter($args)];

		case 'purger':
			include_spip('inc/dashagent_cache');
			$cibles = $args['cibles'] ?? ['tout'];
			if (!is_array($cibles)) {
				$cibles = [(string) $cibles];
			}

			return ['ok' => true, 'purge' => dashagent_purger(array_map('strval', $cibles))];

		case 'sauvegarde_creer':
			include_spip('inc/dashagent_sauvegarde');

			return dashagent_sauvegarde_creer($args);

		case 'sauvegarde_lister':
			include_spip('inc/dashagent_sauvegarde');

			return ['ok' => true, 'sauvegardes' => dashagent_sauvegarde_lister()];

		case 'sauvegarde_supprimer':
			include_spip('inc/dashagent_sauvegarde');
			$supprime = dashagent_sauvegarde_supprimer((string) ($args['identifiant'] ?? ''));

			return ['ok' => $supprime, 'erreur' => $supprime ? '' : 'Sauvegarde introuvable'];

		case 'sauvegarde_telecharger':
			// Ne revient jamais : la réponse est un flux binaire.
			dashagent_op_telecharger((string) ($args['identifiant'] ?? ''));
			// @phpstan-ignore-next-line
			return [];

		case 'plugin_maj_preflight':
			include_spip('inc/dashagent_maj');

			return dashagent_plugin_preflight($args);

		case 'plugin_maj':
			include_spip('inc/dashagent_maj');

			return dashagent_plugin_maj($args);

		case 'core_maj_preflight':
			include_spip('inc/dashagent_maj');

			return dashagent_core_preflight();

		case 'core_maj':
			include_spip('inc/dashagent_maj');

			return dashagent_core_maj($args);

		default:
			return ['ok' => false, 'erreur' => 'Opération non implémentée : ' . $op];
	}
}

/**
 * Réponse au ping : de quoi vérifier l'appairage et l'écart d'horloge.
 *
 * @return array
 */
function dashagent_op_ping() {
	include_spip('inc/dashagent_infos');

	return [
		'ok'         => true,
		'spip'       => (string) ($GLOBALS['spip_version_branche'] ?? ''),
		'php'        => PHP_VERSION,
		'capacites'  => dashagent_infos_capacites(),
	];
}

/**
 * Diffuse le fichier d'une sauvegarde puis termine le script.
 *
 * @param string $identifiant
 * @return void
 */
function dashagent_op_telecharger($identifiant) {
	include_spip('inc/dashagent');
	include_spip('inc/dashagent_sauvegarde');

	$chemin = dashagent_sauvegarde_chemin($identifiant);
	if (!$chemin) {
		dashagent_erreur('sauvegarde_introuvable', 'Sauvegarde introuvable.', 404);
	}

	dashagent_journaliser('sauvegarde_telecharger', 'ok', basename($chemin), ['octets' => filesize($chemin)]);

	while (ob_get_level()) {
		ob_end_clean();
	}

	if (!headers_sent()) {
		header('Content-Type: application/gzip');
		header('Content-Length: ' . filesize($chemin));
		header('Content-Disposition: attachment; filename="' . basename($chemin) . '"');
		header('X-Dashagent-Sha256: ' . hash_file('sha256', $chemin));
		header('Cache-Control: no-store, private');
	}

	readfile($chemin);
	exit;
}

/**
 * Durée écoulée en millisecondes.
 *
 * @param float $debut
 * @return int
 */
function dashagent_duree($debut) {
	return (int) round((microtime(true) - $debut) * 1000);
}
