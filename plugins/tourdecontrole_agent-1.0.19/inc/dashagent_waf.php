<?php
/**
 * Lecture du tableau de bord du plugin SPIP WAF, sur un site géré.
 *
 * Le plugin WAF tient une page d'analyse dans l'espace privé du site
 * (`?exec=stats_waf`). La recopier telle quelle ici n'était pas envisageable :
 * ses sections se chargent en AJAX et s'appuient sur jQuery, donc un cadre isolé
 * — le seul endroit où l'on accepte du HTML venu d'ailleurs — les rendrait
 * vides ; et hors cadre isolé, ce serait ouvrir la tour de contrôle au balisage
 * d'un site qui peut être compromis.
 *
 * On lit donc ses **données**, et le tableau de bord les redessine avec son
 * propre balisage. Les chiffres sont recalculés ici plutôt qu'empruntés aux
 * fonctions du plugin : celles-ci emploient `DATE_SUB(NOW(), INTERVAL n DAY)`,
 * qui n'existe pas en SQLite, et leur signature suivrait ses versions.
 *
 * @package SPIP\Dashagent\Waf
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Nombre maximum d'événements rendus en une fois.
 */
if (!defined('_DASHAGENT_WAF_LOT_MAX')) {
	define('_DASHAGENT_WAF_LOT_MAX', 100);
}

/**
 * Types et motifs du plugin, repris de `waf_fonctions.php`.
 *
 * Les constantes du plugin sont préférées quand il est chargé ; ces valeurs
 * servent de repli, pour que la lecture reste possible même si le plugin a
 * changé la façon dont il les déclare.
 *
 * @param string $nom
 * @param string $defaut
 * @return string
 */
function dashagent_waf_constante($nom, $defaut) {
	return defined($nom) ? (string) constant($nom) : $defaut;
}

/**
 * Le plugin WAF est-il utilisable sur ce site ?
 *
 * @return array{ok: bool, erreur: string, version: string, raison: string}
 */
function dashagent_waf_disponible() {
	include_spip('inc/dashagent_infos');

	if (!dashagent_table_existe('spip_waf_events')) {
		return ['ok' => false, 'version' => '', 'raison' => 'waf_absent',
			'erreur' => 'Le plugin SPIP WAF n’est pas installé sur ce site'];
	}

	$actifs = unserialize($GLOBALS['meta']['plugin'] ?? '');
	$version = is_array($actifs) ? (string) ($actifs['WAF']['version'] ?? '') : '';

	return ['ok' => true, 'erreur' => '', 'raison' => '', 'version' => $version];
}

/**
 * Vide la file d'événements que le plugin tient en fichiers.
 *
 * Sa page d'analyse commence par là : sans cela, les derniers blocages ne sont
 * pas encore en base et les chiffres accusent un retard qu'on ne s'explique pas.
 * Le temps imparti est borné — c'est un site géré, pas notre serveur.
 *
 * @return int Nombre de tours effectués
 */
function dashagent_waf_vider_file() {
	if (!function_exists('waf_flush_events')) {
		$chemin = defined('_DIR_PLUGIN_WAF') ? _DIR_PLUGIN_WAF . 'inc/waf_dashboard.php' : '';
		if ($chemin && is_file($chemin)) {
			include_once $chemin;
		}
	}
	if (!function_exists('waf_flush_events')) {
		return 0;
	}

	$limite = time() + 5;
	$tours = 0;
	do {
		$reste = waf_flush_events(0);
		$tours++;
	} while ($reste < 0 && time() < $limite);

	return $tours;
}

/**
 * Chiffres d'ensemble : ce que les règles ont arrêté, ce que les listes ont
 * arrêté, et combien d'adresses distinctes au total.
 *
 * @return array
 */
function dashagent_waf_vue_ensemble() {
	$bloque = sql_quote(dashagent_waf_constante('WAF_EVT_BLOCKED', 'BLOCKED'));
	$liste  = sql_quote(dashagent_waf_constante('WAF_REASON_BLOCKLISTED_IP', 'BLOCKLISTED_IP'));

	$ligne = sql_fetsel(
		'COUNT(*) AS total'
		. ', SUM(CASE WHEN reason = ' . $liste . ' THEN 0 ELSE 1 END) AS regles_requetes'
		. ', COUNT(DISTINCT CASE WHEN reason = ' . $liste . ' THEN NULL ELSE ip END) AS regles_ips'
		. ', SUM(CASE WHEN reason = ' . $liste . ' THEN 1 ELSE 0 END) AS listes_requetes'
		. ', COUNT(DISTINCT CASE WHEN reason = ' . $liste . ' THEN ip ELSE NULL END) AS listes_ips'
		. ', COUNT(DISTINCT ip) AS ips',
		'spip_waf_events',
		'type = ' . $bloque,
		'',
		'',
		'',
		'',
		'',
		'continue'
	);

	return [
		'requetes_bloquees' => (int) ($ligne['total'] ?? 0),
		'regles_requetes'   => (int) ($ligne['regles_requetes'] ?? 0),
		'regles_ips'        => (int) ($ligne['regles_ips'] ?? 0),
		'listes_requetes'   => (int) ($ligne['listes_requetes'] ?? 0),
		'listes_ips'        => (int) ($ligne['listes_ips'] ?? 0),
		'ips_bloquees'      => (int) ($ligne['ips'] ?? 0),
	];
}

/**
 * Les bannissements les plus récents.
 *
 * @param int $limite
 * @return array
 */
function dashagent_waf_bans($limite = 20) {
	$limite = max(1, min(100, (int) $limite));
	$lignes = sql_allfetsel(
		['ip', 'date_event', 'reason'],
		'spip_waf_events',
		'type = ' . sql_quote(dashagent_waf_constante('WAF_EVT_BAN', 'BAN')),
		'',
		'date_event DESC',
		'0,' . $limite,
		'',
		'',
		'continue'
	);

	$bans = [];
	foreach ((array) $lignes as $ligne) {
		$bans[] = [
			'ip'     => (string) $ligne['ip'],
			'date'   => (string) $ligne['date_event'],
			'motif'  => (string) $ligne['reason'],
		];
	}

	return $bans;
}

/**
 * L'activité du WAF jour par jour, pour en lire la tendance.
 *
 * Les chiffres instantanés disent l'état ; ils ne disent pas si la pression
 * monte. Deux cents requêtes bloquées ne veulent pas la même chose selon
 * qu'elles sont l'ordinaire du site ou le décuple de la semaine passée.
 *
 * Trois précautions, et les deux premières tiennent à la portabilité — l'agent
 * tourne sur MySQL comme sur SQLite :
 *
 * - **le groupement se fait sur `substr(date_event, 1, 10)`**, et non sur
 *   `DATE()`, qui ne se comporte pas partout pareil. Le format d'une date SQL
 *   étant « AAAA-MM-JJ … », ses dix premiers caractères sont le jour ;
 * - **la borne est calculée en PHP**, jamais par `DATE_SUB()` — c'est déjà la
 *   règle du reste de ce fichier ;
 * - **les jours sans événement sont rendus à zéro.** Une série trouée se lit de
 *   travers : une courbe qui saute du 3 au 9 laisse croire à une continuité
 *   entre les deux, là où il ne s'est rien passé pendant cinq jours.
 *
 * Le WAF purge ses événements au bout de quatre-vingt-dix jours, les bans mis à
 * part : demander au-delà ne rendrait que des zéros, d'où le plafond.
 *
 * @param int $jours
 * @return array
 */
function dashagent_waf_serie($jours = 30) {
	$jours  = max(1, min(90, (int) $jours));
	$bloque = sql_quote(dashagent_waf_constante('WAF_EVT_BLOCKED', 'BLOCKED'));
	$ban    = sql_quote(dashagent_waf_constante('WAF_EVT_BAN', 'BAN'));

	/* Le premier jour de la fenêtre, à minuit : une borne posée à l'heure
	   courante amputerait le plus ancien jour d'une fraction, et sa colonne
	   serait plus basse que la réalité sans que rien ne le signale. */
	$premier = date('Y-m-d', time() - ($jours - 1) * 86400);

	$lignes = sql_allfetsel(
		'substr(date_event, 1, 10) AS jour'
		. ', SUM(CASE WHEN type = ' . $bloque . ' THEN 1 ELSE 0 END) AS requetes'
		. ', COUNT(DISTINCT CASE WHEN type = ' . $bloque . ' THEN ip ELSE NULL END) AS ips'
		. ', SUM(CASE WHEN type = ' . $ban . ' THEN 1 ELSE 0 END) AS bans',
		'spip_waf_events',
		[
			'type IN (' . $bloque . ', ' . $ban . ')',
			'date_event >= ' . sql_quote($premier . ' 00:00:00'),
		],
		'jour',
		'jour',
		'',
		'',
		'',
		'continue'
	);

	$par_jour = [];
	foreach ((array) $lignes as $ligne) {
		$par_jour[(string) $ligne['jour']] = [
			'requetes' => (int) $ligne['requetes'],
			'ips'      => (int) $ligne['ips'],
			'bans'     => (int) $ligne['bans'],
		];
	}

	$points = [];
	for ($i = 0; $i < $jours; $i++) {
		$jour = date('Y-m-d', strtotime($premier . ' +' . $i . ' day'));
		$points[] = ['jour' => $jour] + ($par_jour[$jour] ?? ['requetes' => 0, 'ips' => 0, 'bans' => 0]);
	}

	return ['jours' => $jours, 'depuis' => $premier, 'points' => $points];
}

/**
 * Ce qui a été bloqué ces derniers jours, par jour et par motif.
 *
 * La borne de date est calculée en PHP : `DATE_SUB(NOW(), INTERVAL n DAY)`,
 * qu'emploie le plugin, n'existe pas en SQLite.
 *
 * @param int $jours
 * @return array
 */
function dashagent_waf_attaques($jours = 7) {
	$jours = max(1, min(90, (int) $jours));
	$depuis = date('Y-m-d H:i:s', time() - $jours * 86400);

	$lignes = sql_allfetsel(
		['reason', 'COUNT(*) AS requetes', 'COUNT(DISTINCT ip) AS ips'],
		'spip_waf_events',
		[
			'type = ' . sql_quote(dashagent_waf_constante('WAF_EVT_BLOCKED', 'BLOCKED')),
			'date_event >= ' . sql_quote($depuis),
		],
		'reason',
		'requetes DESC',
		'0,30',
		'',
		'',
		'continue'
	);

	$motifs = [];
	foreach ((array) $lignes as $ligne) {
		$motifs[] = [
			'motif'    => (string) $ligne['reason'],
			'requetes' => (int) $ligne['requetes'],
			'ips'      => (int) $ligne['ips'],
		];
	}

	return ['jours' => $jours, 'depuis' => $depuis, 'motifs' => $motifs];
}

/**
 * Une page du journal des événements.
 *
 * @param array $args
 * @return array
 */
function dashagent_waf_evenements($args = []) {
	$debut = max(0, (int) ($args['debut'] ?? 0));
	$lot   = (int) ($args['lot'] ?? 25);
	$lot   = max(1, min(_DASHAGENT_WAF_LOT_MAX, $lot));

	$ou = [];
	$type = (string) ($args['type'] ?? '');
	if ($type !== '' && preg_match('/^[A-Z_]{1,32}$/', $type)) {
		$ou[] = 'type = ' . sql_quote($type);
	}
	$ip = (string) ($args['ip'] ?? '');
	if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
		$ou[] = 'ip = ' . sql_quote($ip);
	}

	$total = sql_countsel('spip_waf_events', $ou, '', '', '', 'continue');
	$lignes = sql_allfetsel(
		['id_waf_event', 'date_event', 'ip', 'type', 'reason', 'method', 'uri', 'ua'],
		'spip_waf_events',
		$ou,
		'',
		'date_event DESC',
		$debut . ',' . $lot,
		'',
		'',
		'continue'
	);

	$evenements = [];
	foreach ((array) $lignes as $ligne) {
		$evenements[] = [
			'date'   => (string) $ligne['date_event'],
			'ip'     => (string) $ligne['ip'],
			'type'   => (string) $ligne['type'],
			'motif'  => (string) $ligne['reason'],
			'methode' => (string) $ligne['method'],
			// Une URI d'attaque porte volontiers plusieurs kilo-octets de charge
			// utile : on en garde de quoi reconnaître, pas de quoi rejouer.
			'uri'    => dashagent_waf_abreger((string) $ligne['uri'], 300),
			'agent'  => dashagent_waf_abreger((string) $ligne['ua'], 200),
		];
	}

	return ['debut' => $debut, 'lot' => $lot, 'total' => ($total === false ? null : (int) $total),
		'evenements' => $evenements];
}

/**
 * Abrège une valeur en disant qu'elle l'a été.
 *
 * @param string $valeur
 * @param int $max
 * @return string
 */
function dashagent_waf_abreger($valeur, $max) {
	$valeur = (string) $valeur;

	return (strlen($valeur) > $max) ? substr($valeur, 0, $max) . '… (' . strlen($valeur) . ' caractères)' : $valeur;
}

/**
 * Les réglages du plugin qu'il est utile de voir à distance.
 *
 * Aucun ne porte de secret — le WAF n'en a pas —, mais le masquage habituel
 * s'applique quand même : un réglage ajouté demain pourrait en porter un.
 *
 * @return array
 */
function dashagent_waf_reglages() {
	include_spip('inc/config');
	include_spip('inc/dashagent_serveur');

	$clefs = ['WAF_MODE', 'WAF_STRIKE_THRESHOLD', 'WAF_BAN_DURATION', 'WAF_ENABLED_BLOCKLISTS',
		'WAF_CLIENT_IP_HEADER', 'WAF_TRUSTED_PROXIES', 'WAF_BYPASS_LOGGED_IN', 'WAF_LOGIN_STRIKE'];

	$reglages = [];
	foreach ($clefs as $clef) {
		$valeur = lire_config('waf/' . $clef, null);
		if ($valeur === null || $valeur === '') {
			continue;
		}
		$reglages[$clef] = dashagent_nom_sensible($clef)
			? dashagent_masquer($valeur)
			: dashagent_waf_abreger(is_scalar($valeur) ? (string) $valeur : json_encode($valeur), 200);
	}

	return $reglages;
}

/**
 * L'opération : tout ce que la page d'analyse du plugin montre, en données.
 *
 * @param array $args
 *     - int `debut`, `lot` : page du journal des événements
 *     - string `type`, `ip` : filtres du journal
 *     - int `jours` : profondeur du résumé des attaques
 * @return array
 */
function dashagent_waf_resume($args = []) {
	$waf = dashagent_waf_disponible();
	if (!$waf['ok']) {
		return ['ok' => false, 'erreur' => $waf['erreur'], 'raison' => $waf['raison'], 'present' => false];
	}

	@set_time_limit(60);
	$tours = dashagent_waf_vider_file();

	return [
		'ok'           => true,
		'erreur'       => '',
		'present'      => true,
		'version'      => $waf['version'],
		'file_videe'   => $tours,
		'vue_ensemble' => dashagent_waf_vue_ensemble(),
		'bans'         => dashagent_waf_bans((int) ($args['bans'] ?? 20)),
		'attaques'     => dashagent_waf_attaques((int) ($args['jours'] ?? 7)),
		'journal'      => dashagent_waf_evenements($args),
		'reglages'     => dashagent_waf_reglages(),
	];
}
