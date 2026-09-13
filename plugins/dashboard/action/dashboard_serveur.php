<?php
/**
 * Relais vers les opérations de consultation d'un site géré.
 *
 * L'onglet « Serveur » se remplit à la demande, par ces appels, et non par la
 * synchronisation : un `phpinfo()` pèse cent kilo-octets, le contenu d'une table
 * bien davantage, et rien de tout cela n'a de raison d'être recopié dans la base
 * du tableau de bord à chaque passage. On regarde, on ne conserve pas.
 *
 * @package SPIP\Dashboard\Action
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Opérations que ce relais accepte de transmettre, et les arguments qu'il
 * laisse passer pour chacune.
 *
 * Une liste fermée : sans elle, l'action deviendrait un tunnel vers n'importe
 * quelle opération de l'agent, court-circuitant les autorisations qui vont avec.
 *
 * @return array
 */
function dashboard_serveur_operations() {
	return [
		'serveur_resume'  => [],
		'serveur_phpinfo' => [],
		'serveur_tables'  => [],
		'serveur_table'   => ['table', 'debut', 'lot', 'tri', 'sens', 'filtre_colonne', 'filtre_valeur'],
		'serveur_fichier' => ['fichier'],
	];
}

/**
 * @return void
 */
function action_dashboard_serveur_dist() {
	include_spip('inc/autoriser');
	include_spip('inc/dashboard_client');

	// L'argument signé ne porte que le site : la signature couvre « cet auteur
	// a le droit d'interroger ce site-là ». L'opération arrive à côté, et c'est
	// la liste fermée ci-dessus qui la borne — glisser un nom d'opération dans
	// l'argument obligerait à resigner l'URL à chaque appel.
	$securiser_action = charger_fonction('securiser_action', 'inc');
	$id_site = (int) $securiser_action();
	$operation = (string) _request('operation');

	$permises = dashboard_serveur_operations();
	if (!isset($permises[$operation])) {
		dashboard_serveur_repondre(['ok' => false, 'message' => 'Consultation inconnue : ' . $operation]);
	}

	$site = $id_site ? dashboard_charger_site($id_site) : null;
	if (!$site) {
		dashboard_serveur_repondre(['ok' => false, 'message' => 'Site inconnu']);
	}

	// Lire l'état d'un serveur en dit plus long que consulter un inventaire :
	// c'est le droit d'agir sur le site qui est exigé, pas celui de le voir.
	if (!autoriser('operer', 'dashboard_site', $id_site)) {
		dashboard_serveur_repondre(['ok' => false, 'message' => 'Consultation non autorisée']);
	}

	$args = [];
	foreach ($permises[$operation] as $nom) {
		$valeur = _request($nom);
		if ($valeur !== null && $valeur !== '') {
			$args[$nom] = is_scalar($valeur) ? (string) $valeur : '';
		}
	}

	$reponse = dashboard_appeler($site, $operation, $args, ['timeout' => dashboard_config('timeout', 30)]);

	if (!$reponse['ok']) {
		dashboard_serveur_repondre([
			'ok'      => false,
			'message' => (string) ($reponse['erreur']['message'] ?? 'Le site n’a pas répondu'),
			'code'    => (string) ($reponse['erreur']['code'] ?? ''),
		]);
	}

	dashboard_serveur_repondre(['ok' => true] + (array) $reponse['data']);
}

/**
 * Émet la réponse JSON et termine.
 *
 * @param array $donnees
 * @return void
 */
function dashboard_serveur_repondre($donnees) {
	if (!headers_sent()) {
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store, private');
		header('X-Robots-Tag: noindex, nofollow');
	}
	echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
	exit;
}
