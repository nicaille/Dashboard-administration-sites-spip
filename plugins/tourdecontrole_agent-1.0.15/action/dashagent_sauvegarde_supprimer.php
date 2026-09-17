<?php
/**
 * Suppression d'une sauvegarde depuis l'espace privé du site géré.
 *
 * Le pendant local de l'opération `sauvegarde_supprimer` du protocole : la même
 * chose, demandée par le webmestre du site plutôt que par la tour de contrôle.
 * Un site qui héberge ces fichiers doit pouvoir s'en défaire sans dépendre de
 * quiconque — y compris de celui qui les a fait produire.
 *
 * @package SPIP\Dashagent\Action
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @return void
 */
function action_dashagent_sauvegarde_supprimer_dist() {
	include_spip('inc/headers');
	include_spip('inc/autoriser');
	include_spip('inc/dashagent');
	include_spip('inc/dashagent_sauvegarde');
	// dashagent_tables_presentes() vit dans le fichier de fonctions du plugin :
	// SPIP ne le charge que pour les squelettes, pas pour une action. Sans cet
	// appel, la suppression aboutissait puis mourait sur une fonction inconnue,
	// laissant une page blanche et aucun compte rendu.
	include_spip('tourdecontrole_agent_fonctions');

	$securiser_action = charger_fonction('securiser_action', 'inc');
	$identifiant = (string) $securiser_action();

	$retour = _request('redirect');
	if (!$retour) {
		$retour = parametre_url(generer_url_ecrire('configurer_dashagent'), 'x', '', '&');
	}

	// Configurer l'agent et effacer ses sauvegardes relèvent du même droit : ces
	// fichiers portent la base entière du site.
	if (!autoriser('configurer', '_dashagent')) {
		redirige_par_entete(parametre_url($retour, 'dashagent_message', _T('dashagent:erreur_non_autorise'), '&'));
	}

	// dashagent_sauvegarde_supprimer() n'accepte qu'un identifiant de la forme
	// attendue et ne touche qu'au répertoire des sauvegardes : rien de ce qui
	// arrive ici ne compose un chemin.
	$supprime = dashagent_sauvegarde_supprimer($identifiant);

	if (dashagent_tables_presentes()) {
		dashagent_journaliser(
			'sauvegarde_supprimer',
			$supprime ? 'ok' : 'erreur',
			$supprime ? 'Sauvegarde supprimée depuis l’espace privé' : 'Sauvegarde introuvable',
			['identifiant' => $identifiant]
		);
	}

	$retour = parametre_url(
		$retour,
		'dashagent_message',
		$supprime ? _T('dashagent:sauvegarde_supprimee') : _T('dashagent:sauvegarde_introuvable'),
		'&'
	);
	$retour = parametre_url($retour, 'dashagent_statut', $supprime ? 'ok' : 'erreur', '&');

	redirige_par_entete($retour);
}
