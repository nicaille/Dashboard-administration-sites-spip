<?php
/**
 * Déclenchement d'une opération sur un site géré depuis l'espace privé.
 *
 * L'argument reçu a la forme `operation/id_site[/complement]`, par exemple
 * `purger/3/images` ou `plugin_maj/3/GIS`.
 *
 * @package SPIP\Dashboard\Action
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @return void
 */
function action_dashboard_operation_dist() {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_operations');
	include_spip('inc/dashboard_sync');

	$securiser_action = charger_fonction('securiser_action', 'inc');
	$arg = $securiser_action();

	$morceaux   = explode('/', (string) $arg);
	$operation  = (string) array_shift($morceaux);
	$id_site    = (int) array_shift($morceaux);
	$complement = implode('/', $morceaux);

	if (!$id_site || !dashboard_charger_site($id_site)) {
		dashboard_operation_retour($id_site, false, 'Site inconnu');
	}

	// Lire l'état d'un site et agir dessus ne relèvent pas du même droit.
	$droit = ($operation === 'sync') ? 'synchroniser' : 'operer';
	if (!autoriser($droit, 'dashboard_site', $id_site)) {
		dashboard_operation_retour($id_site, false, 'Opération non autorisée');
	}

	switch ($operation) {
		case 'sync':
			$resultat = dashboard_synchroniser($id_site);
			$resultat['message'] = $resultat['ok'] ? 'Inventaire mis à jour' : $resultat['message'];
			break;

		case 'purger':
			$cibles = $complement !== '' ? explode(',', $complement) : ['tout'];
			$resultat = dashboard_operation_purger($id_site, $cibles);
			break;

		case 'sauvegarde':
			$resultat = dashboard_operation_sauvegarder($id_site, [
				'rapatrier'         => $complement !== 'distante',
				'sans_statistiques' => ($complement === 'legere'),
			]);
			break;

		case 'plugin_maj':
			$resultat = $complement === ''
				? ['ok' => false, 'message' => 'Aucun plugin indiqué']
				: dashboard_operation_plugin_maj($id_site, $complement);
			break;

		case 'plugin_maj_tous':
			$resultat = dashboard_operation_plugin_maj_tous($id_site);
			break;

		case 'core_maj':
			$resultat = dashboard_operation_core_maj($id_site, $complement, [
				'sauvegarder_avant' => (dashboard_config('sauvegarder_avant_maj', 'on') === 'on'),
			]);
			break;

		default:
			$resultat = ['ok' => false, 'message' => 'Opération inconnue : ' . $operation];
	}

	dashboard_operation_retour($id_site, !empty($resultat['ok']), (string) ($resultat['message'] ?? ''));
}

/**
 * Renvoie l'utilisateur sur la fiche du site avec un message.
 *
 * @param int $id_site
 * @param bool $ok
 * @param string $message
 * @return void
 */
function dashboard_operation_retour($id_site, $ok, $message) {
	include_spip('inc/headers');

	$redirect = _request('redirect');
	if (!$redirect) {
		$redirect = $id_site
			? generer_url_ecrire('dashboard_site', 'id_dashboard_site=' . (int) $id_site)
			: generer_url_ecrire('dashboard');
	}

	$redirect = parametre_url($redirect, 'dashboard_message', substr($message, 0, 500), '&');
	$redirect = parametre_url($redirect, 'dashboard_statut', $ok ? 'ok' : 'erreur', '&');

	redirige_par_entete($redirect);
}
