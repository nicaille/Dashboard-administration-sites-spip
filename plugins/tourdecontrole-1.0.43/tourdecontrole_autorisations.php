<?php
/**
 * Autorisations du tableau de bord.
 *
 * Le tableau de bord détient les secrets de tous les sites du parc et peut
 * écrire du code sur chacun d'eux : les droits sont volontairement étroits.
 *
 * Aucune de ces fonctions n'en appelle une autre, ni ne repasse par
 * `autoriser()` : la chaîne de résolution de SPIP essaie plusieurs noms pour un
 * même contrôle, et la moindre délégation entre eux ouvre la porte à une
 * récursion infinie. Toutes s'appuient sur les deux prédicats ci-dessous.
 *
 * @package SPIP\Dashboard\Autorisations
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Chargement du fichier d'autorisations.
 *
 * @pipeline autoriser
 * @return void
 */
function tourdecontrole_autoriser() {
}

/**
 * Administrateur non restreint : peut consulter l'état du parc.
 *
 * @param array $qui
 * @return bool
 */
function dashboard_qui_peut_voir($qui) {
	return !empty($qui['statut']) && $qui['statut'] === '0minirezo' && empty($qui['restreint']);
}

/**
 * Webmestre : peut agir sur les sites gérés et sur la configuration.
 *
 * @param array $qui
 * @return bool
 */
function dashboard_qui_peut_agir($qui) {
	return !empty($qui['webmestre']) && $qui['webmestre'] === 'oui';
}

/* -------------------------------------------------------------------------- */
/* Consultation                                                               */
/* -------------------------------------------------------------------------- */

/** Voir la fiche d'un site géré. */
function autoriser_dashboard_site_voir_dist($faire, $type, $id, $qui, $opt) {
	return dashboard_qui_peut_voir($qui);
}

/** Voir la page d'ensemble du parc. */
function autoriser_dashboard_voir_dist($faire, $type, $id, $qui, $opt) {
	return dashboard_qui_peut_voir($qui);
}

/** Déclencher une synchronisation : opération de lecture seule. */
function autoriser_dashboard_site_synchroniser_dist($faire, $type, $id, $qui, $opt) {
	return dashboard_qui_peut_voir($qui);
}

/* -------------------------------------------------------------------------- */
/* Édition du parc                                                            */
/* -------------------------------------------------------------------------- */

/** Ajouter un site au parc. */
function autoriser_dashboard_site_creer_dist($faire, $type, $id, $qui, $opt) {
	return dashboard_qui_peut_agir($qui);
}

/** Modifier la fiche d'un site. */
function autoriser_dashboard_site_modifier_dist($faire, $type, $id, $qui, $opt) {
	return dashboard_qui_peut_agir($qui);
}

/** Retirer un site du parc. */
function autoriser_dashboard_site_supprimer_dist($faire, $type, $id, $qui, $opt) {
	return dashboard_qui_peut_agir($qui);
}

/** Changer le statut de supervision d'un site. */
function autoriser_dashboard_site_instituer_dist($faire, $type, $id, $qui, $opt) {
	return dashboard_qui_peut_agir($qui);
}

/** Passer un site en supervision active. */
function autoriser_dashboard_site_publier_dist($faire, $type, $id, $qui, $opt) {
	return dashboard_qui_peut_agir($qui);
}

/* -------------------------------------------------------------------------- */
/* Opérations à distance                                                      */
/* -------------------------------------------------------------------------- */

/**
 * Purger, sauvegarder, mettre à jour : ces opérations écrivent sur un site
 * tiers, elles restent réservées aux webmestres même quand un administrateur
 * peut consulter le parc.
 */
function autoriser_dashboard_site_operer_dist($faire, $type, $id, $qui, $opt) {
	return dashboard_qui_peut_agir($qui);
}

/* -------------------------------------------------------------------------- */
/* Configuration                                                              */
/* -------------------------------------------------------------------------- */

/**
 * Nom attendu par SPIP pour la page `configurer_dashboard` : le type et le
 * verbe y sont concaténés sans souligné.
 */
function autoriser_configurerdashboard_dist($faire, $type, $id, $qui, $opt) {
	return dashboard_qui_peut_agir($qui);
}

/** Variante de nom rencontrée selon les versions de SPIP. */
function autoriser_dashboard_configurer_dist($faire, $type, $id, $qui, $opt) {
	return dashboard_qui_peut_agir($qui);
}

/** Variante de nom rencontrée selon les versions de SPIP. */
function autoriser__dashboard_configurer_dist($faire, $type, $id, $qui, $opt) {
	return dashboard_qui_peut_agir($qui);
}

/*
 * La page de configuration porte désormais le nom du **préfixe du plugin** —
 * `configurer_tourdecontrole` —, parce que c'est de celui-là que SPIP dérive le
 * bouton « Configurer » de sa page Gestion des plugins : `plugin_bouton_config()`
 * cherche l'exec `configurer_` . strtolower(prefix), et `inclure/cfg.html` ne
 * montre le lien que s'il existe.
 *
 * C'est une exception à la règle qui veut que nos noms internes restent en
 * `dashboard_*` : ici le nom n'est pas le nôtre, il est dérivé par SPIP.
 *
 * Les trois orthographes, comme au-dessus, et les anciennes restent : elles ne
 * coûtent rien et couvrent les signets d'avant le renommage.
 */

/** Type `_tourdecontrole`, verbe `configurer`. */
function autoriser_tourdecontrole_configurer_dist($faire, $type, $id, $qui, $opt) {
	return dashboard_qui_peut_agir($qui);
}

/** Type et verbe concaténés, sans souligné. */
function autoriser_configurertourdecontrole_dist($faire, $type, $id, $qui, $opt) {
	return dashboard_qui_peut_agir($qui);
}

/** Variante où le souligné initial du type est conservé. */
function autoriser__tourdecontrole_configurer_dist($faire, $type, $id, $qui, $opt) {
	return dashboard_qui_peut_agir($qui);
}
