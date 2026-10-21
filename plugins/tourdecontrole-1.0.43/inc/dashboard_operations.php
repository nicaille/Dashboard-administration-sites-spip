<?php
/**
 * Opérations pilotées depuis le tableau de bord vers un site géré.
 *
 * Chaque fonction suit le même contrat : elle appelle l'agent, journalise, et
 * renvoie `['ok' => bool, 'message' => string, 'data' => array]`. Les pages et
 * actions n'ont ainsi qu'un seul format de résultat à traiter.
 *
 * @package SPIP\Dashboard\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Adresse officielle du script d'installation de SPIP.
 *
 * Réglable depuis la configuration du tableau de bord, pour un miroir interne.
 */
if (!defined('_DASHBOARD_LOADER_URL')) {
	define('_DASHBOARD_LOADER_URL', 'https://get.spip.net/spip_loader.php');
}

/**
 * Adresse de téléchargement de SPIP Check.
 *
 * Le livrable est publié sur la forge communautaire, à la racine du dépôt.
 * L'adresse désigne une **branche**, pas une étiquette : chaque dépôt sert donc
 * ce qui y a été poussé, y compris un commit de développement. Pointer une
 * release figerait la version déposée ; c'est un réglage, et il se change.
 *
 * Le dépôt publie les deux orthographes, strictement identiques. On prend celle
 * à souligné, des deux côtés : c'est celle qui passe sur les hébergements qui
 * bloquent ou réécrivent les noms à tiret, et c'est celle de `spip_loader.php`,
 * son voisin de palier.
 *
 * Se règle dans *Configuration du tableau de bord*. Rien n'oblige à pointer le
 * dépôt d'origine : un miroir interne convient, et se contrôle. Vidé, l'encadré
 * le dit et ne propose aucun dépôt.
 */
if (!defined('_DASHBOARD_CHECK_URL')) {
	define('_DASHBOARD_CHECK_URL', 'https://git.spip.net/technova69/spip-check/-/raw/2.x/spip_check.php?ref_type=heads');
}

/**
 * Répertoire local de stockage des sauvegardes rapatriées.
 *
 * @param int $id_dashboard_site
 * @return string Chaîne vide si indisponible
 */
function dashboard_dir_sauvegardes($id_dashboard_site = 0) {
	include_spip('inc/flock');

	sous_repertoire(_DIR_TMP, 'dashboard');
	$dir = _DIR_TMP . 'dashboard/';
	sous_repertoire($dir, 'sauvegardes');
	$dir .= 'sauvegardes/';

	// _DIR_TMP est hors espace web sur une installation saine ; ceinture et bretelles.
	if (!file_exists(_DIR_TMP . 'dashboard/.htaccess')) {
		@file_put_contents(_DIR_TMP . 'dashboard/.htaccess', "Deny from all\nRequire all denied\n");
	}

	if ($id_dashboard_site) {
		sous_repertoire($dir, (string) (int) $id_dashboard_site);
		$dir .= (int) $id_dashboard_site . '/';
	}

	return (is_dir($dir) && is_writable($dir)) ? $dir : '';
}

/**
 * Vide un ou plusieurs caches d'un site géré.
 *
 * @param int $id_dashboard_site
 * @param array $cibles
 * @return array
 */
function dashboard_operation_purger($id_dashboard_site, $cibles = ['tout']) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$reponse = dashboard_appeler($site, 'purger', ['cibles' => array_values($cibles)], ['timeout' => dashboard_config('timeout_long', 300)]);

	$message = $reponse['ok']
		? 'Caches vidés : ' . (int) ($reponse['data']['purge']['fichiers'] ?? 0) . ' fichier(s)'
		: (string) ($reponse['erreur']['message'] ?? '');

	dashboard_journaliser($id_dashboard_site, 'purger', $reponse['ok'] ? 'ok' : 'erreur', $message, $reponse['data'], $reponse['duree_ms']);

	return ['ok' => $reponse['ok'], 'message' => $message, 'data' => $reponse['data']];
}

/**
 * Nombre de tranches qu'un export s'autorise avant qu'on le déclare sans fin.
 *
 * Vingt secondes par tranche : deux cents tranches, c'est plus d'une heure
 * d'export. Au-delà, ce n'est plus une base volumineuse, c'est une boucle.
 */
if (!defined('_DASHBOARD_SAUVEGARDE_TRANCHES_MAX')) {
	define('_DASHBOARD_SAUVEGARDE_TRANCHES_MAX', 200);
}

/**
 * Nombre de silences consécutifs qu'on retente avant de renoncer.
 */
if (!defined('_DASHBOARD_SAUVEGARDE_SILENCES_MAX')) {
	define('_DASHBOARD_SAUVEGARDE_SILENCES_MAX', 3);
}

/**
 * Conduit l'export de bout en bout, une tranche après l'autre.
 *
 * L'export d'un seul tenant a un adversaire qui n'est ni PHP ni la base : le
 * frontal du site géré, dont la patience est plus courte que la nôtre. On lui
 * demande donc des tranches, chacune bien en deçà de ses soixante secondes, et
 * on rappelle la même opération tant que l'agent dit ne pas avoir fini — c'est
 * le contrat qui gouverne déjà les chantiers et les actions de parc.
 *
 * Deux compatibilités à tenir, et elles vont dans les deux sens :
 *
 * - **un agent d'avant la 1.0.21** ignore le drapeau `decoupee` et rend
 *   l'export entier, sans clef `termine`. Son absence vaut donc « c'est fini » :
 *   le comportement d'avant, à l'identique. D'où le long délai sur le premier
 *   appel, seul moment où l'on ignore à qui l'on parle ;
 * - **un silence sur une tranche** ne dit rien de l'issue, comme toujours. Mais
 *   ici, contrairement à l'export d'un seul tenant, la reprise est idempotente :
 *   l'agent ramène son fichier au dernier point de contrôle et refait la
 *   tranche. Retenter est donc sans danger, et c'est la seule réponse utile à
 *   un 503 de frontal. Les silences consécutifs sont comptés — un site
 *   réellement tombé ne doit pas nous retenir indéfiniment.
 *
 * @param array $site Ligne de spip_dashboard_sites
 * @param array $options Options de la demande de sauvegarde
 * @return array{reponse: array, tranches: int}
 */
function dashboard_sauvegarde_exporter($site, $options = []) {
	$args = [
		'sans_statistiques' => !empty($options['sans_statistiques']),
		'decoupee'          => true,
	];

	$long  = (int) dashboard_config('timeout_long', 300);
	$court = min($long, 90);

	$tranches = 0;
	$silences = 0;
	$reponse  = [];

	for ($tour = 0; $tour < _DASHBOARD_SAUVEGARDE_TRANCHES_MAX; $tour++) {
		$reponse = dashboard_appeler(
			$site,
			'sauvegarde_creer',
			$args,
			['timeout' => $tour ? $court : $long]
		);

		$suite = dashboard_sauvegarde_suite($reponse, $silences);

		if ($suite['suite'] === 'retenter') {
			$silences++;
			continue;
		}
		if ($suite['suite'] === 'echec') {
			if ($suite['raison'] !== '' && !empty($reponse['ok'])) {
				$reponse = dashboard_reponse_erreur('protocole', $suite['raison'], microtime(true));
			}

			return ['reponse' => $reponse, 'tranches' => $tranches];
		}

		$silences = 0;
		$tranches++;

		if ($suite['suite'] === 'fini') {
			return ['reponse' => $reponse, 'tranches' => $tranches];
		}

		$args['reprendre'] = $suite['reprendre'];
	}

	return [
		'reponse' => dashboard_reponse_erreur(
			'export_sans_fin',
			'L’export n’en finit pas : ' . _DASHBOARD_SAUVEGARDE_TRANCHES_MAX . ' tranches sans aboutir.',
			microtime(true)
		),
		'tranches' => $tranches,
	];
}

/**
 * Ce que la réponse d'une tranche commande de faire ensuite.
 *
 * Séparé de la boucle parce que c'est là qu'est la règle, et qu'une règle se
 * vérifie : quatre issues, dont deux se ressemblent à s'y méprendre. Un agent
 * qui ne connaît pas le découpage et un agent qui vient de finir répondent tous
 * deux « c'est fini », mais pour des raisons opposées — l'un parce qu'il a tout
 * exporté d'un coup, l'autre parce qu'il a exporté la dernière tranche.
 *
 * Et un silence n'est toujours pas une réponse : ici, et **seulement** ici, il
 * se retente, parce que la reprise est idempotente — l'agent ramène son fichier
 * au dernier point de contrôle avant d'y toucher. Rien de tel dans l'export
 * d'un seul tenant, où retenter voudrait dire tout refaire.
 *
 * @param array $reponse Ce que dashboard_appeler() a rendu
 * @param int $silences Nombre de silences déjà essuyés d'affilée
 * @return array{suite: string, reprendre: string, raison: string}
 *     `suite` vaut « fini », « continuer », « retenter » ou « echec »
 */
function dashboard_sauvegarde_suite($reponse, $silences = 0) {
	if (empty($reponse['ok'])) {
		$code = (string) ($reponse['erreur']['code'] ?? '');
		if (dashboard_silence($code) && (int) $silences < _DASHBOARD_SAUVEGARDE_SILENCES_MAX) {
			return ['suite' => 'retenter', 'reprendre' => '', 'raison' => ''];
		}

		return ['suite' => 'echec', 'reprendre' => '', 'raison' => ''];
	}

	$data = (array) ($reponse['data'] ?? []);

	/* Un agent d'avant la 1.0.21 ignore le découpage et n'en dit donc rien.
	   L'absence de la clef vaut « c'est fini » : le comportement d'avant. */
	if (!array_key_exists('termine', $data) || !empty($data['termine'])) {
		return ['suite' => 'fini', 'reprendre' => '', 'raison' => ''];
	}

	$suivant = (string) ($data['reprendre'] ?? '');
	if ($suivant === '') {
		return ['suite' => 'echec', 'reprendre' => '',
			'raison' => 'L’agent annonce un export inachevé sans dire comment le reprendre.'];
	}

	return ['suite' => 'continuer', 'reprendre' => $suivant, 'raison' => ''];
}

/**
 * Demande une sauvegarde de la base et, par défaut, la rapatrie.
 *
 * Une sauvegarde qui reste sur le serveur du site ne protège de rien si c'est
 * l'hébergement qui tombe : le rapatriement est donc le comportement normal.
 *
 * @param int $id_dashboard_site
 * @param array $options
 *     - bool `rapatrier` (défaut true)
 *     - bool `sans_statistiques`
 * @return array
 */
function dashboard_operation_sauvegarder($id_dashboard_site, $options = []) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	// L'instant du départ, pour reconnaître ensuite ce que cette demande-ci a
	// produit — et non ce qui traînait déjà sur le site.
	$depart = time();

	$export   = dashboard_sauvegarde_exporter($site, $options);
	$reponse  = $export['reponse'];
	$tranches = (int) $export['tranches'];

	$adoptee = false;

	if (!$reponse['ok']) {
		$message = (string) ($reponse['erreur']['message'] ?? '');
		$code    = (string) ($reponse['erreur']['code'] ?? '');

		/* Un silence ne dit rien de l'issue : un cache en frontal rend son 503
		   bien avant que PHP ait fini l'export, et la sauvegarde existe le plus
		   souvent malgré tout. Plutôt que d'annoncer un échec — et de pousser à
		   refaire un travail déjà fait, deux fois plus long la seconde fois — on
		   va voir ce que le site a réellement sur son disque. */
		$constat    = dashboard_silence($code)
			? dashboard_sauvegarde_rattraper($id_dashboard_site, $depart)
			: [];
		$sauvegarde = $constat['sauvegarde'] ?? null;

		if (!$sauvegarde) {
			/* Dire qu'on est allé voir. Sans cela le message est celui d'avant le
			   rattrapage, et personne ne peut savoir en le lisant si le correctif
			   a cherché ou s'il n'est pas là. */
			if ($constat) {
				$message .= ' — ' . dashboard_sauvegarde_diagnostic($constat);
			}
			dashboard_journaliser($id_dashboard_site, 'sauvegarde', 'erreur', $message, $reponse['erreur'], $reponse['duree_ms']);

			return ['ok' => false, 'message' => $message, 'code' => $code, 'data' => []];
		}

		$adoptee = true;
		dashboard_journaliser(
			$id_dashboard_site,
			'sauvegarde',
			'ok',
			'Réponse perdue (' . $message . ') — sauvegarde retrouvée sur le site : ' . (string) $sauvegarde['identifiant'],
			$sauvegarde,
			$reponse['duree_ms']
		);
	} else {
		$sauvegarde = $reponse['data']['sauvegarde'] ?? [];
	}
	$id_sauvegarde = (int) sql_insertq('spip_dashboard_sauvegardes', [
		'id_dashboard_site' => (int) $id_dashboard_site,
		'identifiant'       => substr((string) ($sauvegarde['identifiant'] ?? ''), 0, 64),
		'fichier'           => '',
		'octets'            => (int) ($sauvegarde['octets'] ?? 0),
		'sha256'            => substr((string) ($sauvegarde['sha256'] ?? ''), 0, 64),
		'statut'            => 'distante',
		'date'              => date('Y-m-d H:i:s'),
	]);

	$message = ($adoptee
		? 'Sauvegarde retrouvée sur le site, la réponse s’étant perdue ('
		: 'Sauvegarde créée sur le site (')
		. dashboard_octets((int) ($sauvegarde['octets'] ?? 0))
		. ($tranches > 1 ? ', ' . $tranches . ' tranches' : '') . ')';

	if (!isset($options['rapatrier']) || $options['rapatrier']) {
		$rapatriement = dashboard_rapatrier_sauvegarde($id_dashboard_site, $id_sauvegarde);
		$message .= ' — ' . $rapatriement['message'];
		if (!$rapatriement['ok']) {
			dashboard_journaliser($id_dashboard_site, 'sauvegarde', 'erreur', $message, $sauvegarde, $reponse['duree_ms']);

			return ['ok' => false, 'message' => $message, 'data' => $sauvegarde];
		}
	}

	dashboard_journaliser($id_dashboard_site, 'sauvegarde', 'ok', $message, $sauvegarde, $reponse['duree_ms']);

	return ['ok' => true, 'message' => $message, 'data' => $sauvegarde, 'id_dashboard_sauvegarde' => $id_sauvegarde];
}

/**
 * Ce qu'une relecture de dépôt permet d'annoncer.
 *
 * L'agent, quand la relecture est forcée, efface la copie locale du catalogue
 * avant d'appeler SVP et regarde ensuite si une copie est revenue. Une copie
 * revenue prouve le téléchargement ; rien de revenu prouve le contraire, quoi
 * que SVP ait répondu.
 *
 * Ce constat n'existe qu'à partir de l'agent 1.0.22. Son absence ne vaut pas
 * mauvaise nouvelle : elle vaut « on ne sait pas », et on se tait alors, comme
 * avant.
 *
 * @param array $data Réponse de l'opération `depots_actualiser`
 * @return string Complément de message, vide quand il n'y a rien à dire
 */
function dashboard_depots_constat($data) {
	$relecture = (array) ($data['relecture'] ?? []);
	if (!$relecture || !array_key_exists('fiable', $relecture)) {
		return '';
	}
	if (!empty($relecture['fiable'])) {
		return '';
	}

	return (string) ($relecture['message'] ?? 'relecture non concluante');
}

/**
 * L'inventaire des sauvegardes présentes sur le site géré.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_operation_sauvegardes_lister($id_dashboard_site) {
	include_spip('inc/dashboard_client');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'code' => '', 'data' => []];
	}

	$reponse = dashboard_appeler($site, 'sauvegarde_lister');

	if (!$reponse['ok']) {
		return [
			'ok' => false,
			'message' => (string) ($reponse['erreur']['message'] ?? ''),
			'code' => (string) ($reponse['erreur']['code'] ?? ''),
			'data' => [],
		];
	}

	// Tout ce qui vient d'un site géré est inerte avant d'aller plus loin.
	return [
		'ok' => true,
		'message' => '',
		'code' => '',
		'data' => dashboard_inerte((array) ($reponse['data']['sauvegardes'] ?? [])),
		// Les exports restés en plan : sans valeur comme sauvegarde, précieux
		// comme indice. Les agents d'avant la 1.0.17 n'en rendent pas.
		'inacheves' => dashboard_inerte((array) ($reponse['data']['inacheves'] ?? [])),
	];
}

/**
 * Parmi les sauvegardes d'un site, celle qu'une requête coupée vient de créer.
 *
 * Quand la demande de sauvegarde se solde par un silence — un 503 de Varnish,
 * typiquement, dont la patience est plus courte que la nôtre — la sauvegarde a
 * le plus souvent été produite quand même : PHP a poursuivi son travail derrière
 * le cache qui avait déjà rendu la main. Reste à la reconnaître dans
 * l'inventaire du site.
 *
 * Deux critères, et le premier fait l'essentiel du travail :
 *
 * - **elle nous est inconnue.** Toute sauvegarde déjà inscrite chez nous est
 *   écartée, ce qui suffit à ne jamais adopter celle d'hier ;
 * - **elle est récente.** Garde-fou contre l'adoption d'une orpheline
 *   ancienne — un site où des sauvegardes traînent depuis avant l'appairage.
 *   La tolérance est large à dessein : la date vient de l'horloge du site géré,
 *   qui n'est pas la nôtre, et un décalage de quelques minutes entre deux
 *   hébergements n'a rien d'exceptionnel.
 *
 * @param array $sauvegardes Inventaire rendu par le site géré
 * @param int $depuis Horodatage à partir duquel une sauvegarde nous intéresse
 * @param array $connus Identifiants déjà inscrits chez nous
 * @param int $tolerance Écart d'horloge admis, en secondes
 * @return array|null
 */
function dashboard_sauvegarde_retrouvee($sauvegardes, $depuis, $connus = [], $tolerance = 3600) {
	$seuil = (int) $depuis - (int) $tolerance;
	$retenue = null;
	$date_retenue = 0;

	foreach ((array) $sauvegardes as $sauvegarde) {
		if (!is_array($sauvegarde)) {
			continue;
		}
		$identifiant = (string) ($sauvegarde['identifiant'] ?? '');
		if ($identifiant === '' || in_array($identifiant, (array) $connus, true)) {
			continue;
		}
		// Une sauvegarde vide ne vaut pas la peine d'être adoptée : ce serait
		// annoncer une protection qui n'en est pas une.
		if ((int) ($sauvegarde['octets'] ?? 0) <= 0) {
			continue;
		}
		$date = strtotime((string) ($sauvegarde['date'] ?? ''));
		if (!$date || $date < $seuil) {
			continue;
		}
		if ($date > $date_retenue) {
			$retenue = $sauvegarde;
			$date_retenue = $date;
		}
	}

	return $retenue;
}

/**
 * Marque de fin qu'écrit l'agent au bas de chaque sauvegarde.
 *
 * Sa présence prouve que l'export est allé à son terme. Son absence ne prouve
 * rien : les sauvegardes produites avant la version 1.0.16 de l'agent n'en
 * portent pas.
 */
if (!defined('_DASHBOARD_SAUVEGARDE_FIN')) {
	define('_DASHBOARD_SAUVEGARDE_FIN', '-- fin de sauvegarde');
}

/**
 * Une sauvegarde rapatriée est-elle intacte ?
 *
 * Le fichier existe, il pèse son poids, et rien de tout cela ne dit qu'il est
 * lisible. Un export interrompu — le processus tué par une limite de temps ou de
 * mémoire pendant qu'il écrivait — laisse une archive gzip amputée, dont la
 * présence rassure à tort. Restaurer une base à partir d'un fichier pareil se
 * découvre le jour où l'on en a besoin, c'est-à-dire le pire.
 *
 * Le contrôle relit l'archive membre par membre, comme le fait `gzip -t` :
 * chacun doit se terminer proprement — zlib vérifie lui-même son CRC32 et sa
 * taille décompressée —, et le fichier doit s'achever sur une frontière de
 * membre.
 *
 * **Un gzip n'a pas forcément un seul membre** (RFC 1952), et une sauvegarde
 * découpée en a un par tranche d'export. Le contrôle d'avant ne lisait que les
 * huit derniers octets du fichier, c'est-à-dire le pied du *dernier* membre : il
 * les comparait au flux décompressé tout entier, et déclarait tronquée une
 * archive parfaitement valide. D'où ce parcours, qui ne suppose plus rien du
 * nombre de membres et vaut pour l'archive d'un seul tenant comme pour l'autre.
 *
 * Deux verdicts distincts, et les confondre serait une faute :
 *
 * - `ok` à faux **prouve** que le fichier est abîmé ;
 * - `complet` à faux dit seulement que la marque de fin n'a pas été vue. Les
 *   sauvegardes d'avant la version 1.0.16 de l'agent n'en portent pas : leur
 *   refuser sa confiance reviendrait à jeter des sauvegardes valides.
 *
 * @param string $chemin
 * @return array{ok: bool, complet: bool, octets: int, membres: int, raison: string}
 */
function dashboard_sauvegarde_verifier($chemin) {
	$verdict = ['ok' => false, 'complet' => false, 'octets' => 0, 'membres' => 0, 'raison' => ''];

	if (!is_file($chemin) || !filesize($chemin)) {
		return ['raison' => 'fichier absent ou vide'] + $verdict;
	}
	if (!function_exists('inflate_init')) {
		return ['raison' => 'extension zlib absente : archive non vérifiable'] + $verdict;
	}

	$brut = @fopen($chemin, 'rb');
	if (!$brut) {
		return ['raison' => 'fichier illisible'] + $verdict;
	}
	clearstatcache(true, $chemin);
	$taille = (int) filesize($chemin);

	$depart  = 0;
	$octets  = 0;
	$membres = 0;
	$queue   = '';

	while ($depart < $taille) {
		if (fseek($brut, $depart) !== 0) {
			fclose($brut);

			return ['raison' => 'archive illisible à l’octet ' . $depart] + $verdict;
		}

		// Un membre commence par la signature de gzip. Le dire ici distingue
		// « des octets étrangers suivent l'archive » d'« archive corrompue ».
		if ((string) fread($brut, 2) !== "\x1f\x8b") {
			fclose($brut);

			return ['ok' => false, 'complet' => false, 'octets' => $octets, 'membres' => $membres,
				'raison' => 'des octets étrangers suivent l’archive, à partir de l’octet ' . $depart];
		}
		fseek($brut, $depart);

		$contexte = inflate_init(ZLIB_ENCODING_GZIP);
		$acheve   = false;

		while (!feof($brut)) {
			$bloc = fread($brut, 262144);
			if ($bloc === false || $bloc === '') {
				break;
			}
			$sortie = @inflate_add($contexte, $bloc);
			if ($sortie === false) {
				fclose($brut);

				return ['ok' => false, 'complet' => false, 'octets' => $octets, 'membres' => $membres,
					'raison' => 'archive corrompue : zlib refuse le flux'];
			}
			$octets += strlen($sortie);
			// De quoi retrouver la marque de fin sans garder tout le dump en mémoire.
			$queue = substr($queue . $sortie, -512);

			if (inflate_get_status($contexte) === ZLIB_STREAM_END) {
				$acheve = true;
				break;
			}
		}

		if (!$acheve) {
			fclose($brut);

			return ['ok' => false, 'complet' => false, 'octets' => $octets, 'membres' => $membres,
				'raison' => 'archive tronquée : le flux gzip ne se termine pas'];
		}

		$lu = (int) inflate_get_read_len($contexte);
		if ($lu <= 0) {
			fclose($brut);

			return ['ok' => false, 'complet' => false, 'octets' => $octets, 'membres' => $membres,
				'raison' => 'archive illisible : membre gzip de longueur nulle'];
		}
		$depart += $lu;
		$membres++;
	}

	fclose($brut);

	if (!$membres) {
		return ['raison' => 'archive vide'] + $verdict;
	}

	return [
		'ok'      => true,
		'complet' => strpos($queue, _DASHBOARD_SAUVEGARDE_FIN) !== false,
		'octets'  => $octets,
		'membres' => $membres,
		'raison'  => '',
	];
}

/**
 * Va voir sur le site si la sauvegarde demandée existe, malgré le silence.
 *
 * Interroge l'inventaire du site géré et retient la sauvegarde que cette
 * demande-ci vient de produire, s'il y en a une. Rien de trouvé — un site
 * vraiment tombé, une autorisation refusée, une sauvegarde qui a réellement
 * échoué — et l'appelant conclut à l'échec, comme avant.
 *
 * @param int $id_dashboard_site
 * @param int $depart Horodatage du début de la demande
 * @return array|null
 */
function dashboard_sauvegarde_rattraper($id_dashboard_site, $depart) {
	$inventaire = dashboard_operation_sauvegardes_lister($id_dashboard_site);
	if (empty($inventaire['ok'])) {
		return ['sauvegarde' => null, 'joignable' => false, 'sauvegardes' => 0, 'inacheves' => 0,
			'octets_inacheve' => 0, 'reprise' => false];
	}

	$connus = array_column(
		(array) sql_allfetsel('identifiant', 'spip_dashboard_sauvegardes', 'id_dashboard_site = ' . (int) $id_dashboard_site),
		'identifiant'
	);

	$inacheves = (array) ($inventaire['inacheves'] ?? []);

	return [
		'sauvegarde'      => dashboard_sauvegarde_retrouvee((array) $inventaire['data'], (int) $depart, $connus),
		'joignable'       => true,
		'sauvegardes'     => count((array) $inventaire['data']),
		'inacheves'       => count($inacheves),
		'octets_inacheve' => (int) ($inacheves[0]['octets'] ?? 0),
		// Un export découpé en cours n'est pas un export tué : il attend sa
		// tranche suivante. Les agents d'avant la 1.0.21 ne le disent pas.
		'reprise'         => !empty($inacheves[0]['reprise']),
	];
}

/**
 * Ce que dire quand on est allé voir, et qu'on n'a rien trouvé.
 *
 * Le rattrapage muet était pire que pas de rattrapage du tout : il renvoyait le
 * message d'origine, mot pour mot celui d'avant. Impossible, en le lisant, de
 * savoir si le correctif avait cherché sans trouver ou s'il n'était pas
 * installé — deux situations qui n'appellent pas les mêmes gestes.
 *
 * Le constat désigne en outre le bon coupable, ce que le 503 ne fait pas :
 *
 * - **un export inachevé sur le disque** prouve que PHP a commencé à écrire sans
 *   aller au bout. Le cache en frontal n'y est alors pour rien : c'est une
 *   limite du site géré, temps d'exécution ou mémoire ;
 * - **rien du tout**, et l'export n'a pas même démarré — autorisation, place
 *   disque, base illisible.
 *
 * @param array $constat Rendu par dashboard_sauvegarde_rattraper()
 * @return string
 */
function dashboard_sauvegarde_diagnostic($constat) {
	if (empty($constat['joignable'])) {
		return 'le site n’a pas répondu non plus quand on lui a demandé ses sauvegardes';
	}

	if (!empty($constat['inacheves'])) {
		if (!empty($constat['reprise'])) {
			/* Celui-là n'a pas été tué : il est en chantier, son point de reprise
			   à côté de lui. Accuser ici max_execution_time enverrait chercher
			   une panne là où il n'y en a pas. */
			return 'un export découpé de ' . dashboard_octets((int) $constat['octets_inacheve'])
				. ' est en cours sur le site : il reprendra là où il s’est arrêté'
				. ' à la prochaine demande de sauvegarde';
		}

		return 'un export inachevé de ' . dashboard_octets((int) $constat['octets_inacheve'])
			. ' traîne sur le site : PHP a été interrompu en cours d’écriture.'
			. ' Regarder max_execution_time et memory_limit du site géré, plutôt que le cache en frontal';
	}

	return 'le site répond mais n’annonce aucune sauvegarde nouvelle ('
		. (int) $constat['sauvegardes'] . ' au total) : l’export n’a pas abouti';
}

/**
 * Rapatrie le fichier d'une sauvegarde déjà créée sur le site géré.
 *
 * @param int $id_dashboard_site
 * @param int $id_dashboard_sauvegarde
 * @return array
 */
function dashboard_rapatrier_sauvegarde($id_dashboard_site, $id_dashboard_sauvegarde) {
	include_spip('inc/dashboard_client');

	$site = dashboard_charger_site($id_dashboard_site);
	$ligne = sql_fetsel('*', 'spip_dashboard_sauvegardes', 'id_dashboard_sauvegarde = ' . (int) $id_dashboard_sauvegarde);
	if (!$site || !$ligne) {
		return ['ok' => false, 'message' => 'Sauvegarde inconnue'];
	}

	$dir = dashboard_dir_sauvegardes($id_dashboard_site);
	if (!$dir) {
		return ['ok' => false, 'message' => 'Répertoire local de sauvegardes non inscriptible'];
	}

	$nom = 'site' . (int) $id_dashboard_site . '-' . $ligne['identifiant'] . '.sql.gz';
	$resultat = dashboard_telecharger_sauvegarde($site, (string) $ligne['identifiant'], $dir . $nom);

	if (!$resultat['ok']) {
		return ['ok' => false, 'message' => 'rapatriement échoué : ' . (string) ($resultat['erreur']['message'] ?? '')];
	}

	// L'empreinte annoncée par l'agent protège d'un transfert tronqué.
	if (!empty($ligne['sha256']) && !hash_equals((string) $ligne['sha256'], (string) $resultat['sha256'])) {
		@unlink($dir . $nom);

		return ['ok' => false, 'message' => 'rapatriement échoué : empreinte SHA-256 non conforme'];
	}

	/* Une sauvegarde retrouvée après une réponse perdue n'a pas d'empreinte :
	   l'inventaire du site donne un nom, une taille et une date, pas de SHA-256
	   — le calculer à chaque listage coûterait une lecture complète de chaque
	   fichier. Reste la taille, et elle suffit à repérer une troncature. C'est
	   d'autant moins théorique que ces sauvegardes-là sont précisément celles
	   qui passent par un frontal ayant déjà montré qu'il coupait. */
	if (empty($ligne['sha256']) && (int) $ligne['octets'] > 0 && (int) $resultat['octets'] !== (int) $ligne['octets']) {
		@unlink($dir . $nom);

		return ['ok' => false, 'message' => 'rapatriement échoué : '
			. dashboard_octets((int) $resultat['octets']) . ' reçus pour '
			. dashboard_octets((int) $ligne['octets']) . ' annoncés'];
	}

	/* Empreinte et taille disent que le transfert s'est bien passé ; elles ne
	   disent rien de ce qui a été transféré. Un export interrompu sur le site
	   géré produit une archive amputée qui voyage parfaitement. Relire le gzip
	   est le seul contrôle qui porte sur le contenu — et le seul moment pour le
	   faire est maintenant, pas le jour où il faudra restaurer. */
	$verdict = dashboard_sauvegarde_verifier($dir . $nom);
	if (!$verdict['ok']) {
		@unlink($dir . $nom);

		return ['ok' => false, 'message' => 'rapatriement échoué : ' . $verdict['raison']];
	}

	sql_updateq('spip_dashboard_sauvegardes', [
		'fichier' => $nom,
		'octets'  => (int) $resultat['octets'],
		'sha256'  => (string) $resultat['sha256'],
		'statut'  => 'locale',
	], 'id_dashboard_sauvegarde = ' . (int) $id_dashboard_sauvegarde);

	$message = 'rapatriée localement (' . dashboard_octets((int) $resultat['octets']) . ')';
	// La marque de fin n'existe qu'à partir de la version 1.0.16 de l'agent :
	// son absence ne se signale pas, elle ne prouverait rien.
	if ($verdict['complet']) {
		$message .= ', export complet vérifié';
	}

	return ['ok' => true, 'message' => $message];
}

/**
 * Fait relire au site géré le catalogue de ses dépôts de plugins.
 *
 * Un dépôt par appel : télécharger un catalogue XML de plusieurs méga-octets et
 * le réindexer suffit à occuper une requête.
 *
 * @param int $id_dashboard_site
 * @param int $age_max Ne rafraîchir qu'au-delà de cet âge, en secondes. Zéro force.
 * @return array
 */
function dashboard_operation_depots_actualiser($id_dashboard_site, $age_max = 0) {
	include_spip('inc/dashboard_client');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$reponse = dashboard_appeler(
		$site,
		'depots_actualiser',
		['age_max' => (int) $age_max],
		['timeout' => dashboard_config('timeout_long', 300)]
	);

	if (!$reponse['ok']) {
		return [
			'ok' => false,
			'message' => (string) ($reponse['erreur']['message'] ?? ''),
			'code' => (string) ($reponse['erreur']['code'] ?? ''),
			'data' => $reponse['data'],
		];
	}

	return ['ok' => true, 'message' => '', 'data' => $reponse['data']];
}

/**
 * Fait calculer à SVP, sur le site géré, ce qu'une mise à jour impliquerait.
 *
 * Rien n'est engagé : c'est l'occasion d'apprendre qu'une dépendance manque
 * **avant** de sauvegarder et de télécharger, plutôt qu'après avoir déployé un
 * plugin que SPIP refusera d'activer.
 *
 * @param int $id_dashboard_site
 * @param string $prefixe
 * @return array
 */
function dashboard_operation_plugin_svp_preflight($id_dashboard_site, $prefixe) {
	return dashboard_appeler_svp($id_dashboard_site, 'plugin_svp_preflight', $prefixe);
}

/**
 * Fige sur le site géré la file d'actions que SVP jouera.
 *
 * @param int $id_dashboard_site
 * @param string $prefixe
 * @param bool $forcer Passer outre un verrou SVP existant
 * @return array
 */
function dashboard_operation_plugin_svp_preparer($id_dashboard_site, $prefixe, $forcer = false) {
	return dashboard_appeler_svp($id_dashboard_site, 'plugin_svp_preparer', $prefixe, ['forcer' => (bool) $forcer]);
}

/**
 * Joue une action de la file SVP, et une seule.
 *
 * @param int $id_dashboard_site
 * @param string $prefixe
 * @return array
 */
function dashboard_operation_plugin_svp_avancer($id_dashboard_site, $prefixe) {
	return dashboard_appeler_svp($id_dashboard_site, 'plugin_svp_avancer', $prefixe);
}

/**
 * Libère une file SVP restée en plan sur le site géré.
 *
 * @param int $id_dashboard_site
 * @param string $prefixe
 * @return array
 */
function dashboard_operation_plugin_svp_liberer($id_dashboard_site, $prefixe = '') {
	return dashboard_appeler_svp($id_dashboard_site, 'plugin_svp_liberer', $prefixe);
}

/**
 * Le passage obligé des quatre opérations SVP : même chargement, même erreurs.
 *
 * @param int $id_dashboard_site
 * @param string $op
 * @param string $prefixe
 * @param array $args
 * @return array
 */
function dashboard_appeler_svp($id_dashboard_site, $op, $prefixe, $args = []) {
	include_spip('inc/dashboard_client');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$args['prefixe'] = strtoupper((string) $prefixe);
	$reponse = dashboard_appeler($site, $op, $args, ['timeout' => dashboard_config('timeout_long', 300)]);

	if (!$reponse['ok']) {
		return [
			'ok' => false,
			'message' => (string) ($reponse['erreur']['message'] ?? ''),
			'code' => (string) ($reponse['erreur']['code'] ?? ''),
			'data' => $reponse['data'],
		];
	}

	return ['ok' => true, 'message' => '', 'data' => $reponse['data']];
}

/**
 * Met à jour un plugin sur un site géré.
 *
 * @param int $id_dashboard_site
 * @param string $prefixe
 * @param array $options url_archive, sha256, strategie
 * @return array
 */
function dashboard_operation_plugin_maj($id_dashboard_site, $prefixe, $options = []) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');
	include_spip('inc/dashboard_sync');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$args = ['prefixe' => strtoupper((string) $prefixe)];
	foreach (['url_archive', 'sha256', 'strategie'] as $clef) {
		if (!empty($options[$clef])) {
			$args[$clef] = (string) $options[$clef];
		}
	}

	$reponse = dashboard_appeler($site, 'plugin_maj', $args, ['timeout' => dashboard_config('timeout_long', 300)]);

	if (!$reponse['ok']) {
		$message = $prefixe . ' : ' . (string) ($reponse['erreur']['message'] ?? '');
		dashboard_journaliser($id_dashboard_site, 'plugin_maj', 'erreur', $message, $reponse, $reponse['duree_ms']);

		// Le code accompagne le message : l'appelant doit pouvoir distinguer un
		// refus argumenté d'un silence, qui ne dit rien de l'issue.
		return [
			'ok' => false,
			'message' => $message,
			'code' => (string) ($reponse['erreur']['code'] ?? ''),
			'data' => $reponse['data'],
		];
	}

	$message = $prefixe . ' : ' . (string) ($reponse['data']['version_avant'] ?? '?')
		. ' → ' . (string) ($reponse['data']['version_apres'] ?? '?');

	// Le dossier change de nom avec la version : le dire évite de chercher
	// pourquoi l'ancien répertoire ne contient plus rien.
	$dossier = (string) ($reponse['data']['dossier'] ?? '');
	if ($dossier !== '' && $dossier !== (string) ($reponse['data']['dossier_avant'] ?? '')) {
		$message .= ' (dossier ' . $dossier . ')';
	}
	dashboard_journaliser($id_dashboard_site, 'plugin_maj', 'ok', $message, $reponse['data'], $reponse['duree_ms']);

	dashboard_synchroniser($id_dashboard_site);

	return ['ok' => true, 'message' => $message, 'data' => $reponse['data']];
}

/**
 * Met à jour tous les plugins d'un site pour lesquels une version est annoncée.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_operation_plugin_maj_tous($id_dashboard_site) {
	$prefixes = sql_allfetsel(
		'prefixe',
		'spip_dashboard_plugins',
		[
			'id_dashboard_site = ' . (int) $id_dashboard_site,
			'maj_disponible = ' . sql_quote('oui'),
			'distribue = ' . sql_quote('non'),
		],
		'',
		'prefixe'
	);

	$rapport = ['ok' => true, 'faits' => [], 'echecs' => []];
	foreach ($prefixes as $ligne) {
		$resultat = dashboard_operation_plugin_maj($id_dashboard_site, $ligne['prefixe']);
		if ($resultat['ok']) {
			$rapport['faits'][] = $resultat['message'];
		} else {
			$rapport['ok'] = false;
			$rapport['echecs'][] = $resultat['message'];
		}
	}

	$rapport['message'] = count($rapport['faits']) . ' mise(s) à jour effectuée(s)'
		. ($rapport['echecs'] ? ', ' . count($rapport['echecs']) . ' en échec' : '');

	return $rapport;
}

/**
 * Met à jour le core SPIP d'un site géré.
 *
 * Le remplacement des fichiers, et lui seul : la sauvegarde préalable et la
 * migration du schéma qui suit sont des étapes du chantier, de sorte qu'aucun
 * appelant ne puisse remplacer un noyau sans filet.
 *
 * @param int $id_dashboard_site
 * @param string $version Version cible, déduite si vide
 * @param array $options
 * @return array
 */
function dashboard_operation_core_maj($id_dashboard_site, $version = '', $options = []) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');
	include_spip('inc/dashboard_versions');
	include_spip('inc/dashboard_sync');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	// Le PHP du site compte ici aussi : l'opération peut être appelée sans version
	// imposée, et elle la redéduit alors elle-même.
	$version = trim((string) $version)
		?: dashboard_version_cible((string) $site['version_spip'], (string) $site['php_version']);
	if ($version === '') {
		return ['ok' => false, 'message' => 'Aucune version cible connue pour la branche installée', 'data' => []];
	}

	$url = dashboard_url_archive_spip($version);
	if ($url === '') {
		return ['ok' => false, 'message' => 'URL d’archive introuvable pour SPIP ' . $version, 'data' => []];
	}

	// L'empreinte vient de l'annuaire officiel, qui la publie à côté de
	// l'adresse. L'agent refuse l'archive qui n'y répond pas ; il le faisait
	// déjà, mais personne ne lui avait jamais donné d'empreinte à comparer.
	$sha256 = trim((string) ($options['sha256'] ?? '')) ?: dashboard_sha256_spip($version);

	$reponse = dashboard_appeler($site, 'core_maj', [
		'url_archive'      => $url,
		'version_attendue' => $version,
		'sha256'           => $sha256,
	], ['timeout' => dashboard_config('timeout_long', 300)]);

	if (!$reponse['ok']) {
		$message = (string) ($reponse['erreur']['message'] ?? '');
		dashboard_journaliser($id_dashboard_site, 'core_maj', 'erreur', $message, $reponse, $reponse['duree_ms']);

		return ['ok' => false, 'message' => $message, 'data' => $reponse['data']];
	}

	$version_apres = (string) ($reponse['data']['version_apres'] ?? $version);
	$message = 'SPIP ' . (string) ($reponse['data']['version_avant'] ?? '?') . ' → ' . $version_apres
		. ($sha256 ? ' (empreinte vérifiée)' : '');
	dashboard_journaliser($id_dashboard_site, 'core_maj', 'ok', $message, $reponse['data'], $reponse['duree_ms']);

	dashboard_synchroniser($id_dashboard_site);

	// La synchronisation qui suit immédiatement le remplacement interroge un
	// PHP qui a déjà chargé l'ancien inc_version.php : il annonce encore la
	// version d'avant, et la fiche continuerait de proposer une mise à jour
	// déjà faite. La version déployée, elle, est connue avec certitude —
	// l'agent l'a lue dans l'archive qu'il vient d'installer.
	if ($version_apres !== '') {
		sql_updateq('spip_dashboard_sites', [
			'version_spip' => $version_apres,
			'core_maj'     => dashboard_version_cible($version_apres, (string) $site['php_version']) ? 'oui' : 'non',
		], 'id_dashboard_site = ' . (int) $id_dashboard_site);
	}

	return ['ok' => true, 'message' => $message, 'data' => $reponse['data']];
}

/**
 * Contrôles préalables à une mise à jour du core, sans rien modifier.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_operation_core_preflight($id_dashboard_site) {
	include_spip('inc/dashboard_client');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$reponse = dashboard_appeler($site, 'core_maj_preflight');

	return [
		'ok'      => $reponse['ok'],
		'message' => $reponse['ok'] ? '' : (string) ($reponse['erreur']['message'] ?? ''),
		'data'    => $reponse['data'],
	];
}

/**
 * Joue une tranche de migration du schéma de base sur un site géré.
 *
 * L'agent rend la main dès qu'il a consommé son budget de temps ; `termine`
 * dit s'il reste du travail. L'appelant rappelle tant que ce n'est pas fini —
 * c'est ce que fait l'étape « base » d'un chantier.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_operation_base_maj($id_dashboard_site) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'termine' => true, 'data' => []];
	}

	// Le budget accordé à l'agent reste en deçà de notre propre temps d'attente :
	// mieux vaut une tranche rendue proprement qu'une requête coupée.
	$timeout = (int) dashboard_config('timeout_long', 300);
	$budget  = max(5, min(120, (int) ($timeout / 3)));

	$reponse = dashboard_appeler($site, 'base_maj', ['budget' => $budget], ['timeout' => $timeout]);

	if (!$reponse['ok']) {
		$message = (string) ($reponse['erreur']['message'] ?? '');
		dashboard_journaliser($id_dashboard_site, 'base_maj', 'erreur', $message, $reponse, $reponse['duree_ms']);

		return ['ok' => false, 'message' => $message, 'termine' => true, 'data' => $reponse['data']];
	}

	$data    = $reponse['data'];
	$termine = !empty($data['termine']);
	$message = $termine
		? 'Base migrée en version ' . (string) ($data['version_base'] ?? '?')
		: 'Migration en cours : version ' . (string) ($data['version_base'] ?? '?')
			. ' sur ' . (string) ($data['version_base_attendue'] ?? '?');

	// Une tranche qui n'aboutit pas n'est pas un événement : seul le terme
	// mérite une ligne au journal, sans quoi une grosse migration le noierait.
	if ($termine) {
		dashboard_journaliser($id_dashboard_site, 'base_maj', 'ok', $message, $data, $reponse['duree_ms']);
	}

	return ['ok' => true, 'message' => $message, 'termine' => $termine, 'data' => $data];
}

/**
 * Contrôles préalables à la migration du schéma, sans rien modifier.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_operation_base_preflight($id_dashboard_site) {
	include_spip('inc/dashboard_client');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$reponse = dashboard_appeler($site, 'base_maj_preflight');

	return [
		'ok'      => $reponse['ok'],
		'message' => $reponse['ok'] ? '' : (string) ($reponse['erreur']['message'] ?? ''),
		'data'    => $reponse['data'],
	];
}

/**
 * Remplace le `spip_loader.php` d'un site géré.
 *
 * Ce n'est pas un chantier : un seul aller-retour suffit, et il n'y a pas de
 * sauvegarde de base à prendre — c'est un fichier, que l'agent met lui-même de
 * côté avant d'écrire le nouveau. L'opération reste journalisée : remplacer le
 * script qui sait installer SPIP n'est pas un geste anodin.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_operation_loader_maj($id_dashboard_site) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$url = trim((string) dashboard_config('url_spip_loader', _DASHBOARD_LOADER_URL));
	$reponse = dashboard_appeler(
		$site,
		'loader_maj',
		['url' => $url],
		['timeout' => dashboard_config('timeout_long', 300)]
	);

	if (!$reponse['ok']) {
		$message = (string) ($reponse['erreur']['message'] ?? '');
		dashboard_journaliser($id_dashboard_site, 'loader_maj', 'erreur', $message, $reponse['erreur'], $reponse['duree_ms']);

		return ['ok' => false, 'message' => $message, 'data' => $reponse['data']];
	}

	$data = (array) $reponse['data'];
	$avant = (string) ($data['version_avant'] ?? '');
	$apres = (string) ($data['version_apres'] ?? '');
	$message = 'spip_loader.php déposé (' . dashboard_octets((int) ($data['octets'] ?? 0)) . ')'
		. ($apres !== '' ? ' — version ' . ($avant !== '' && $avant !== $apres ? $avant . ' → ' . $apres : $apres) : '');

	dashboard_journaliser($id_dashboard_site, 'loader_maj', 'ok', $message, $data, $reponse['duree_ms']);

	return ['ok' => true, 'message' => $message, 'data' => $data];
}

/**
 * Dépose SPIP Check à la racine d'un site géré.
 *
 * Comme le spip_loader : un aller-retour, pas de chantier, pas de sauvegarde de
 * base à prendre — c'est un fichier, que l'agent met lui-même de côté avant
 * d'écrire le nouveau. Et journalisé, parce que rendre appelable à la racine
 * web un outil qui lit tout le disque n'est pas un geste anodin.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_operation_check_maj($id_dashboard_site) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	// La même lecture que l'encadré, sans quoi le bouton et l'action pourraient
	// diverger : voir dashboard_url_check_source() pour le cas du champ vidé.
	include_spip('tourdecontrole_fonctions');
	$url = dashboard_url_check_source();
	if ($url === '') {
		return [
			'ok'      => false,
			'message' => 'Aucune adresse de téléchargement de SPIP Check n’est réglée',
			'data'    => [],
		];
	}

	$reponse = dashboard_appeler(
		$site,
		'check_maj',
		// L'épingle, quand il y en a une : c'est l'agent qui hache le fichier
		// qu'il a réellement reçu, nous ne faisons que dire ce qu'on attend.
		['url' => $url, 'sha256' => dashboard_empreinte_check()],
		['timeout' => dashboard_config('timeout_long', 300)]
	);

	if (!$reponse['ok']) {
		$message = (string) ($reponse['erreur']['message'] ?? '');
		dashboard_journaliser($id_dashboard_site, 'check_maj', 'erreur', $message, $reponse['erreur'], $reponse['duree_ms']);

		return ['ok' => false, 'message' => $message, 'data' => $reponse['data']];
	}

	$data = (array) $reponse['data'];
	$avant = (string) ($data['version_avant'] ?? '');
	$apres = (string) ($data['version_apres'] ?? '');
	$edition = (string) ($data['edition'] ?? '');
	$message = 'spip_check.php déposé (' . dashboard_octets((int) ($data['octets'] ?? 0)) . ')'
		. ($apres !== '' ? ' — version ' . ($avant !== '' && $avant !== $apres ? $avant . ' → ' . $apres : $apres) : '')
		. ($edition !== '' ? ' (' . $edition . ')' : '');

	dashboard_journaliser($id_dashboard_site, 'check_maj', 'ok', $message, $data, $reponse['duree_ms']);

	return ['ok' => true, 'message' => $message, 'data' => $data];
}

/**
 * Retire le `spip_loader.php` de la racine d'un site géré.
 *
 * C'est le fichier le plus dangereux d'un site SPIP : il installe ce qu'on lui
 * dit d'installer, et n'importe qui peut l'appeler. Il n'a de raison d'être que
 * le jour où l'on s'en sert. L'agent le supprime — il se retéléchargera d'un
 * clic le jour suivant.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_operation_loader_retirer($id_dashboard_site) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$reponse = dashboard_appeler($site, 'loader_retirer', [], ['timeout' => dashboard_config('timeout', 30)]);

	if (!$reponse['ok']) {
		$message = (string) ($reponse['erreur']['message'] ?? '');
		dashboard_journaliser($id_dashboard_site, 'loader_retirer', 'erreur', $message, $reponse['erreur'], $reponse['duree_ms']);

		return ['ok' => false, 'message' => $message, 'data' => $reponse['data']];
	}

	$data = (array) $reponse['data'];
	$message = ((string) ($data['retire'] ?? '') !== '')
		? 'spip_loader.php retiré de la racine du site'
		: 'Aucun spip_loader.php à retirer';

	dashboard_journaliser($id_dashboard_site, 'loader_retirer', 'ok', $message, $data, $reponse['duree_ms']);

	return ['ok' => true, 'message' => $message, 'data' => $data];
}

/**
 * Retire SPIP Check de la racine d'un site géré.
 *
 * L'outil s'emploie ponctuellement et se retire après usage — c'est ce que dit
 * sa propre documentation, et c'est ce qui le sépare du spip_loader, lequel a
 * vocation à rester. L'agent le renomme plutôt que de l'effacer : le fichier
 * n'est plus appelable, et le retour arrière reste possible.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_operation_check_retirer($id_dashboard_site) {
	include_spip('inc/dashboard_client');
	include_spip('inc/dashboard_journal');

	$site = dashboard_charger_site($id_dashboard_site);
	if (!$site) {
		return ['ok' => false, 'message' => 'Site inconnu', 'data' => []];
	}

	$reponse = dashboard_appeler($site, 'check_retirer', [], ['timeout' => dashboard_config('timeout', 30)]);

	if (!$reponse['ok']) {
		$message = (string) ($reponse['erreur']['message'] ?? '');
		dashboard_journaliser($id_dashboard_site, 'check_retirer', 'erreur', $message, $reponse['erreur'], $reponse['duree_ms']);

		return ['ok' => false, 'message' => $message, 'data' => $reponse['data']];
	}

	$data = (array) $reponse['data'];
	$message = ((string) ($data['retire'] ?? '') !== '')
		? 'spip_check.php retiré de la racine du site'
		: 'Aucun spip_check.php à retirer';

	dashboard_journaliser($id_dashboard_site, 'check_retirer', 'ok', $message, $data, $reponse['duree_ms']);

	return ['ok' => true, 'message' => $message, 'data' => $data];
}

/**
 * Formate une taille en octets.
 *
 * @param int $octets
 * @return string
 */
function dashboard_octets($octets) {
	$octets = (int) $octets;
	$unites = ['o', 'Ko', 'Mo', 'Go', 'To'];
	$rang = 0;
	while ($octets >= 1024 && $rang < count($unites) - 1) {
		$octets /= 1024;
		$rang++;
	}

	return ($rang ? round($octets, 1) : $octets) . ' ' . $unites[$rang];
}
