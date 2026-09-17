<?php
/**
 * Relecture des dépôts d'un site, pilotée depuis la vue d'ensemble.
 *
 * Le nombre de mises à jour affiché sur le parc ne vaut que ce que vaut le
 * catalogue de chaque site géré, et ce catalogue ne se rafraîchit pas tout seul.
 * Faire le tour du parc en une requête est hors de question — un catalogue pèse
 * plusieurs méga-octets et il y en a un par dépôt et par site. Cette action en
 * relit **un seul** et rend la main ; c'est le navigateur qui enchaîne, site
 * après site, en montrant où il en est.
 *
 * Rien n'est modifié sur le site géré : un catalogue se régénère à volonté.
 * Interrompre la boucle ne laisse donc rien à moitié fait — chaque site est
 * relu ou ne l'est pas, et la synchronisation de fond rattrapera le reste.
 *
 * @package SPIP\Dashboard\Action
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @return void
 */
function action_dashboard_parc_dist() {
	include_spip('inc/autoriser');
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_operations');
	include_spip('inc/dashboard_sync');

	$securiser_action = charger_fonction('securiser_action', 'inc');
	$id_site = (int) $securiser_action();

	$site = $id_site ? dashboard_charger_site($id_site) : null;
	if (!$site) {
		dashboard_parc_repondre(['ok' => false, 'termine' => true, 'message' => 'Site inconnu']);
	}
	if (!autoriser('operer', 'dashboard_site', $id_site)) {
		dashboard_parc_repondre(['ok' => false, 'termine' => true, 'message' => 'Opération non autorisée']);
	}

	$titre = (string) $site['titre'];
	$reponse = dashboard_operation_depots_actualiser($id_site, 0);

	if (empty($reponse['ok'])) {
		// Un site sans SVP n'a pas de catalogue : ce n'est pas un échec, il n'y
		// a rien à relire chez lui.
		$absent = ((string) ($reponse['data']['raison'] ?? '') === 'svp_absent');

		dashboard_parc_repondre([
			'ok'      => $absent,
			'termine' => true,
			'site'    => $titre,
			'message' => $absent
				? $titre . ' : pas de dépôt à relire'
				: $titre . ' : ' . (string) $reponse['message'],
		]);
	}

	$data = (array) $reponse['data'];
	if (empty($data['termine'])) {
		dashboard_parc_repondre([
			'ok'      => true,
			'termine' => false,
			'site'    => $titre,
			'message' => $titre . ' : dépôt « ' . (string) ($data['actualise'] ?? '') . ' » relu, reste '
				. (int) ($data['reste'] ?? 0),
		]);
	}

	// Le catalogue est à jour : reste à recompter. Sans dépôts, puisqu'on vient
	// de les relire — les repasser en revue ne ferait que perdre du temps.
	$inventaire = dashboard_synchroniser($id_site, ['sans_depots' => true]);
	$apres = dashboard_charger_site($id_site);

	dashboard_parc_repondre([
		'ok'      => !empty($inventaire['ok']),
		'termine' => true,
		'site'    => $titre,
		'plugins_maj' => (int) ($apres['nb_plugins_maj'] ?? 0),
		'message' => empty($inventaire['ok'])
			? $titre . ' : ' . (string) ($inventaire['message'] ?? 'inventaire impossible')
			: $titre . ' : ' . (int) ($apres['nb_plugins_maj'] ?? 0) . ' mise(s) à jour de plugins',
	]);
}

/**
 * Émet la réponse JSON et termine.
 *
 * @param array $donnees
 * @return void
 */
function dashboard_parc_repondre($donnees) {
	if (!headers_sent()) {
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store, private');
	}
	echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}
