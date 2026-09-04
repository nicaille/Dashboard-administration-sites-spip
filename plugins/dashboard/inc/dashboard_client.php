<?php
/**
 * Client HTTP signé du tableau de bord.
 *
 * C'est le seul endroit d'où partent des requêtes vers les sites gérés : la
 * signature, les délais et la vérification TLS y sont centralisés.
 *
 * @package SPIP\Dashboard\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Chiffre une valeur sensible avant stockage.
 *
 * @param string $valeur
 * @return string
 */
function dashboard_chiffrer($valeur) {
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
 * Déchiffre une valeur produite par dashboard_chiffrer().
 *
 * @param string $valeur
 * @return string
 */
function dashboard_dechiffrer($valeur) {
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

	return $valeur;
}

/**
 * Lit une clef de configuration du tableau de bord.
 *
 * @param string $clef
 * @param mixed $defaut
 * @return mixed
 */
function dashboard_config($clef, $defaut = null) {
	include_spip('inc/config');
	$valeur = lire_config('dashboard/' . $clef, $defaut);

	return ($valeur === null || $valeur === '') ? $defaut : $valeur;
}

/**
 * Construit les champs signés d'une requête vers un agent.
 *
 * C'est la seule définition de la signature côté tableau de bord ; son pendant
 * exact côté agent est `dashagent_signer()`. Toute évolution doit toucher les
 * deux en même temps, sinon plus aucun site du parc ne répond.
 *
 * @param string $op
 * @param string $args JSON brut, transmis tel quel
 * @param int $ts
 * @param string $nonce
 * @param string $secret
 * @return array Champs à poster
 */
function dashboard_signer($op, $args, $ts, $nonce, $secret) {
	return [
		'op'    => $op,
		'args'  => $args,
		'ts'    => $ts,
		'nonce' => $nonce,
		'sig'   => hash_hmac('sha256', $op . "\n" . $args . "\n" . $ts . "\n" . $nonce, $secret),
	];
}

/**
 * Charge un site du parc.
 *
 * @param int $id_dashboard_site
 * @return array|null
 */
function dashboard_charger_site($id_dashboard_site) {
	$site = sql_fetsel('*', 'spip_dashboard_sites', 'id_dashboard_site = ' . (int) $id_dashboard_site);

	return $site ?: null;
}

/**
 * Appelle une opération sur l'agent d'un site.
 *
 * @param array $site Ligne de spip_dashboard_sites
 * @param string $op
 * @param array $args
 * @param array $options
 *     - int `timeout` : délai en secondes
 * @return array{ok: bool, data: array, erreur: array, http: int, duree_ms: int}
 */
function dashboard_appeler($site, $op, $args = [], $options = []) {
	$debut = microtime(true);

	$url = trim((string) ($site['url_agent'] ?? ''));
	if ($url === '') {
		return dashboard_reponse_erreur('url_absente', 'Aucune URL d’agent configurée pour ce site.', $debut);
	}
	if (!dashboard_url_acceptable($url)) {
		return dashboard_reponse_erreur('url_non_https', 'L’URL de l’agent doit être en https.', $debut);
	}

	$secret = dashboard_dechiffrer((string) ($site['secret'] ?? ''));
	if ($secret === '') {
		return dashboard_reponse_erreur('secret_absent', 'Aucun secret partagé enregistré pour ce site.', $debut);
	}

	$json  = $args ? json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
	$ts    = time();
	$nonce = bin2hex(random_bytes(16));

	$champs = dashboard_signer($op, $json, $ts, $nonce, $secret);

	$timeout = (int) ($options['timeout'] ?? dashboard_config('timeout', 30));
	$http    = dashboard_http_post($url, $champs, $timeout);

	if (!$http['ok']) {
		return dashboard_reponse_erreur('transport', $http['erreur'], $debut, $http['code']);
	}

	$reponse = json_decode((string) $http['corps'], true);
	if (!is_array($reponse)) {
		return dashboard_reponse_erreur(
			'reponse_illisible',
			'Réponse non JSON (HTTP ' . $http['code'] . ') : ' . substr(strip_tags((string) $http['corps']), 0, 200),
			$debut,
			$http['code']
		);
	}

	if (empty($reponse['ok'])) {
		$erreur = $reponse['erreur'] ?? [];

		return [
			'ok'     => false,
			'data'   => is_array($erreur['detail'] ?? null) ? $erreur['detail'] : [],
			'erreur' => [
				'code'    => (string) ($erreur['code'] ?? 'inconnue'),
				'message' => (string) ($erreur['message'] ?? 'Erreur non détaillée par l’agent'),
			],
			'http'     => $http['code'],
			'duree_ms' => dashboard_duree($debut),
			'agent'    => (string) ($reponse['agent'] ?? ''),
		];
	}

	return [
		'ok'       => true,
		'data'     => is_array($reponse['data'] ?? null) ? $reponse['data'] : [],
		'erreur'   => [],
		'http'     => $http['code'],
		'duree_ms' => dashboard_duree($debut),
		'agent'    => (string) ($reponse['agent'] ?? ''),
		'horloge'  => (int) ($reponse['horloge'] ?? 0),
	];
}

/**
 * Rapatrie une sauvegarde depuis un site géré.
 *
 * @param array $site
 * @param string $identifiant
 * @param string $destination Chemin local du fichier à écrire
 * @return array{ok: bool, erreur: array, octets: int, sha256: string}
 */
function dashboard_telecharger_sauvegarde($site, $identifiant, $destination) {
	$debut  = microtime(true);
	$secret = dashboard_dechiffrer((string) ($site['secret'] ?? ''));
	$url    = trim((string) ($site['url_agent'] ?? ''));

	if ($secret === '' || !dashboard_url_acceptable($url)) {
		return ['ok' => false, 'erreur' => ['code' => 'configuration', 'message' => 'Site mal configuré'], 'octets' => 0, 'sha256' => ''];
	}

	$json  = json_encode(['identifiant' => $identifiant], JSON_UNESCAPED_SLASHES);
	$ts    = time();
	$nonce = bin2hex(random_bytes(16));
	$champs = dashboard_signer('sauvegarde_telecharger', $json, $ts, $nonce, $secret);

	$http = dashboard_http_post($url, $champs, (int) dashboard_config('timeout_long', 300), $destination);

	if (!$http['ok']) {
		@unlink($destination);

		return ['ok' => false, 'erreur' => ['code' => 'transport', 'message' => $http['erreur']], 'octets' => 0, 'sha256' => ''];
	}

	// Une erreur applicative revient en JSON dans le fichier : on la détecte
	// plutôt que d'enregistrer une « sauvegarde » de 200 octets illisible.
	if (filesize($destination) < 4096) {
		$debut_fichier = (string) file_get_contents($destination, false, null, 0, 512);
		if (strncmp(ltrim($debut_fichier), '{', 1) === 0) {
			$json_erreur = json_decode((string) file_get_contents($destination), true);
			@unlink($destination);
			$message = $json_erreur['erreur']['message'] ?? 'Réponse inattendue de l’agent';

			return ['ok' => false, 'erreur' => ['code' => 'agent', 'message' => (string) $message], 'octets' => 0, 'sha256' => ''];
		}
	}

	return [
		'ok'       => true,
		'erreur'   => [],
		'octets'   => (int) filesize($destination),
		'sha256'   => hash_file('sha256', $destination),
		'duree_ms' => dashboard_duree($debut),
	];
}

/**
 * POST HTTP vers un agent.
 *
 * cURL est privilégié pour maîtriser les délais et refuser les redirections ;
 * la couche `recuperer_url` de SPIP sert de repli quand cURL est absent.
 *
 * @param string $url
 * @param array $champs
 * @param int $timeout
 * @param string $fichier Si fourni, le corps est écrit dans ce fichier
 * @return array{ok: bool, code: int, corps: string, erreur: string}
 */
function dashboard_http_post($url, $champs, $timeout = 30, $fichier = '') {
	$timeout = max(5, min(900, (int) $timeout));

	if (function_exists('curl_init')) {
		return dashboard_http_post_curl($url, $champs, $timeout, $fichier);
	}

	include_spip('inc/distant');
	$options = [
		'methode'         => 'POST',
		'datas'           => $champs,
		'taille_max'      => 256 * 1024 * 1024,
		'follow_location' => 0,
	];
	if ($fichier !== '') {
		$options['file'] = $fichier;
	}
	$res = recuperer_url($url, $options);

	if (!is_array($res)) {
		return ['ok' => false, 'code' => 0, 'corps' => '', 'erreur' => 'Requête impossible (aucune couche HTTP disponible)'];
	}
	$code = (int) ($res['status'] ?? 0);
	if (!$code) {
		return ['ok' => false, 'code' => 0, 'corps' => '', 'erreur' => 'Site injoignable'];
	}

	return ['ok' => true, 'code' => $code, 'corps' => (string) ($res['page'] ?? ''), 'erreur' => ''];
}

/**
 * Implémentation cURL du POST.
 *
 * @param string $url
 * @param array $champs
 * @param int $timeout
 * @param string $fichier
 * @return array
 */
function dashboard_http_post_curl($url, $champs, $timeout, $fichier = '') {
	$ch = curl_init($url);
	if (!$ch) {
		return ['ok' => false, 'code' => 0, 'corps' => '', 'erreur' => 'Initialisation cURL impossible'];
	}

	$sortie = null;
	$options = [
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => http_build_query($champs),
		CURLOPT_CONNECTTIMEOUT => 15,
		CURLOPT_TIMEOUT        => $timeout,
		// Suivre une redirection rejouerait la requête ailleurs, éventuellement
		// en clair : mieux vaut échouer visiblement et corriger l'URL.
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_USERAGENT      => 'SPIP Dashboard/1.0 (+' . url_de_base() . ')',
		CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Expect:'],
	];

	if ($fichier !== '') {
		$sortie = @fopen($fichier, 'wb');
		if (!$sortie) {
			curl_close($ch);

			return ['ok' => false, 'code' => 0, 'corps' => '', 'erreur' => 'Fichier de destination non inscriptible'];
		}
		$options[CURLOPT_FILE] = $sortie;
	} else {
		$options[CURLOPT_RETURNTRANSFER] = true;
	}

	curl_setopt_array($ch, $options);
	$corps = curl_exec($ch);
	$code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$erreur = curl_error($ch);
	curl_close($ch);
	if ($sortie) {
		fclose($sortie);
	}

	if ($corps === false && $erreur !== '') {
		return ['ok' => false, 'code' => $code, 'corps' => '', 'erreur' => $erreur];
	}
	if ($code >= 300 && $code < 400) {
		return ['ok' => false, 'code' => $code, 'corps' => '', 'erreur' => 'L’agent répond par une redirection (HTTP ' . $code . ') : vérifiez l’URL exacte.'];
	}

	return ['ok' => true, 'code' => $code, 'corps' => is_string($corps) ? $corps : '', 'erreur' => ''];
}

/**
 * L'URL d'agent est-elle utilisable ?
 *
 * Le http en clair n'est toléré que si le tableau de bord l'autorise
 * explicitement — utile en développement local, jamais en production.
 *
 * @param string $url
 * @return bool
 */
function dashboard_url_acceptable($url) {
	if (preg_match('#^https://#i', $url)) {
		return true;
	}

	return preg_match('#^http://#i', $url) && dashboard_config('autoriser_http', '') === 'on';
}

/**
 * Fabrique une réponse d'erreur homogène.
 *
 * @param string $code
 * @param string $message
 * @param float $debut
 * @param int $http
 * @return array
 */
function dashboard_reponse_erreur($code, $message, $debut, $http = 0) {
	return [
		'ok'       => false,
		'data'     => [],
		'erreur'   => ['code' => $code, 'message' => $message],
		'http'     => (int) $http,
		'duree_ms' => dashboard_duree($debut),
	];
}

/**
 * @param float $debut
 * @return int
 */
function dashboard_duree($debut) {
	return (int) round((microtime(true) - $debut) * 1000);
}

/**
 * Génère un secret partagé.
 *
 * @return string
 */
function dashboard_generer_secret() {
	return bin2hex(random_bytes(32));
}
