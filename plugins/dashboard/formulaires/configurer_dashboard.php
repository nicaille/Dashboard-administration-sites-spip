<?php
/**
 * Configuration générale du tableau de bord.
 *
 * @package SPIP\Dashboard\Formulaires
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * @return array
 */
function formulaires_configurer_dashboard_charger_dist() {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_versions');

	return [
		'timeout'               => dashboard_config('timeout', 30),
		'timeout_long'          => dashboard_config('timeout_long', 300),
		'sync_auto'             => dashboard_config('sync_auto', ''),
		'sync_frequence'        => dashboard_config('sync_frequence', 6),
		'sync_lot'              => dashboard_config('sync_lot', 10),
		'url_archives_spip'     => dashboard_config('url_archives_spip', 'https://files.spip.net/spip/archives/'),
		'versions_manuelles'    => dashboard_config('versions_manuelles', ''),
		'sauvegarder_avant_maj' => dashboard_config('sauvegarder_avant_maj', 'on'),
		'retention_journal'     => dashboard_config('retention_journal', 180),
		'retention_sauvegardes' => dashboard_config('retention_sauvegardes', 30),
		'autoriser_http'        => dashboard_config('autoriser_http', ''),
		'_versions_connues'     => dashboard_versions_spip(),
	];
}

/**
 * @return array
 */
function formulaires_configurer_dashboard_verifier_dist() {
	$erreurs = [];

	if ((int) _request('timeout') < 5 || (int) _request('timeout') > 300) {
		$erreurs['timeout'] = _T('dashboard:erreur_timeout');
	}
	if ((int) _request('timeout_long') < 30 || (int) _request('timeout_long') > 900) {
		$erreurs['timeout_long'] = _T('dashboard:erreur_timeout_long');
	}
	$url = trim((string) _request('url_archives_spip'));
	if ($url !== '' && !preg_match('#^https://#i', $url)) {
		$erreurs['url_archives_spip'] = _T('dashboard:erreur_url_archives');
	}

	foreach (preg_split('/[\r\n]+/', (string) _request('versions_manuelles')) as $ligne) {
		$ligne = trim($ligne);
		if ($ligne === '') {
			continue;
		}
		if (!preg_match('/^\d+\.\d+\s*=\s*\d+\.\d+\.\d+/', $ligne)) {
			$erreurs['versions_manuelles'] = _T('dashboard:erreur_versions_manuelles', ['ligne' => $ligne]);
			break;
		}
	}

	return $erreurs;
}

/**
 * @return array
 */
function formulaires_configurer_dashboard_traiter_dist() {
	include_spip('inc/config');
	include_spip('inc/dashboard_versions');

	$config = lire_config('dashboard', []);
	if (!is_array($config)) {
		$config = [];
	}

	$config['timeout']               = (int) _request('timeout');
	$config['timeout_long']          = (int) _request('timeout_long');
	$config['sync_frequence']        = max(1, (int) _request('sync_frequence'));
	$config['sync_lot']              = max(1, (int) _request('sync_lot'));
	$config['url_archives_spip']     = trim((string) _request('url_archives_spip'));
	$config['versions_manuelles']    = trim((string) _request('versions_manuelles'));
	$config['retention_journal']     = max(1, (int) _request('retention_journal'));
	$config['retention_sauvegardes'] = max(1, (int) _request('retention_sauvegardes'));

	foreach (['sync_auto', 'sauvegarder_avant_maj', 'autoriser_http'] as $bascule) {
		$config[$bascule] = (_request($bascule) === 'on') ? 'on' : '';
	}

	// L'index des archives est remis en cause : on invalide le cache pour que la
	// prochaine lecture reflète immédiatement le nouveau réglage.
	unset($config['cache_versions']);

	ecrire_config('dashboard', $config);

	return ['message_ok' => _T('dashboard:config_enregistree'), 'editable' => true];
}
