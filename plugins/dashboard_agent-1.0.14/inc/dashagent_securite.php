<?php
/**
 * Contrôle d'accès de l'agent Dashboard.
 *
 * Le point d'entrée est public (les hébergements mutualisés ne permettent pas
 * de compter sur une restriction réseau), la sécurité repose donc entièrement
 * sur : secret partagé + signature HMAC-SHA256 + fenêtre temporelle + anti-rejeu,
 * avec en option une liste blanche d'adresses IP.
 *
 * @package SPIP\Dashagent\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Calcule la signature d'une requête.
 *
 * La base signée est la concaténation des champs transmis tels quels, séparés
 * par des sauts de ligne : on signe l'octet transmis, jamais une re-sérialisation,
 * ce qui évite toute divergence de canonicalisation entre les deux extrémités.
 *
 * @param string $op
 * @param string $args JSON brut, tel qu'il sera transmis
 * @param int $ts
 * @param string $nonce
 * @param string $secret
 * @return string Signature hexadécimale
 */
function dashagent_signer($op, $args, $ts, $nonce, $secret) {
	$base = $op . "\n" . $args . "\n" . $ts . "\n" . $nonce;

	return hash_hmac('sha256', $base, $secret);
}

/**
 * Vérifie la requête entrante et retourne l'opération demandée.
 *
 * En cas d'échec la fonction n'a pas de valeur de retour : elle émet la réponse
 * d'erreur et interrompt le script.
 *
 * @return array{op: string, args: array}
 */
function dashagent_verifier_requete() {
	include_spip('inc/dashagent');

	if (!dashagent_est_appaire()) {
		dashagent_erreur('non_appaire', 'Agent non appairé : aucun secret partagé configuré.', 503);
	}

	if (!dashagent_ip_autorisee(dashagent_ip_client())) {
		dashagent_journaliser('-', 'refus', 'Adresse IP non autorisée');
		dashagent_erreur('ip_refusee', 'Adresse IP non autorisée.', 403);
	}

	$op    = (string) _request('op');
	$args  = _request('args');
	$ts    = (string) _request('ts');
	$nonce = (string) _request('nonce');
	$sig   = (string) _request('sig');

	if (!is_string($args)) {
		$args = '';
	}
	if ($op === '' || $ts === '' || $nonce === '' || $sig === '') {
		dashagent_erreur('requete_incomplete', 'Champs op, ts, nonce et sig obligatoires.', 400);
	}
	if (!preg_match('/^[a-z0-9_]{1,64}$/', $op)) {
		dashagent_erreur('operation_invalide', 'Nom d’opération invalide.', 400);
	}
	if (!preg_match('/^[a-f0-9]{16,64}$/', $nonce)) {
		dashagent_erreur('nonce_invalide', 'Nonce invalide.', 400);
	}
	if (!preg_match('/^[a-f0-9]{64}$/', $sig)) {
		dashagent_erreur('signature_invalide', 'Signature invalide.', 403);
	}
	if (strlen($args) > 65536) {
		dashagent_erreur('args_trop_longs', 'Arguments trop volumineux.', 413);
	}

	$tolerance = (int) dashagent_config('tolerance_horloge', _DASHAGENT_TOLERANCE_HORLOGE);
	$tolerance = min(3600, max(30, $tolerance));
	if (abs(time() - (int) $ts) > $tolerance) {
		dashagent_journaliser($op, 'refus', 'Horodatage hors fenêtre', ['ts' => (int) $ts, 'local' => time()]);
		dashagent_erreur('horloge', 'Horodatage hors de la fenêtre acceptée.', 403, ['horloge_agent' => time()]);
	}

	$attendue = dashagent_signer($op, $args, (int) $ts, $nonce, dashagent_secret());
	if (!hash_equals($attendue, $sig)) {
		dashagent_journaliser($op, 'refus', 'Signature invalide');
		dashagent_erreur('signature_invalide', 'Signature invalide.', 403);
	}

	// Anti-rejeu : c'est l'unicité de la clef primaire qui fait foi, pas un SELECT
	// préalable — deux requêtes concurrentes portant le même nonce ne peuvent pas
	// passer toutes les deux.
	if (!dashagent_consommer_nonce($nonce)) {
		dashagent_journaliser($op, 'refus', 'Rejeu détecté');
		dashagent_erreur('rejeu', 'Nonce déjà utilisé.', 409);
	}

	if (!dashagent_operation_autorisee($op)) {
		dashagent_journaliser($op, 'refus', 'Opération désactivée sur ce site');
		dashagent_erreur('operation_desactivee', 'Cette opération est désactivée sur ce site.', 403);
	}

	$decode = [];
	if ($args !== '') {
		$decode = json_decode($args, true);
		if (!is_array($decode)) {
			dashagent_erreur('args_invalides', 'Arguments JSON illisibles.', 400);
		}
	}

	return ['op' => $op, 'args' => $decode];
}

/**
 * Marque un nonce comme consommé.
 *
 * @param string $nonce
 * @return bool false si le nonce avait déjà servi
 */
function dashagent_consommer_nonce($nonce) {
	// L'option 'continue' demande à SPIP de retourner false au lieu d'interrompre
	// l'exécution : un doublon de clef primaire se traduit donc par un false.
	$ok = sql_insertq(
		'spip_dashagent_nonces',
		['nonce' => $nonce, 'date' => date('Y-m-d H:i:s')],
		[],
		'',
		'continue'
	);

	return $ok !== false;
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
		// Même autorisation : c'est la même chose, faite par SVP plutôt qu'à la
		// main. Un site qui refuse la mise à jour de ses plugins la refuse par
		// les deux chemins.
		'plugin_svp_preflight'    => 'op_plugin_maj',
		'plugin_svp_preparer'     => 'op_plugin_maj',
		'plugin_svp_avancer'      => 'op_plugin_maj',
		'plugin_svp_liberer'      => 'op_plugin_maj',
		// Relire le catalogue des dépôts, c'est savoir quelles versions
		// existent : cela va de pair avec le droit de mettre à jour.
		'depots_actualiser'       => 'op_plugin_maj',
		'core_maj'                => 'op_core_maj',
		'core_maj_preflight'      => 'op_core_maj',
		// La migration du schéma va de pair avec le remplacement des fichiers :
		// l'une sans l'autre laisse le site à moitié à jour.
		'base_maj'                => 'op_core_maj',
		'base_maj_preflight'      => 'op_core_maj',
		// Consultation de l'état du serveur : une autorisation à part, parce
		// qu'elle expose des choses qu'aucune autre opération ne montre — la
		// configuration de PHP, le contenu des tables, des fichiers de réglage.
		'serveur_resume'          => 'op_serveur',
		'serveur_phpinfo'         => 'op_serveur',
		'serveur_tables'          => 'op_serveur',
		'serveur_table'           => 'op_serveur',
		'serveur_fichier'         => 'op_serveur',
		// Le tableau de bord du plugin SPIP WAF : qui a été bloqué, pourquoi,
		// depuis quelle adresse. C'est une lecture, mais elle nomme des
		// visiteurs — elle a son autorisation à elle.
		'waf_resume'              => 'op_waf',
		// spip_loader.php vit à la racine web et installe ce qu'on lui dit
		// d'installer : le remplacer est le geste le plus lourd de conséquences
		// qu'on puisse demander à distance, et il ne se confond avec aucun autre.
		'loader_etat'             => 'op_loader',
		'loader_maj'              => 'op_loader',
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
 * Adresse IP de l'appelant.
 *
 * Les en-têtes de proxy ne sont pris en compte que si le site les déclare
 * explicitement dignes de confiance, sinon ils sont trivialement falsifiables.
 *
 * Même déclaré de confiance, le proxy transmet un en-tête que le client a pu
 * écrire : ce qui en sort doit ressembler à une adresse IP et à rien d'autre.
 * Cette valeur est enregistrée au journal — y compris pour une requête refusée,
 * donc non authentifiée — et le journal s'affiche dans l'espace privé du site.
 * Sans ce contrôle, n'importe qui connaissant l'URL de l'agent y déposerait le
 * balisage de son choix.
 *
 * @param array|null $serveur Variables serveur, injectables pour les tests
 * @return string
 */
function dashagent_ip_client($serveur = null) {
	$serveur = is_array($serveur) ? $serveur : $_SERVER;
	$distante = isset($serveur['REMOTE_ADDR']) ? (string) $serveur['REMOTE_ADDR'] : '';

	if (defined('_DASHAGENT_PROXY_DE_CONFIANCE') && _DASHAGENT_PROXY_DE_CONFIANCE) {
		foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $entete) {
			if (empty($serveur[$entete])) {
				continue;
			}
			$ips = explode(',', (string) $serveur[$entete]);
			$annoncee = trim((string) reset($ips));
			if (filter_var($annoncee, FILTER_VALIDATE_IP) !== false) {
				return $annoncee;
			}
		}
	}

	// REMOTE_ADDR vient du serveur, mais le valider ne coûte rien et ferme la
	// question pour de bon.
	return (filter_var($distante, FILTER_VALIDATE_IP) !== false) ? $distante : '';
}

/**
 * L'adresse IP est-elle dans la liste blanche ?
 *
 * Une liste vide vaut « pas de filtrage » : la signature reste la protection
 * principale, la liste blanche n'est qu'une couche supplémentaire.
 *
 * @param string $ip
 * @return bool
 */
function dashagent_ip_autorisee($ip) {
	$liste = trim((string) dashagent_config('ips_autorisees', ''));
	if ($liste === '') {
		return true;
	}
	if ($ip === '') {
		return false;
	}

	foreach (preg_split('/[\s,;]+/', $liste) as $motif) {
		$motif = trim($motif);
		if ($motif !== '' && dashagent_ip_correspond($ip, $motif)) {
			return true;
		}
	}

	return false;
}

/**
 * Compare une IP à un motif : adresse exacte ou notation CIDR (v4 et v6).
 *
 * @param string $ip
 * @param string $motif
 * @return bool
 */
function dashagent_ip_correspond($ip, $motif) {
	if (strpos($motif, '/') === false) {
		return inet_pton($ip) !== false && inet_pton($ip) === inet_pton($motif);
	}

	[$reseau, $bits] = explode('/', $motif, 2);
	$bin_ip     = @inet_pton($ip);
	$bin_reseau = @inet_pton($reseau);
	$bits       = (int) $bits;

	if ($bin_ip === false || $bin_reseau === false || strlen($bin_ip) !== strlen($bin_reseau)) {
		return false;
	}
	if ($bits < 0 || $bits > strlen($bin_ip) * 8) {
		return false;
	}

	$octets_pleins = intdiv($bits, 8);
	$bits_restants = $bits % 8;

	if ($octets_pleins && strncmp($bin_ip, $bin_reseau, $octets_pleins) !== 0) {
		return false;
	}
	if ($bits_restants === 0) {
		return true;
	}

	$masque = 0xff << (8 - $bits_restants) & 0xff;

	return (ord($bin_ip[$octets_pleins]) & $masque) === (ord($bin_reseau[$octets_pleins]) & $masque);
}

/**
 * Génère un secret partagé de qualité cryptographique.
 *
 * @return string 64 caractères hexadécimaux (256 bits d'entropie)
 */
function dashagent_generer_secret() {
	return bin2hex(random_bytes(32));
}
