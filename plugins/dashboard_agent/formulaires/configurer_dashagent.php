<?php
/**
 * Formulaire de configuration de l'agent Dashboard.
 *
 * @package SPIP\Dashagent\Formulaires
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Champs du formulaire.
 *
 * Le secret n'est jamais réaffiché : il n'est visible qu'une seule fois, au
 * moment où il est généré.
 *
 * @return array
 */
function formulaires_configurer_dashagent_charger_dist() {
	include_spip('inc/dashagent');

	return [
		'secret_present'    => dashagent_est_appaire() ? 'oui' : 'non',
		'secret_empreinte'  => dashagent_est_appaire() ? substr(hash('sha256', dashagent_secret()), 0, 12) : '',
		'secret_clair'      => '',
		'generer'           => '',
		'ips_autorisees'    => dashagent_config('ips_autorisees', ''),
		'tolerance_horloge' => dashagent_config('tolerance_horloge', 300),
		'op_infos'          => dashagent_config('op_infos', ''),
		'op_purger'         => dashagent_config('op_purger', ''),
		'op_sauvegarde'     => dashagent_config('op_sauvegarde', ''),
		'op_plugin_maj'     => dashagent_config('op_plugin_maj', ''),
		'op_core_maj'       => dashagent_config('op_core_maj', ''),
		'retention_journal' => dashagent_config('retention_journal', 90),
		'retention_backup'  => dashagent_config('retention_backup', 7),
		'url_agent'         => url_de_base() . 'spip.php?action=dashagent',
	];
}

/**
 * Validation.
 *
 * @return array
 */
function formulaires_configurer_dashagent_verifier_dist() {
	include_spip('inc/dashagent');

	$erreurs = [];

	$secret = trim((string) _request('secret_clair'));
	if ($secret !== '' && strlen($secret) < _DASHAGENT_SECRET_LONGUEUR_MIN) {
		$erreurs['secret_clair'] = _T('dashagent:erreur_secret_court', ['min' => _DASHAGENT_SECRET_LONGUEUR_MIN]);
	}

	$tolerance = (int) _request('tolerance_horloge');
	if ($tolerance < 30 || $tolerance > 3600) {
		$erreurs['tolerance_horloge'] = _T('dashagent:erreur_tolerance');
	}

	include_spip('inc/dashagent_securite');
	foreach (preg_split('/[\s,;]+/', trim((string) _request('ips_autorisees'))) as $motif) {
		$motif = trim($motif);
		if ($motif === '') {
			continue;
		}
		$reseau = explode('/', $motif)[0];
		if (@inet_pton($reseau) === false) {
			$erreurs['ips_autorisees'] = _T('dashagent:erreur_ip', ['ip' => $motif]);
			break;
		}
	}

	return $erreurs;
}

/**
 * Enregistrement.
 *
 * @return array
 */
function formulaires_configurer_dashagent_traiter_dist() {
	include_spip('inc/config');
	include_spip('inc/dashagent');
	include_spip('inc/dashagent_securite');

	$config = lire_config('dashagent', []);
	if (!is_array($config)) {
		$config = [];
	}

	$nouveau_secret = '';
	if (_request('generer') === 'on') {
		$nouveau_secret = dashagent_generer_secret();
	} elseif (trim((string) _request('secret_clair')) !== '') {
		$nouveau_secret = trim((string) _request('secret_clair'));
	}
	if ($nouveau_secret !== '') {
		$config['secret'] = dashagent_chiffrer($nouveau_secret);
	}

	$config['ips_autorisees']    = trim((string) _request('ips_autorisees'));
	$config['tolerance_horloge'] = (int) _request('tolerance_horloge');
	$config['retention_journal'] = max(1, (int) _request('retention_journal'));
	$config['retention_backup']  = max(1, (int) _request('retention_backup'));

	foreach (['op_infos', 'op_purger', 'op_sauvegarde', 'op_plugin_maj', 'op_core_maj'] as $op) {
		$config[$op] = (_request($op) === 'on') ? 'on' : '';
	}

	ecrire_config('dashagent', $config);

	$message = _T('dashagent:config_enregistree');
	if ($nouveau_secret !== '') {
		$message .= ' ' . _T('dashagent:secret_a_copier', ['secret' => $nouveau_secret]);
	}

	return ['message_ok' => $message, 'editable' => true];
}
