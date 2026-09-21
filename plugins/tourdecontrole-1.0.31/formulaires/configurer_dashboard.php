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
		'url_spip_loader'       => dashboard_config('url_spip_loader', _DASHBOARD_LOADER_URL),
		'url_spip_check'        => dashboard_config('url_spip_check', _DASHBOARD_CHECK_URL),
		'sha256_spip_check'     => dashboard_config('sha256_spip_check', ''),
		'versions_manuelles'    => dashboard_config('versions_manuelles', ''),
		'fraicheur_sauvegarde'  => dashboard_config('fraicheur_sauvegarde', 900),
		'fraicheur_depots'      => dashboard_config('fraicheur_depots', 86400),
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
	// Le http n'est toléré que si la case de développement local est cochée, et
	// il ne suffit pas : l'agent refuse de son côté toute archive en clair tant
	// que son propre `mes_options.php` ne l'autorise pas. Deux accords
	// explicites, sur deux sites différents, pour télécharger sans chiffrement.
	$url = trim((string) _request('url_archives_spip'));
	$schemas = _request('autoriser_http') ? '#^https?://#i' : '#^https://#i';
	if ($url !== '' && !preg_match($schemas, $url)) {
		$erreurs['url_archives_spip'] = _T('dashboard:erreur_url_archives');
	}

	// Même exigence pour le script d'installation : c'est un fichier PHP qu'on
	// va déposer à la racine web d'un site, le transport doit être authentifié.
	$loader = trim((string) _request('url_spip_loader'));
	if ($loader !== '' && !preg_match($schemas, $loader)) {
		$erreurs['url_spip_loader'] = _T('dashboard:erreur_url_loader');
	}

	// Et pour SPIP Check, qui est du même bois : un fichier PHP déposé à la
	// racine web d'un site géré.
	$check = trim((string) _request('url_spip_check'));
	if ($check !== '' && !preg_match($schemas, $check)) {
		$erreurs['url_spip_check'] = _T('dashboard:erreur_url_check');
	}

	/* L'épingle est facultative, mais une épingle mal recopiée serait pire que
	   pas d'épingle du tout : elle bloquerait tout dépôt en accusant la forge.
	   Soixante-quatre caractères hexadécimaux, ou rien. */
	$empreinte = trim((string) _request('sha256_spip_check'));
	if ($empreinte !== '' && !preg_match('/^[a-f0-9]{64}$/i', $empreinte)) {
		$erreurs['sha256_spip_check'] = _T('dashboard:erreur_sha256_check');
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
	$config['url_spip_loader']       = trim((string) _request('url_spip_loader'));
	$config['url_spip_check']        = trim((string) _request('url_spip_check'));
	$config['sha256_spip_check']     = strtolower(trim((string) _request('sha256_spip_check')));
	$config['versions_manuelles']    = trim((string) _request('versions_manuelles'));
	$config['retention_journal']     = max(1, (int) _request('retention_journal'));
	$config['retention_sauvegardes'] = max(1, (int) _request('retention_sauvegardes'));
	$config['fraicheur_sauvegarde']  = max(0, (int) _request('fraicheur_sauvegarde'));
	$config['fraicheur_depots']      = max(0, (int) _request('fraicheur_depots'));

	foreach (['sync_auto', 'autoriser_http'] as $bascule) {
		$config[$bascule] = (_request($bascule) === 'on') ? 'on' : '';
	}

	// L'index des archives est remis en cause : on invalide le cache pour que la
	// prochaine lecture reflète immédiatement le nouveau réglage.
	unset($config['cache_versions']);

	ecrire_config('dashboard', $config);

	return ['message_ok' => _T('dashboard:config_enregistree'), 'editable' => true];
}
