<?php
/**
 * Enregistre ou retire l'abonnement d'un navigateur aux alertes du parc.
 *
 * Un abonnement appartient à un **navigateur**, pas à une personne : le même
 * webmestre en aura un sur son poste et un sur son téléphone, et c'est très
 * bien — la notification doit arriver là où il est.
 *
 * Ce que le navigateur envoie n'est pas un secret d'authentification : c'est
 * une adresse de distribution et une clef publique. Mais c'est une **écriture
 * en base à partir de données extérieures**, et elle est donc traitée comme
 * telle — action signée, droit vérifié, valeurs contrôlées une à une.
 *
 * @package SPIP\Dashboard\Action
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @return void
 */
function action_dashboard_push_dist() {
	include_spip('inc/autoriser');
	include_spip('inc/session');
	include_spip('inc/dashboard_push');

	$securiser_action = charger_fonction('securiser_action', 'inc');
	$securiser_action();

	// Les alertes sont celles du webmestre du parc : c'est le droit de la page
	// de configuration, pas celui de consulter un site.
	if (!autoriser('configurer', '_tourdecontrole')) {
		dashboard_push_repondre(['ok' => false, 'message' => 'Opération non autorisée']);
	}

	$endpoint = (string) _request('endpoint');
	if ($endpoint === '' || dashboard_push_audience($endpoint) === '') {
		dashboard_push_repondre(['ok' => false, 'message' => 'Adresse de distribution absente ou illisible']);
	}

	// L'unicité porte sur l'empreinte de l'adresse : les adresses de FCM
	// dépassent ce qu'un index MySQL accepte sur une colonne de texte.
	$empreinte = hash('sha256', $endpoint);

	if ((string) _request('retirer') === 'oui') {
		sql_delete('spip_dashboard_push', 'empreinte = ' . sql_quote($empreinte));
		dashboard_push_repondre(['ok' => true, 'message' => 'Abonnement retiré']);
	}

	$p256dh = (string) _request('p256dh');
	$auth   = (string) _request('auth');

	// Contrôle des tailles une fois décodées, et non de la chaîne reçue : un
	// abonnement mal formé stocké ici ne se signalerait qu'au premier envoi,
	// par un échec de chiffrement sans rapport apparent avec son origine.
	if (strlen(dashboard_push_deb64($p256dh)) !== 65) {
		dashboard_push_repondre(['ok' => false, 'message' => 'Clef publique d’abonnement de taille inattendue']);
	}
	if (strlen(dashboard_push_deb64($auth)) !== 16) {
		dashboard_push_repondre(['ok' => false, 'message' => 'Secret d’authentification de taille inattendue']);
	}

	$maintenant = date('Y-m-d H:i:s');
	$champs = [
		'id_auteur'   => (int) (session_get('id_auteur') ?: 0),
		'empreinte'   => $empreinte,
		'endpoint'    => $endpoint,
		'p256dh'      => $p256dh,
		'auth'        => $auth,
		'navigateur'  => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
		'date'        => $maintenant,
		'date_succes' => $maintenant,
		'echecs'      => 0,
	];

	$id = (int) sql_getfetsel('id_dashboard_push', 'spip_dashboard_push',
		'empreinte = ' . sql_quote($empreinte));

	if ($id) {
		// Le même navigateur revient : on rafraîchit plutôt que d'ajouter. Les
		// clefs changent quand il renouvelle son abonnement sans changer
		// d'adresse, ce que font certains services.
		sql_updateq('spip_dashboard_push', $champs, 'id_dashboard_push = ' . $id);
	} else {
		$id = (int) sql_insertq('spip_dashboard_push', $champs);
	}

	dashboard_push_repondre([
		'ok'      => (bool) $id,
		'message' => $id ? 'Notifications activées sur ce navigateur' : 'Enregistrement impossible',
	]);
}

/**
 * Émet la réponse JSON et termine.
 *
 * @param array $donnees
 * @return void
 */
function dashboard_push_repondre($donnees) {
	if (!headers_sent()) {
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store, private');
	}
	echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}
