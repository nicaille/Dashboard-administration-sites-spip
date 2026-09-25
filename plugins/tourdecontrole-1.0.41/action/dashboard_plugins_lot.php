<?php
/**
 * Ouvre un lot de mises à jour de plugins depuis la page unifiée.
 *
 * Reçoit en POST un champ `maj[]` dont chaque valeur est `<id_site>:<PREFIXE>`,
 * puis ouvre un chantier par site et renvoie vers l'écran de suivi du lot.
 *
 * L'action est signée par `#URL_ACTION_AUTEUR`, donc `securiser_action()`
 * couvre la requête. Mais la signature ne dit rien du **contenu** du
 * formulaire : les couples reçus sont revalidés un par un contre l'inventaire
 * et contre `autoriser()`. Une case à cocher désigne, elle n'autorise pas.
 *
 * @package SPIP\Dashboard\Action
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @return void
 */
function action_dashboard_plugins_lot_dist() {
	include_spip('inc/autoriser');
	include_spip('inc/dashboard_lots');

	$securiser_action = charger_fonction('securiser_action', 'inc');
	$securiser_action();

	// Le droit d'ouvrir la page ne suffit pas ; celui d'opérer se vérifie site
	// par site, dans dashboard_lot_valider(). Ici on écarte seulement celui qui
	// n'a rien à faire là du tout.
	if (!autoriser('voir', 'dashboard_site')) {
		dashboard_lot_retour('', 'Opération non autorisée');
	}

	$choix = dashboard_lot_lire(_request('maj'));
	if (!$choix) {
		dashboard_lot_retour('', 'Aucun plugin sélectionné');
	}

	$retenus = dashboard_lot_valider($choix, dashboard_lot_en_retard(), dashboard_lot_sites_operables());
	if (!$retenus) {
		// Rien de ce qui a été coché n'existe plus comme mise à jour disponible.
		// C'est le cas normal quand l'inventaire a bougé depuis l'affichage, et
		// le dire vaut mieux que d'ouvrir un lot vide.
		dashboard_lot_retour('', 'Aucune des mises à jour cochées n’est encore disponible');
	}

	$lot = dashboard_lot_ouvrir($retenus);

	if (!$lot['ouverts']) {
		// Tous les sites étaient déjà occupés. Le message le dit, plutôt que de
		// renvoyer vers un écran de suivi qui n'aurait rien à suivre.
		dashboard_lot_retour('', 'Aucun chantier ouvert : ' . implode(' ; ', $lot['refuses']));
	}

	$message = count($lot['ouverts']) . ' site(s), ' . (int) $lot['plugins'] . ' mise(s) à jour lancée(s)';
	if ($lot['refuses']) {
		$message .= ' — ' . count($lot['refuses']) . ' site(s) déjà occupé(s)';
	}

	dashboard_lot_retour((string) $lot['lot'], $message, 'ok');
}

/**
 * Renvoie vers la page des plugins, avec ou sans lot à suivre.
 *
 * @param string $lot
 * @param string $message
 * @param string $statut « ok » ou « erreur », pour l'habillage du message
 * @return void
 */
function dashboard_lot_retour($lot, $message, $statut = 'erreur') {
	include_spip('inc/headers');

	$url = generer_url_ecrire('dashboard_plugins');
	if ($lot !== '') {
		$url = parametre_url($url, 'lot', $lot);
	}
	$url = parametre_url($url, 'dashboard_message', $message);
	$url = parametre_url($url, 'dashboard_statut', $statut);

	redirige_par_entete($url);
}
