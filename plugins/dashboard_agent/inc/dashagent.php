<?php
/**
 * Briques communes de l'agent Dashboard : configuration, chiffrement du secret,
 * journal d'audit et sérialisation des réponses JSON.
 *
 * @package SPIP\Dashagent\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Lit une clef de configuration de l'agent.
 *
 * @param string $clef
 * @param mixed $defaut
 * @return mixed
 */
function dashagent_config($clef, $defaut = null) {
	include_spip('inc/config');
	$valeur = lire_config('dashagent/' . $clef, $defaut);

	return ($valeur === null || $valeur === '') ? $defaut : $valeur;
}

/**
 * Chiffre une valeur sensible avant stockage en base.
 *
 * Utilise le chiffrement du core SPIP 4 lorsqu'il est disponible ; à défaut la
 * valeur est stockée telle quelle et l'appelant en est informé par le préfixe.
 *
 * @param string $valeur
 * @return string
 */
function dashagent_chiffrer($valeur) {
	if ($valeur === '') {
		return '';
	}
	include_spip('inc/chiffrer');
	if (function_exists('chiffrer')) {
		return 'c1:' . chiffrer($valeur);
	}

	return 'p0:' . $valeur;
}

/**
 * Déchiffre une valeur stockée par dashagent_chiffrer().
 *
 * @param string $valeur
 * @return string
 */
function dashagent_dechiffrer($valeur) {
	if (!is_string($valeur) || $valeur === '') {
		return '';
	}
	if (strncmp($valeur, 'c1:', 3) === 0) {
		include_spip('inc/chiffrer');
		if (!function_exists('dechiffrer')) {
			return '';
		}
		$clair = dechiffrer(substr($valeur, 3));

		return is_string($clair) ? $clair : '';
	}
	if (strncmp($valeur, 'p0:', 3) === 0) {
		return substr($valeur, 3);
	}

	// Valeur historique, non préfixée.
	return $valeur;
}

/**
 * Retourne le secret partagé en clair, ou une chaîne vide si l'agent n'est pas appairé.
 *
 * @return string
 */
function dashagent_secret() {
	return dashagent_dechiffrer((string) dashagent_config('secret', ''));
}

/**
 * L'agent est-il utilisable ? (secret présent et suffisamment long)
 *
 * @return bool
 */
function dashagent_est_appaire() {
	return strlen(dashagent_secret()) >= _DASHAGENT_SECRET_LONGUEUR_MIN;
}

/**
 * Une opération est-elle autorisée par la configuration locale du site ?
 *
 * Le site géré garde toujours le dernier mot : le dashboard ne peut pas
 * activer à distance une opération que l'administrateur du site a refusée.
 *
 * @param string $operation
 * @return bool
 */
function dashagent_operation_autorisee($operation) {
	$permissions = [
		'ping'                    => 'toujours',
		'infos'                   => 'op_infos',
		'purger'                  => 'op_purger',
		'sauvegarde_creer'        => 'op_sauvegarde',
		'sauvegarde_lister'       => 'op_sauvegarde',
		'sauvegarde_telecharger'  => 'op_sauvegarde',
		'sauvegarde_supprimer'    => 'op_sauvegarde',
		'plugin_maj'              => 'op_plugin_maj',
		'plugin_maj_preflight'    => 'op_plugin_maj',
		'core_maj'                => 'op_core_maj',
		'core_maj_preflight'      => 'op_core_maj',
	];

	if (!isset($permissions[$operation])) {
		return false;
	}
	if ($permissions[$operation] === 'toujours') {
		return true;
	}

	return dashagent_config($permissions[$operation], '') === 'on';
}

/**
 * Ajoute une entrée au journal d'audit de l'agent.
 *
 * @param string $operation
 * @param string $statut ok|erreur|refus
 * @param string $message
 * @param mixed $detail
 * @param int $duree Durée en millisecondes
 * @return void
 */
function dashagent_journaliser($operation, $statut, $message = '', $detail = null, $duree = 0) {
	sql_insertq('spip_dashagent_journal', [
		'date'      => date('Y-m-d H:i:s'),
		'operation' => substr((string) $operation, 0, 64),
		'statut'    => substr((string) $statut, 0, 16),
		'ip'        => substr((string) dashagent_ip_client(), 0, 45),
		'duree'     => (int) $duree,
		'message'   => (string) $message,
		'detail'    => $detail === null ? '' : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
	]);

	dashagent_purger_journal();
}

/**
 * Supprime les entrées de journal et les nonces périmés.
 *
 * @return void
 */
function dashagent_purger_journal() {
	// Une purge sur ~1 requête sur 20 suffit largement et évite un DELETE systématique.
	if (random_int(1, 20) !== 1) {
		return;
	}
	$retention = max(1, (int) dashagent_config('retention_journal', 90));
	sql_delete('spip_dashagent_journal', 'date < ' . sql_quote(date('Y-m-d H:i:s', time() - $retention * 86400)));
	sql_delete('spip_dashagent_nonces', 'date < ' . sql_quote(date('Y-m-d H:i:s', time() - 86400)));
}

/**
 * Adresse IP de l'appelant.
 *
 * Les en-têtes de proxy ne sont pris en compte que si le site les déclare
 * explicitement dignes de confiance, sinon ils sont trivialement falsifiables.
 *
 * @return string
 */
function dashagent_ip_client() {
	if (defined('_DASHAGENT_PROXY_DE_CONFIANCE') && _DASHAGENT_PROXY_DE_CONFIANCE) {
		foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $entete) {
			if (!empty($_SERVER[$entete])) {
				$ips = explode(',', $_SERVER[$entete]);

				return trim(reset($ips));
			}
		}
	}

	return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
}

/**
 * Émet une réponse JSON et termine le script.
 *
 * @param array $data
 * @param int $code Code HTTP
 * @return void
 */
function dashagent_repondre($data, $code = 200) {
	if (!headers_sent()) {
		http_response_code($code);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store, private');
		header('X-Robots-Tag: noindex, nofollow');
	}

	$enveloppe = array_merge([
		'ok'         => true,
		'protocole'  => _DASHAGENT_PROTOCOLE,
		'agent'      => dashagent_version_plugin(),
		'horloge'    => time(),
	], $data);

	echo json_encode($enveloppe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
	exit;
}

/**
 * Émet une erreur JSON normalisée et termine le script.
 *
 * @param string $code Code d'erreur stable, exploitable par le dashboard
 * @param string $message Message lisible
 * @param int $http
 * @param array $detail
 * @return void
 */
function dashagent_erreur($code, $message, $http = 400, $detail = []) {
	dashagent_repondre([
		'ok'     => false,
		'erreur' => [
			'code'    => $code,
			'message' => $message,
			'detail'  => $detail,
		],
	], $http);
}

/**
 * Version déclarée du plugin agent.
 *
 * @return string
 */
function dashagent_version_plugin() {
	$infos = unserialize($GLOBALS['meta']['plugin'] ?? '') ?: [];

	return (string) ($infos['DASHAGENT']['version'] ?? '0');
}

/**
 * Crée si besoin le répertoire de travail de l'agent et le protège.
 *
 * @return string Chemin du répertoire, chaîne vide en cas d'échec
 */
function dashagent_dir_travail() {
	$dir = _DASHAGENT_DIR_TRAVAIL;
	if (!is_dir($dir)) {
		include_spip('inc/flock');
		sous_repertoire(_DIR_TMP, 'dashagent');
	}
	if (!is_dir($dir) || !is_writable($dir)) {
		return '';
	}

	// _DIR_TMP est déjà hors-web sur une installation saine ; ceinture et bretelles.
	if (!file_exists($dir . '.htaccess')) {
		@file_put_contents($dir . '.htaccess', "Deny from all\nRequire all denied\n");
	}
	if (!file_exists($dir . 'index.html')) {
		@file_put_contents($dir . 'index.html', '');
	}

	return $dir;
}
