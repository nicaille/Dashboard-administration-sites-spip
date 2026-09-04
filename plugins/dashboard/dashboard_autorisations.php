<?php
/**
 * Autorisations du tableau de bord.
 *
 * Le tableau de bord détient les secrets de tous les sites du parc et peut
 * écrire du code sur chacun d'eux : les droits sont volontairement étroits.
 *
 * @package SPIP\Dashboard\Autorisations
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @pipeline autoriser
 * @return void
 */
function dashboard_autoriser() {
}

/**
 * Voir le tableau de bord et les fiches de sites : administrateurs.
 */
function autoriser_dashboard_site_voir_dist($faire, $type, $id, $qui, $opt) {
	return !empty($qui['statut']) && $qui['statut'] === '0minirezo' && empty($qui['restreint']);
}

/**
 * Créer, modifier, supprimer un site du parc : webmestres uniquement.
 */
function autoriser_dashboard_site_creer_dist($faire, $type, $id, $qui, $opt) {
	return !empty($qui['webmestre']) && $qui['webmestre'] === 'oui';
}

/**
 * @see autoriser_dashboard_site_creer_dist()
 */
function autoriser_dashboard_site_modifier_dist($faire, $type, $id, $qui, $opt) {
	return autoriser_dashboard_site_creer_dist($faire, $type, $id, $qui, $opt);
}

/**
 * @see autoriser_dashboard_site_creer_dist()
 */
function autoriser_dashboard_site_supprimer_dist($faire, $type, $id, $qui, $opt) {
	return autoriser_dashboard_site_creer_dist($faire, $type, $id, $qui, $opt);
}

/**
 * Déclencher une opération de lecture (inventaire) sur un site.
 */
function autoriser_dashboard_site_synchroniser_dist($faire, $type, $id, $qui, $opt) {
	return autoriser_dashboard_site_voir_dist($faire, $type, $id, $qui, $opt);
}

/**
 * Déclencher une opération qui modifie le site géré (purge, sauvegarde, mise à jour).
 *
 * Ces opérations écrivent sur un site tiers : elles restent réservées aux
 * webmestres, même quand un administrateur peut les voir.
 */
function autoriser_dashboard_site_operer_dist($faire, $type, $id, $qui, $opt) {
	return autoriser_dashboard_site_creer_dist($faire, $type, $id, $qui, $opt);
}

/**
 * Accès à la page globale du parc.
 */
function autoriser_dashboard_voir_dist($faire, $type, $id, $qui, $opt) {
	return autoriser_dashboard_site_voir_dist($faire, $type, $id, $qui, $opt);
}

/**
 * Configurer le plugin.
 */
function autoriser_dashboard_configurer_dist($faire, $type, $id, $qui, $opt) {
	return !empty($qui['webmestre']) && $qui['webmestre'] === 'oui';
}

/**
 * Alias, selon la façon dont SPIP transmet le type des pages `configurer_xxx`.
 */
function autoriser__dashboard_configurer_dist($faire, $type, $id, $qui, $opt) {
	return autoriser_dashboard_configurer_dist($faire, $type, $id, $qui, $opt);
}
