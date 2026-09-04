<?php
/**
 * Création et modification d'un site du parc.
 *
 * @package SPIP\Dashboard\Action
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Action d'édition, appelée par le formulaire CVT.
 *
 * @param null|int $arg
 * @return array [id_dashboard_site, message d'erreur]
 */
function action_editer_dashboard_site_dist($arg = null) {
	if (is_null($arg)) {
		$securiser_action = charger_fonction('securiser_action', 'inc');
		$arg = $securiser_action();
	}

	$id_dashboard_site = intval($arg);
	if (!$id_dashboard_site) {
		$id_dashboard_site = dashboard_site_inserer();
	}
	if (!$id_dashboard_site) {
		return [0, 'Création impossible'];
	}

	$erreur = dashboard_site_modifier($id_dashboard_site);

	return [$id_dashboard_site, $erreur];
}

/**
 * Insère un site vide.
 *
 * @return int
 */
function dashboard_site_inserer() {
	include_spip('action/editer_objet');

	return (int) objet_inserer('dashboard_site', null, [
		'statut' => 'prepa',
		'date'   => date('Y-m-d H:i:s'),
		'etat'   => 'inconnu',
	]);
}

/**
 * Applique les champs postés.
 *
 * @param int $id_dashboard_site
 * @param array|null $set
 * @return string|null Message d'erreur
 */
function dashboard_site_modifier($id_dashboard_site, $set = null) {
	include_spip('action/editer_objet');

	$erreur = objet_modifier_champs('dashboard_site', $id_dashboard_site, [
		'nonvide' => ['titre' => 'Site sans nom ' . $id_dashboard_site],
	], $set);

	return $erreur;
}
