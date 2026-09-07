<?php
/**
 * Relance la création des tables du plugin depuis l'espace privé.
 *
 * Évite d'avoir à désinstaller puis réactiver le plugin quand l'installation
 * automatique n'a pas abouti, et affiche ce qui reste éventuellement bloqué.
 *
 * @package SPIP\Dashboard\Action
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @return void
 */
function action_dashboard_installer_dist() {
	include_spip('inc/headers');

	$securiser_action = charger_fonction('securiser_action', 'inc');
	$securiser_action();

	$redirect = _request('redirect') ?: generer_url_ecrire('dashboard');

	if (!autoriser('configurer', '_dashboard')) {
		redirige_par_entete(parametre_url($redirect, 'dashboard_message', _T('dashboard:erreur_non_autorise'), '&'));
	}

	include_spip('dashboard_administrations');
	$rapport = dashboard_creer_tables();
	dashboard_initialiser_configuration();

	$restantes = $rapport['restantes'];
	$message = $restantes
		? _T('dashboard:installation_echouee', [
			'tables' => implode(', ', $restantes),
			'erreur' => implode(' | ', $rapport['erreurs']),
		])
		: _T('dashboard:installation_reussie');

	$redirect = parametre_url($redirect, 'dashboard_message', $message, '&');
	$redirect = parametre_url($redirect, 'dashboard_statut', $restantes ? 'erreur' : 'ok', '&');

	redirige_par_entete($redirect);
}
