<?php
/**
 * L'annuaire officiel des versions de SPIP, lu à la source.
 *
 * SPIP publie sur `spip.net/spip_loader.api` la liste des versions existantes.
 * C'est cette adresse, et pas une autre, que le core lui-même interroge
 * (`ecrire/genie/mise_a_jour.php`, constante `_VERSIONS_SERVEUR`) : la version
 * qu'un site géré annonce à son webmestre par courriel vient de là.
 *
 * Trois formats cohabitent derrière trois adresses, et **ils ne disent pas la
 * même chose** :
 *
 * - `/1` : la liste nue, `version => chemin relatif` ;
 * - l'adresse sans suffixe rend le format 2 : la même liste, plus la branche
 *   par défaut, le PHP minimal par branche et des empreintes SHA-1 ;
 * - `/3` : une entrée par version, avec l'**adresse absolue** de l'archive, son
 *   **SHA-256**, la liste des PHP supportés, la mémoire et l'espace disque
 *   attendus, et des correctifs incrémentaux.
 *
 * Le piège est que le format 3 n'est pas un surensemble du 2 : au jour où ceci
 * est écrit, le 2 publie une branche 4.2 que le 3 ignore. Lire le seul format 3
 * rendrait donc la tour aveugle sur les sites d'une branche ancienne — ceux,
 * précisément, dont on veut surveiller la fin de vie. D'où deux lectures et une
 * fusion, le 3 faisant autorité sur ce qu'il liste.
 *
 * @package SPIP\Dashboard\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/** Durée de mise en cache de l'annuaire des versions. */
if (!defined('_DASHBOARD_CACHE_API')) {
	define('_DASHBOARD_CACHE_API', 3 * 3600);
}

/**
 * Racine sous laquelle les formats 1 et 2 expriment leurs chemins.
 *
 * Eux ne publient qu'un chemin relatif — `spip/archives/spip-v4.4.25.zip` — là
 * où le format 3 donne une adresse entière. Il faut donc une racine, et elle
 * n'est écrite nulle part dans la charge.
 */
if (!defined('_DASHBOARD_FICHIERS_SPIP')) {
	define('_DASHBOARD_FICHIERS_SPIP', 'https://files.spip.net/');
}

/** Adresse de l'annuaire, sans son suffixe de format. */
if (!defined('_DASHBOARD_VERSIONS_API')) {
	define('_DASHBOARD_VERSIONS_API', 'https://www.spip.net/spip_loader.api');
}

/**
 * Normalise une charge de `spip_loader.api`, quel qu'en soit le format.
 *
 * Rendre un tableau vide est un verdict à part entière : « je ne sais pas ».
 * Il ne doit jamais se confondre avec « aucune version disponible » — c'est la
 * même règle que le silence d'un agent, et elle est appliquée jusqu'au bout par
 * dashboard_core_verdict().
 *
 * @param string $charge Le corps de la réponse, tel quel
 * @param string $racine Racine des chemins relatifs des formats 1 et 2
 * @return array{api: int, versions: array, branche_defaut: string}
 */
function dashboard_api_analyser($charge, $racine = _DASHBOARD_FICHIERS_SPIP) {
	$vide = ['api' => 0, 'versions' => [], 'branche_defaut' => ''];

	$json = json_decode((string) $charge, true);
	if (!is_array($json) || !is_array($json['versions'] ?? null)) {
		return $vide;
	}

	$api = (int) ($json['api'] ?? 0);
	$out = [
		'api'            => $api,
		'versions'       => [],
		'branche_defaut' => dashboard_api_branche((string) ($json['default_branch'] ?? '')),
	];

	// Le PHP minimal du format 2 est rangé par branche, non par version : on le
	// garde de côté pour le recoller ensuite à chaque version de cette branche.
	$php_branche = [];
	foreach ((array) ($json['requirements']['php'] ?? []) as $branche => $mini) {
		if (preg_match('/^\d+\.\d+$/', (string) $branche) && preg_match('/^\d+\.\d+/', (string) $mini)) {
			$php_branche[$branche] = (string) $mini;
		}
	}

	foreach ($json['versions'] as $version => $entree) {
		$version = (string) $version;
		// « dev » et « master » désignent une branche de développement : elles
		// n'ont pas de numéro, et on ne propose pas un tronc à un site en
		// production.
		if (!preg_match('/^\d+\.\d+\.\d+/', $version)) {
			continue;
		}

		$fiche = [
			'version'   => $version,
			'url'       => '',
			'sha256'    => '',
			'sha1'      => '',
			'php'       => [],
			'php_mini'  => '',
			'ram'       => 0,
			'espace'    => 0,
		];

		if (is_array($entree)) {
			// Format 3 : l'adresse est déjà entière.
			$fiche['url']    = dashboard_api_url((string) ($entree['url'] ?? ''), $racine);
			$fiche['sha256'] = dashboard_api_empreinte((string) ($entree['sha256'] ?? ''), 64);
			$fiche['sha1']   = dashboard_api_empreinte((string) ($entree['sha1'] ?? ''), 40);
			$fiche['ram']    = max(0, (int) ($entree['ram'] ?? 0));
			$fiche['espace'] = max(0, (int) ($entree['freespace'] ?? 0));
			foreach ((array) ($entree['php'] ?? []) as $php) {
				if (preg_match('/^\d+\.\d+$/', (string) $php)) {
					$fiche['php'][] = (string) $php;
				}
			}
		} else {
			// Formats 1 et 2 : un chemin relatif à la racine des fichiers.
			$fiche['url'] = dashboard_api_url((string) $entree, $racine);
		}

		// Les empreintes du format 2 vivent dans un bloc à part.
		if (!$fiche['sha1']) {
			$fiche['sha1'] = dashboard_api_empreinte((string) ($json['digests'][$version]['sha1'] ?? ''), 40);
		}
		if (!$fiche['sha256']) {
			$fiche['sha256'] = dashboard_api_empreinte((string) ($json['digests'][$version]['sha256'] ?? ''), 64);
		}

		$branche = dashboard_api_branche($version);
		if (!$fiche['php'] && isset($php_branche[$branche])) {
			$fiche['php_mini'] = $php_branche[$branche];
		}

		$out['versions'][$version] = $fiche;
	}

	// Une charge dont aucune version n'est exploitable ne vaut pas mieux qu'une
	// charge illisible : on ne veut pas qu'elle chasse du cache un annuaire qui,
	// lui, disait quelque chose.
	return $out['versions'] ? $out : $vide;
}

/**
 * Une adresse d'archive, absolue, et seulement si elle est acceptable.
 *
 * @param string $brute Adresse entière (format 3) ou chemin relatif (1 et 2)
 * @param string $racine
 * @return string Chaîne vide si rien d'utilisable
 */
function dashboard_api_url($brute, $racine) {
	include_spip('inc/dashboard_client');

	$brute = trim((string) $brute);
	if ($brute === '') {
		return '';
	}
	if (!preg_match('#^https?://#i', $brute)) {
		$brute = rtrim((string) $racine, '/') . '/' . ltrim($brute, '/');
	}

	return dashboard_url_acceptable($brute) ? $brute : '';
}

/**
 * Une empreinte hexadécimale de la longueur attendue, en minuscules.
 *
 * @param string $valeur
 * @param int $longueur
 * @return string
 */
function dashboard_api_empreinte($valeur, $longueur) {
	$valeur = strtolower(trim((string) $valeur));

	return preg_match('/^[0-9a-f]{' . (int) $longueur . '}$/', $valeur) ? $valeur : '';
}

/**
 * Cette version est-elle une version stable ?
 *
 * Le critère est celui du core (`info_maj_versions()`) : le troisième nombre est
 * **numérique**. `4.4.25` l'est, `4.5.0-rc1` et `4.5.0-beta` ne le sont pas.
 *
 * Le core s'en sert pour ne jamais annoncer un saut de branche vers une version
 * instable. On va plus loin, et on refuse aussi de la proposer **dans la branche
 * installée** : remplacer le noyau d'un site de production par une candidate à la
 * publication n'est pas une décision qui se prend depuis un tableau de bord, et
 * encore moins sur tout un parc à la fois. Un webmestre qui la veut la nomme dans
 * les versions imposées.
 *
 * @param string $version
 * @return bool
 */
function dashboard_api_stable($version) {
	// Sans borne au découpage, comme le core : une éventuelle `4.4.25.1` a bien
	// « 25 » pour troisième nombre, là où une borne à trois en ferait « 25.1 » et
	// la déclarerait instable. Le cas ne s'est jamais produit chez SPIP, mais
	// diverger du core sur un critère qu'on lui emprunte se paierait un jour.
	$morceaux = explode('.', trim((string) $version));

	return count($morceaux) >= 3 && ctype_digit($morceaux[2]);
}

/**
 * La branche d'une version : « 4.4.25 » donne « 4.4 ».
 *
 * @param string $version
 * @return string
 */
function dashboard_api_branche($version) {
	return preg_match('/^(\d+\.\d+)(?:\.|$)/', trim((string) $version), $m) ? $m[1] : '';
}

/**
 * Fusionne deux annuaires : le premier fait autorité, le second complète.
 *
 * Le format 3 est plus riche (SHA-256, PHP supportés, adresse absolue), mais il
 * ne liste pas toutes les branches. Là où il se tait, l'annuaire du format 2
 * parle — et là où les deux parlent, c'est le premier qui est retenu, entier :
 * panacher les champs de deux fiches donnerait une fiche que personne n'a
 * publiée.
 *
 * @param array $principal
 * @param array $complement
 * @return array
 */
function dashboard_api_fusionner($principal, $complement) {
	$principal  = is_array($principal) ? $principal : [];
	$complement = is_array($complement) ? $complement : [];

	$versions = is_array($principal['versions'] ?? null) ? $principal['versions'] : [];
	foreach ((array) ($complement['versions'] ?? []) as $version => $fiche) {
		if (!isset($versions[$version])) {
			$versions[$version] = $fiche;
		}
	}

	return [
		'api'            => (int) ($principal['api'] ?? 0) ?: (int) ($complement['api'] ?? 0),
		'versions'       => $versions,
		'branche_defaut' => (string) ($principal['branche_defaut'] ?? '')
			?: (string) ($complement['branche_defaut'] ?? ''),
	];
}

/**
 * Le PHP d'un site permet-il d'installer cette version ?
 *
 * Deux façons de l'exprimer, selon le format : une **liste** des PHP supportés
 * (format 3), qui borne aussi par le haut — 4.3.9 ne prend pas PHP 8.5 —, ou un
 * **minimum** par branche (format 2), qui ne borne que par le bas.
 *
 * Sans contrainte publiée, ou sans PHP connu pour le site, on répond oui : ne
 * rien savoir ne se transforme jamais en refus. C'est la règle déjà tenue par
 * dashboard_catalogue_compatible() pour les branches d'un plugin.
 *
 * @param string $php_site Version de PHP du site géré (« 8.2.18 »)
 * @param array $fiche Entrée d'annuaire
 * @return bool
 */
function dashboard_api_php_compatible($php_site, $fiche) {
	$php_site = trim((string) $php_site);
	if (!preg_match('/^(\d+\.\d+)/', $php_site, $m)) {
		return true;
	}
	$branche_php = $m[1];

	$liste = (array) ($fiche['php'] ?? []);
	if ($liste) {
		return in_array($branche_php, $liste, true);
	}

	$mini = (string) ($fiche['php_mini'] ?? '');
	if ($mini !== '') {
		return version_compare($php_site, $mini, '>=');
	}

	return true;
}

/**
 * Ce que la tour peut dire de l'état du core d'un site.
 *
 * Quatre états, et le troisième est celui qui manquait :
 *
 * - `inconnu` : l'annuaire est vide. Ni à jour, ni en retard — on n'a pas pu
 *   savoir. Le confondre avec « à jour » est exactement le défaut qui a fait
 *   passer un parc entier pour sain pendant que son unique source répondait 500 ;
 * - `retard` : une version plus récente existe dans la branche, et le PHP du
 *   site la supporte. C'est le seul état qui arme un bouton ;
 * - `bloque` : elle existe mais le PHP du site ne la supporte pas. Dire « à
 *   jour » serait faux, proposer une mise à jour serait la promettre en vain ;
 * - `a_jour` : rien de plus récent dans la branche.
 *
 * Le saut de branche est rendu à part, pour information seulement : passer un
 * site de 4.3 à 4.4 casserait ses plugins sans prévenir, et ne se déclenche pas
 * depuis un tableau de bord.
 *
 * @param string $version_site
 * @param string $php_site
 * @param array $versions Annuaire normalisé, indexé par version
 * @return array{etat: string, cible: string, majeure: string, php_requis: string}
 */
function dashboard_core_verdict($version_site, $php_site, $versions) {
	include_spip('inc/plugin');

	$verdict = ['etat' => 'inconnu', 'cible' => '', 'majeure' => '', 'php_requis' => ''];

	$version_site = trim((string) $version_site);
	$versions     = is_array($versions) ? $versions : [];
	if (!$versions || !preg_match('/^\d+\.\d+\.\d+/', $version_site)) {
		return $verdict;
	}

	$branche  = dashboard_api_branche($version_site);
	$mineure  = '';
	$bloquee  = '';
	$majeure  = '';

	foreach ($versions as $version => $fiche) {
		$version = (string) $version;
		if (!spip_version_compare($version, $version_site, '>') || !dashboard_api_stable($version)) {
			continue;
		}
		$branche_v = dashboard_api_branche($version);

		if ($branche_v === $branche) {
			if (dashboard_api_php_compatible($php_site, $fiche)) {
				if (!$mineure || spip_version_compare($version, $mineure, '>')) {
					$mineure = $version;
				}
			} elseif (!$bloquee || spip_version_compare($version, $bloquee, '>')) {
				$bloquee = $version;
				$verdict['php_requis'] = dashboard_api_php_libelle($fiche);
			}
		} elseif (spip_version_compare($branche_v, $branche, '>')) {
			if (!$majeure || spip_version_compare($version, $majeure, '>')) {
				$majeure = $version;
			}
		}
	}

	$verdict['majeure'] = $majeure;

	if ($mineure) {
		$verdict['etat']  = 'retard';
		$verdict['cible'] = $mineure;
	} elseif ($bloquee) {
		$verdict['etat']  = 'bloque';
		$verdict['cible'] = $bloquee;
	} else {
		$verdict['etat'] = 'a_jour';
	}

	return $verdict;
}

/**
 * Ce qu'une version attend de PHP, en une ligne lisible.
 *
 * @param array $fiche
 * @return string
 */
function dashboard_api_php_libelle($fiche) {
	$liste = (array) ($fiche['php'] ?? []);
	if ($liste) {
		return implode(', ', $liste);
	}

	$mini = (string) ($fiche['php_mini'] ?? '');

	return $mini !== '' ? $mini . '+' : '';
}

/**
 * L'avis du site géré sur sa propre mise à jour.
 *
 * L'agent remonte ce que le génie `mise_a_jour` du core a écrit dans les metas
 * du site : c'est la source qui prévient le webmestre par courriel, et elle a
 * un avantage qu'aucune autre n'a — elle ne coûte aucun appel réseau à la tour,
 * et elle parle encore quand la tour, elle, est enfermée sans sortie.
 *
 * Son défaut est symétrique : le génie du core ne passe que **toutes les
 * soixante-douze heures**. L'avis du site peut donc avoir trois jours, et ne
 * l'emporte jamais sur celui de la tour.
 *
 * @param array $infos Inventaire rendu par l'agent
 * @return string Version disponible selon le site, ou chaîne vide
 */
function dashboard_core_avis_site($infos) {
	$infos = is_array($infos) ? $infos : [];
	$dit   = (string) ($infos['spip']['maj_disponible'] ?? '');

	return preg_match('/^\d+\.\d+\.\d+[a-z0-9.\-]*$/i', $dit) ? $dit : '';
}

/**
 * Confronte l'avis de la tour et celui du site géré.
 *
 * L'ordre est celui du catalogue des plugins, pour la même raison : quand la
 * tour sait, elle tranche, parce qu'elle est la seule à être à l'heure pour
 * tout le parc d'un coup. Quand elle ne sait pas, l'avis du site vaut mieux que
 * rien — répondre « je ne sais pas » alors que le site, lui, sait, serait une
 * perte sèche — et il porte alors sa provenance, faute de quoi le webmestre
 * croirait la tour renseignée.
 *
 * @param array $verdict Sortie de dashboard_core_verdict()
 * @param string $avis_site Sortie de dashboard_core_avis_site()
 * @param string $version_site
 * @return array{etat: string, cible: string, majeure: string, php_requis: string, provenance: string}
 */
function dashboard_core_confronter($verdict, $avis_site, $version_site) {
	include_spip('inc/plugin');

	$verdict = is_array($verdict) ? $verdict : [];
	$verdict += ['etat' => 'inconnu', 'cible' => '', 'majeure' => '', 'php_requis' => ''];
	$verdict['provenance'] = '';

	if ((string) $verdict['etat'] !== 'inconnu') {
		$verdict['provenance'] = 'tour';

		return $verdict;
	}

	$avis_site    = trim((string) $avis_site);
	$version_site = trim((string) $version_site);
	if (
		$avis_site === ''
		|| !preg_match('/^\d+\.\d+\.\d+/', $version_site)
		|| !spip_version_compare($avis_site, $version_site, '>')
		|| dashboard_api_branche($avis_site) !== dashboard_api_branche($version_site)
		// Le core du site géré, lui, ne filtre pas la stabilité pour une mise à
		// jour de branche : il proposerait une candidate. La règle est la nôtre,
		// et elle vaut quelle que soit la provenance de l'avis.
		|| !dashboard_api_stable($avis_site)
	) {
		return $verdict;
	}

	// Le site dit qu'une version l'attend, et la tour n'en sait rien : on
	// l'adopte, sans empreinte ni adresse — c'est tout ce qu'il nous a donné.
	$verdict['etat']       = 'retard';
	$verdict['cible']      = $avis_site;
	$verdict['provenance'] = 'site';

	return $verdict;
}

/**
 * Les deux adresses de l'annuaire, dans l'ordre où on les lit.
 *
 * Le suffixe est celui du format, et la première adresse fait autorité. Le
 * réglage permet de pointer un miroir interne ; vidé, il coupe la lecture de
 * l'annuaire sans rien casser d'autre — les versions saisies à la main et
 * l'index d'archives continuent de fonctionner.
 *
 * @return array
 */
function dashboard_api_adresses() {
	include_spip('inc/config');
	include_spip('inc/dashboard_client');

	// Trois états, et non deux. `dashboard_config()` traite la chaîne vide comme
	// une absence et rendrait le défaut : l'adresse serait alors impossible à
	// vider, alors que la vider est un usage légitime — un parc qui ne veut
	// consulter aucun service extérieur, et s'en tient aux versions qu'il
	// impose. C'est le même piège que l'adresse de SPIP Check.
	$reglee = lire_config('dashboard/url_versions_spip', null);
	$base   = trim((string) ($reglee === null ? _DASHBOARD_VERSIONS_API : $reglee));
	if ($base === '' || !dashboard_url_acceptable($base)) {
		return [];
	}
	$base = rtrim($base, '/');

	return [$base . '/3', $base];
}

/**
 * Interroge l'annuaire et rend un résultat normalisé, fusionné.
 *
 * On appelle `recuperer_url()` et non `recuperer_url_cache()`, alors que le core
 * fait l'inverse. Ce n'est pas un oubli : le cache du core est un fichier daté,
 * rafraîchi même sur un 304, et c'est exactement le mécanisme qui a verrouillé
 * les catalogues SVP d'un parc entier pendant six heures (voir
 * `dashagent_svp_relecture_constat()`). Notre cache à nous est une meta que
 * « forcer » efface ; il ne peut pas se refermer sur lui-même.
 *
 * @return array{annuaire: array, statuts: array}
 */
function dashboard_api_interroger() {
	include_spip('inc/distant');

	$annuaire = ['api' => 0, 'versions' => [], 'branche_defaut' => ''];
	$statuts  = [];

	foreach (dashboard_api_adresses() as $url) {
		$reponse = recuperer_url($url, ['taille_max' => 512 * 1024]);
		$statut  = is_array($reponse) ? (int) ($reponse['status'] ?? 0) : 0;
		$lu      = dashboard_api_analyser((string) ($reponse['page'] ?? ''));

		$statuts[$url] = [
			'status'   => $statut,
			'api'      => (int) $lu['api'],
			'versions' => count($lu['versions']),
		];

		$annuaire = dashboard_api_fusionner($annuaire, $lu);
	}

	return ['annuaire' => $annuaire, 'statuts' => $statuts];
}

/**
 * L'annuaire des versions, du cache ou de la source.
 *
 * Un échec ne vide jamais le cache : un annuaire de trois heures vaut
 * infiniment mieux qu'un « je ne sais pas » qui ferait disparaître tout le parc
 * du radar. C'est ce que fait le core lui aussi, et pour la même raison.
 *
 * @param bool $forcer Ignorer le cache
 * @return array
 */
function dashboard_api_annuaire($forcer = false) {
	include_spip('inc/config');

	// Une page de parc appelle ceci une fois par site, et une fiche une fois par
	// ligne de tableau : sans mémoire de passage, un annuaire périmé partirait en
	// autant de requêtes qu'il y a de lignes.
	static $memoire = null;
	if (!$forcer && $memoire !== null) {
		return $memoire;
	}

	// L'annuaire mémorisé est relu **même quand on force** : forcer veut dire
	// « ne te contente pas de ce que tu as », pas « oublie ce que tu sais ». Le
	// jeter d'abord faisait disparaître tout le parc du radar dès qu'une
	// relecture demandée à la main tombait sur une source muette.
	$cache = (array) lire_config('dashboard/cache_api', []);
	if (
		!$forcer
		&& !empty($cache['versions'])
		&& !empty($cache['date'])
		&& time() - (int) $cache['date'] <= _DASHBOARD_CACHE_API
	) {
		return $memoire = [
			'api'            => (int) ($cache['api'] ?? 0),
			'versions'       => (array) $cache['versions'],
			'branche_defaut' => (string) ($cache['branche_defaut'] ?? ''),
		];
	}

	$lecture  = dashboard_api_interroger();
	$annuaire = $lecture['annuaire'];

	if ($annuaire['versions']) {
		ecrire_config('dashboard/cache_api', [
			'date'           => time(),
			'api'            => (int) $annuaire['api'],
			'versions'       => $annuaire['versions'],
			'branche_defaut' => (string) $annuaire['branche_defaut'],
		]);

		return $memoire = $annuaire;
	}

	// Rien de lisible. On le dit — c'est tout l'enjeu : une source muette qui ne
	// laisse aucune trace, c'est un parc qui passe pour sain sans que personne
	// ne puisse le savoir.
	foreach ($lecture['statuts'] as $url => $etat) {
		spip_log('dashboard_api : ' . $url . ' → HTTP ' . $etat['status']
			. ', ' . $etat['versions'] . ' version(s) lisible(s)', 'dashboard');
	}
	if (!$lecture['statuts']) {
		spip_log('dashboard_api : aucune adresse d’annuaire configurée', 'dashboard');
	}

	if (!empty($cache['versions'])) {
		return $memoire = [
			'api'            => (int) ($cache['api'] ?? 0),
			'versions'       => (array) $cache['versions'],
			'branche_defaut' => (string) ($cache['branche_defaut'] ?? ''),
		];
	}

	return $memoire = ['api' => 0, 'versions' => [], 'branche_defaut' => ''];
}

/**
 * L'annuaire, enrichi des versions imposées à la main.
 *
 * Une version saisie dans la configuration fait toujours autorité — c'est ce
 * qui permet à un parc de rester sur une version validée — mais elle n'apporte
 * ni empreinte ni adresse : seul son numéro compte, et l'adresse se retrouve
 * alors par l'index d'archives comme avant.
 *
 * @param bool $forcer
 * @return array Annuaire normalisé, indexé par version
 */
function dashboard_api_versions($forcer = false) {
	include_spip('inc/dashboard_versions');

	$annuaire = dashboard_api_annuaire($forcer);
	$versions = $annuaire['versions'];

	foreach (dashboard_versions_manuelles() as $branche => $version) {
		// Une version imposée remplace ce que l'annuaire dit de sa branche :
		// laisser cohabiter les deux ferait ressortir la plus élevée, c'est-à-dire
		// souvent celle qu'on voulait justement écarter.
		foreach (array_keys($versions) as $connue) {
			if (dashboard_api_branche((string) $connue) === $branche) {
				unset($versions[$connue]);
			}
		}
		$versions[$version] = [
			'version'  => $version,
			'url'      => '',
			'sha256'   => '',
			'sha1'     => '',
			'php'      => [],
			'php_mini' => '',
			'ram'      => 0,
			'espace'   => 0,
		];
	}

	return $versions;
}

/**
 * Relire l'annuaire, une fois pour toute une salve d'actions de parc.
 *
 * « Relire les dépôts » s'applique site par site, une requête chacun : forcer
 * l'annuaire à chaque passage ferait dix appels à spip.net pour dix sites, là où
 * un seul renseigne tout le monde. Un annuaire rapatrié dans la minute est donc
 * considéré comme frais, et les neuf suivants ne font rien.
 *
 * @return bool Vrai si l'annuaire a effectivement été relu
 */
function dashboard_api_forcer_une_fois() {
	include_spip('inc/config');

	$cache = (array) lire_config('dashboard/cache_api', []);
	if (!empty($cache['date']) && time() - (int) $cache['date'] < 60) {
		return false;
	}

	dashboard_api_annuaire(true);

	return true;
}

/**
 * Ce qu'une version attend de PHP, pour affichage, depuis son seul numéro.
 *
 * @param string $version
 * @return string
 */
function dashboard_core_php_requis($version) {
	$versions = dashboard_api_versions();
	$fiche    = $versions[(string) $version] ?? [];

	return $fiche ? dashboard_api_php_libelle($fiche) : '';
}
