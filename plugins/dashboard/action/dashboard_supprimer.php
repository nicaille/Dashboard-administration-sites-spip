<?php
/**
 * Retrait d'un site du parc.
 *
 * Le site passe à la poubelle plutôt que d'être effacé : son journal et ses
 * sauvegardes rapatriées restent consultables jusqu'à la purge de rétention,
 * ce qui est souvent exactement ce qu'on veut après un incident.
 *
 * @package SPIP\Dashboard\Action
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @return void
 */
function action_dashboard_supprimer_dist() {
	include_spip('inc/headers');

	$securiser_action = charger_fonction('securiser_action', 'inc');
	$id_dashboard_site = (int) $securiser_action();

	if ($id_dashboard_site && autoriser('supprimer', 'dashboard_site', $id_dashboard_site)) {
		sql_updateq(
			'spip_dashboard_sites',
			['statut' => 'poubelle'],
			'id_dashboard_site = ' . $id_dashboard_site
		);
		// Le secret ne sert plus à rien et ne doit pas traîner en base.
		sql_updateq('spip_dashboard_sites', ['secret' => ''], 'id_dashboard_site = ' . $id_dashboard_site);
		sql_delete('spip_dashboard_plugins', 'id_dashboard_site = ' . $id_dashboard_site);

		include_spip('inc/dashboard_journal');
		dashboard_journaliser($id_dashboard_site, 'retrait', 'ok', 'Site retiré du parc');
	}

	redirige_par_entete(generer_url_ecrire('dashboard'));
}
