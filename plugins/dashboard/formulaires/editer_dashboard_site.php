<?php
/**
 * Formulaire de création / modification d'un site du parc.
 *
 * @package SPIP\Dashboard\Formulaires
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

include_spip('inc/actions');
include_spip('inc/editer');

/**
 * Chargement.
 *
 * @param int|string $id_dashboard_site
 * @param string $retour
 * @param int $lier_trad
 * @param string $config_fonc
 * @param array $row
 * @param string $hidden
 * @return array
 */
function formulaires_editer_dashboard_site_charger_dist($id_dashboard_site = 'new', $retour = '', $lier_trad = 0, $config_fonc = '', $row = [], $hidden = '') {
	$valeurs = formulaires_editer_objet_charger('dashboard_site', $id_dashboard_site, 0, $lier_trad, $retour, $config_fonc, $row, $hidden);

	include_spip('inc/dashboard_client');

	$secret = '';
	if (is_numeric($id_dashboard_site)) {
		$site = dashboard_charger_site((int) $id_dashboard_site);
		$secret = $site ? dashboard_dechiffrer((string) $site['secret']) : '';
	}

	// Le secret n'est jamais réinjecté dans le formulaire : seule son empreinte
	// est affichée, ce qui suffit à vérifier qu'il correspond à celui de l'agent.
	$valeurs['secret_present']   = $secret !== '' ? 'oui' : 'non';
	$valeurs['secret_empreinte'] = $secret !== '' ? substr(hash('sha256', $secret), 0, 12) : '';
	$valeurs['secret_clair']     = '';
	$valeurs['generer_secret']   = '';
	$valeurs['_hidden'] = ($valeurs['_hidden'] ?? '');

	return $valeurs;
}

/**
 * Vérification.
 *
 * @return array
 */
function formulaires_editer_dashboard_site_verifier_dist($id_dashboard_site = 'new', $retour = '', $lier_trad = 0, $config_fonc = '', $row = [], $hidden = '') {
	include_spip('inc/dashboard_client');

	$erreurs = formulaires_editer_objet_verifier('dashboard_site', $id_dashboard_site, ['titre', 'url_agent']);

	$url_agent = trim((string) _request('url_agent'));
	if ($url_agent !== '' && !dashboard_url_acceptable($url_agent)) {
		$erreurs['url_agent'] = _T('dashboard:erreur_url_agent');
	}
	if ($url_agent !== '' && strpos($url_agent, 'action=dashagent') === false) {
		$erreurs['url_agent'] = _T('dashboard:erreur_url_agent_endpoint');
	}

	$url_site = trim((string) _request('url_site'));
	if ($url_site !== '' && !preg_match('#^https?://#i', $url_site)) {
		$erreurs['url_site'] = _T('dashboard:erreur_url_site');
	}

	$secret = trim((string) _request('secret_clair'));
	if ($secret !== '' && strlen($secret) < 32) {
		$erreurs['secret_clair'] = _T('dashboard:erreur_secret_court', ['min' => 32]);
	}

	// Sans secret, la fiche ne sert à rien : on l'exige à la création.
	$nouveau = !is_numeric($id_dashboard_site);
	if ($nouveau && $secret === '' && _request('generer_secret') !== 'on') {
		$erreurs['secret_clair'] = _T('dashboard:erreur_secret_obligatoire');
	}

	return $erreurs;
}

/**
 * Enregistrement.
 *
 * @return array
 */
function formulaires_editer_dashboard_site_traiter_dist($id_dashboard_site = 'new', $retour = '', $lier_trad = 0, $config_fonc = '', $row = [], $hidden = '') {
	include_spip('inc/dashboard_client');

	$retours = formulaires_editer_objet_traiter('dashboard_site', $id_dashboard_site, 0, $lier_trad, $retour, $config_fonc, $row, $hidden);
	$id = (int) ($retours['id_dashboard_site'] ?? 0);

	if (!$id) {
		return $retours;
	}

	$nouveau_secret = '';
	if (_request('generer_secret') === 'on') {
		$nouveau_secret = dashboard_generer_secret();
	} elseif (trim((string) _request('secret_clair')) !== '') {
		$nouveau_secret = trim((string) _request('secret_clair'));
	}

	if ($nouveau_secret !== '') {
		sql_updateq('spip_dashboard_sites', ['secret' => dashboard_chiffrer($nouveau_secret)], 'id_dashboard_site = ' . $id);
		$retours['message_ok'] = trim((string) ($retours['message_ok'] ?? '') . ' '
			. _T('dashboard:secret_a_copier', ['secret' => $nouveau_secret]));
		// Le secret ne doit pas transiter dans l'URL de redirection.
		unset($retours['redirect']);
		$retours['editable'] = true;
	}

	return $retours;
}
