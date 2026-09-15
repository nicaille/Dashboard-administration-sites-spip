<?php
/**
 * Création et modification d'un site du parc.
 *
 * Deux pièges de nommage sont évités ici :
 *
 * - `objet_inserer('dashboard_site', …)` et `objet_modifier()` cherchent par
 *   convention des fonctions `dashboard_site_inserer()` / `dashboard_site_modifier()`.
 *   En définir une *et* y appeler l'API générique fait s'appeler les deux sans
 *   fin. Ce plugin n'a pas de logique d'insertion propre : il n'en définit aucune.
 * - les champs écrits sont construits explicitement à partir du descripteur de
 *   l'objet, plutôt que de compter sur une collecte implicite.
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

	$set = dashboard_champs_postes($id_dashboard_site);
	$erreur = objet_modifier('dashboard_site', $id_dashboard_site, $set);

	return [$id_dashboard_site, is_string($erreur) ? $erreur : ''];
}

/**
 * Rassemble les champs postés qui sont réellement éditables pour cet objet.
 *
 * La liste vient du descripteur : elle ne peut pas diverger de ce que SPIP
 * accepte d'écrire.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_champs_postes($id_dashboard_site) {
	include_spip('base/dashboard_tables');

	$descripteur = dashboard_declarer_tables_objets_sql([]);
	$editables = $descripteur['spip_dashboard_sites']['champs_editables'] ?? [];

	$set = [];
	foreach ($editables as $champ) {
		$valeur = _request($champ);
		if ($valeur !== null) {
			$set[$champ] = is_string($valeur) ? trim($valeur) : $valeur;
		}
	}

	// Un site sans nom serait illisible dans la liste du parc.
	if (empty($set['titre'])) {
		$set['titre'] = _T('dashboard:site_sans_nom', ['id' => $id_dashboard_site]);
	}

	return $set;
}
