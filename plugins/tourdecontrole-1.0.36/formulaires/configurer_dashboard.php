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
	include_spip('inc/dashboard_alertes');

	return [
		'timeout'               => dashboard_config('timeout', 30),
		'timeout_long'          => dashboard_config('timeout_long', 300),
		// Pas `dashboard_config()` : une case décochée vaut la chaîne vide,
		// qu'il traite comme une absence et remplace par le défaut.
		'sync_auto'             => dashboard_sync_auto() ? 'on' : '',
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
		// Les alertes, éteintes tant qu'on ne les allume pas. Même raison que
		// pour `sync_auto` : une case décochée vaut la chaîne vide, que
		// `dashboard_config()` prendrait pour une absence et remplacerait par
		// son défaut — un réglage qu'on ne pourrait plus éteindre.
		'alerte_active'         => dashboard_alerte_active() ? 'on' : '',
		'alerte_destinataires'  => dashboard_config('alerte_destinataires', ''),
		'alerte_sujet'          => dashboard_config('alerte_sujet', ''),
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

	/* Les destinataires des alertes : une adresse par ligne, ou séparées par
	   des virgules. Vide est parfaitement valide — c'est le défaut, et il
	   signifie « n'écrire à personne ». Une adresse fautive, en revanche, se
	   signale ici : la découvrir au premier envoi voudrait dire un parc en
	   difficulté et un courriel qui ne part pas. */
	include_spip('inc/filtres');
	foreach (preg_split('/[\s,;]+/', (string) _request('alerte_destinataires'), -1, PREG_SPLIT_NO_EMPTY) as $adresse) {
		if (!email_valide($adresse)) {
			$erreurs['alerte_destinataires'] = _T('dashboard:erreur_alerte_destinataires', ['adresse' => $adresse]);
			break;
		}
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

	$config['alerte_destinataires'] = trim((string) _request('alerte_destinataires'));
	$config['alerte_sujet']         = trim((string) _request('alerte_sujet'));

	foreach (['sync_auto', 'autoriser_http', 'alerte_active'] as $bascule) {
		$config[$bascule] = (_request($bascule) === 'on') ? 'on' : '';
	}

	// L'index des archives est remis en cause : on invalide le cache pour que la
	// prochaine lecture reflète immédiatement le nouveau réglage.
	unset($config['cache_versions']);

	ecrire_config('dashboard', $config);

	return ['message_ok' => _T('dashboard:config_enregistree'), 'editable' => true];
}
