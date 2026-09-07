<?php
/**
 * Création et modification d'un site du parc.
 *
 * Attention au nommage : `objet_inserer('dashboard_site', …)` cherche par
 * convention une fonction `dashboard_site_inserer()`, et `objet_modifier()` une
 * fonction `dashboard_site_modifier()`. Définir l'une de ces fonctions *et* y
 * appeler l'API générique crée une récursion infinie — chacune se rappelant via
 * l'autre. Ce plugin n'a pas de logique d'insertion propre : il n'en définit
 * aucune et s'en tient à l'API générique.
 *
 * @package SPIP\Dashboard\Action
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Action d'édition, appelée par le formulaire CVT.
 *
 * @param null|int|string $arg Identifiant du site, ou vide pour une création
 * @return array [id_dashboard_site, message d'erreur]
 */
function action_editer_dashboard_site_dist($arg = null) {
	if (is_null($arg)) {
		$securiser_action = charger_fonction('securiser_action', 'inc');
		$arg = $securiser_action();
	}

	include_spip('action/editer_objet');

	$id_dashboard_site = intval($arg);

	if (!$id_dashboard_site) {
		$id_dashboard_site = (int) objet_inserer('dashboard_site', null, [
			'statut' => 'prepa',
			'date'   => date('Y-m-d H:i:s'),
			'etat'   => 'inconnu',
		]);
	}

	if (!$id_dashboard_site) {
		return [0, _T('dashboard:erreur_creation_site')];
	}

	$erreur = objet_modifier_champs('dashboard_site', $id_dashboard_site, [
		'nonvide' => ['titre' => _T('dashboard:site_sans_nom', ['id' => $id_dashboard_site])],
	]);

	return [$id_dashboard_site, $erreur];
}
