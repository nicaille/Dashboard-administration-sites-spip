<?php
/**
 * Mise à jour d'un plugin par SVP, quand le site géré en dispose.
 *
 * SVP sait déjà faire ce que l'agent faisait à la main, et mieux : il vérifie
 * les dépendances avant d'agir, télécharge dans `plugins/auto/<prefixe>/v<x>`,
 * écarte l'ancien dossier, active le nouveau, enchaîne la migration de schéma
 * du plugin et tient à jour son propre inventaire de paquets. Déployer une
 * archive par-dessus tout cela revenait à défaire son travail.
 *
 * Le découpage suit celui de nos chantiers : `preflight` dit ce qui va se
 * passer et pourquoi cela pourrait échouer, `preparer` fige la file d'actions,
 * `avancer` en joue **une seule** — de sorte qu'un aller-retour HTTP suffit
 * toujours, quel que soit le nombre de dépendances à installer.
 *
 * @package SPIP\Dashagent\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * SVP est-il utilisable sur ce site ?
 *
 * Trois conditions, et chacune donne lieu à un message distinct : sans quoi
 * « SVP indisponible » laisserait chercher longtemps.
 *
 * @return array{ok: bool, erreur: string, version: string, dir_auto: string}
 */
function dashagent_svp_disponible() {
	include_spip('inc/dashagent_infos');

	$vide = ['ok' => false, 'version' => '', 'dir_auto' => '', 'raison' => 'svp_absent'];

	if (!find_in_path('inc/svp_decider.php') || !find_in_path('inc/svp_actionner.php')) {
		return $vide + ['erreur' => 'SVP n’est pas actif sur ce site'];
	}
	if (!dashagent_table_existe('spip_paquets') || !dashagent_table_existe('spip_depots')) {
		return $vide + ['erreur' => 'Les tables de SVP sont absentes : son installation est incomplète'];
	}

	include_spip('inc/plugin');
	if (!defined('_DIR_PLUGINS_AUTO') || !_DIR_PLUGINS_AUTO) {
		return $vide + ['erreur' => '_DIR_PLUGINS_AUTO n’est pas défini'];
	}

	$dir = _DIR_PLUGINS_AUTO;
	// Personne ne crée ce répertoire : ni SPIP, ni SVP, qui se contente de
	// refuser d'agir s'il manque. Sur un site où les plugins n'ont jamais été
	// installés depuis l'espace privé, il est donc absent — et le créer est
	// exactement le geste que ferait l'administrateur au premier téléchargement.
	if (!is_dir($dir) && is_writable(_DIR_PLUGINS)) {
		include_spip('inc/flock');
		sous_repertoire(_DIR_PLUGINS, 'auto');
	}
	if (!is_dir($dir) || !is_writable($dir)) {
		return ['ok' => false, 'version' => '', 'dir_auto' => $dir, 'raison' => 'dir_auto',
			'erreur' => $dir . ' n’est pas inscriptible'];
	}

	$actifs = unserialize($GLOBALS['meta']['plugin'] ?? '');
	$version = is_array($actifs) ? (string) ($actifs['SVP']['version'] ?? '') : '';

	return ['ok' => true, 'erreur' => '', 'raison' => '', 'version' => $version, 'dir_auto' => $dir];
}

/**
 * État des dépôts de plugins du site, et de leur fraîcheur.
 *
 * Le catalogue des versions disponibles ne se rafraîchit pas tout seul : il est
 * relu quand un administrateur clique « actualiser les dépôts » dans l'espace
 * privé, ou quand la tâche de fond de SVP passe — ce qui, sur un site peu
 * visité, peut n'arriver que rarement. Un tableau de bord qui lit ce catalogue
 * sans en donner l'âge annonce « tout est à jour » avec l'assurance de quelqu'un
 * qui n'a pas regardé depuis trois semaines.
 *
 * @return array
 */
function dashagent_svp_depots() {
	include_spip('inc/dashagent_infos');

	if (!dashagent_table_existe('spip_depots')) {
		return [];
	}

	$depots = [];
	foreach ((array) sql_allfetsel(
		['id_depot', 'titre', 'xml_paquets', 'nbr_paquets', 'nbr_plugins', 'maj'],
		'spip_depots',
		'',
		'',
		'maj'
	) as $depot) {
		$date = (string) ($depot['maj'] ?? '');
		$vu   = ($date && $date !== '0000-00-00 00:00:00') ? strtotime($date) : 0;

		$depots[] = [
			'id'          => (int) $depot['id_depot'],
			'titre'       => (string) $depot['titre'],
			'source'      => (string) $depot['xml_paquets'],
			'paquets'     => (int) $depot['nbr_paquets'],
			'plugins'     => (int) $depot['nbr_plugins'],
			'date'        => $date,
			// L'âge en secondes plutôt qu'une date : les deux sites peuvent
			// avoir des fuseaux ou des horloges différentes, et c'est l'âge qui
			// décide s'il faut actualiser.
			'age'         => $vu ? max(0, time() - $vu) : null,
		];
	}

	return $depots;
}

/**
 * Actualise **un** dépôt, le plus ancien d'abord, et rend la main.
 *
 * Un dépôt, c'est le téléchargement d'un catalogue XML de plusieurs méga-octets
 * et sa réindexation : de quoi dépasser le temps d'exécution d'un hébergement
 * mutualisé si on les enchaîne. Un par appel, donc, comme pour le reste.
 *
 * @param array $args
 *     - int `age_max` : ne rafraîchir qu'au-delà de cet âge, en secondes.
 *       Zéro force le rafraîchissement de tous.
 * @return array
 */
function dashagent_svp_depots_actualiser($args = []) {
	$svp = dashagent_svp_disponible();
	if (!$svp['ok']) {
		return ['ok' => false, 'erreur' => $svp['erreur'], 'raison' => $svp['raison'], 'termine' => true];
	}

	$age_max = max(0, (int) ($args['age_max'] ?? 0));
	$restants = dashagent_svp_depots_a_rafraichir($age_max);

	if (!$restants) {
		return dashagent_svp_depots_conclure(true, '', 0);
	}

	@set_time_limit(300);
	include_spip('inc/svp_depoter_distant');

	$depot = reset($restants);
	$niveau = ob_get_level();
	ob_start();
	$echec = null;
	try {
		$fait = svp_actualiser_depot($depot['id']);
	} catch (Throwable $e) {
		$fait = false;
		$echec = $e->getMessage();
	}
	$sortie = dashagent_svp_vider_tampons($niveau);

	if ($echec !== null || !$fait) {
		return [
			'ok'      => false,
			'erreur'  => 'Dépôt « ' . $depot['titre'] . ' » non actualisé'
				. ($echec !== null ? ' : ' . $echec : ' (catalogue injoignable ou illisible ?)'),
			'termine' => true,
			'journal' => dashagent_svp_texte($sortie),
			'depots'  => dashagent_svp_depots(),
		];
	}

	// SVP n'actualise la date du dépôt que par effet de bord : quand le
	// catalogue n'a pas changé, il réécrit le même `sha_paquets` en comptant
	// sur `ON UPDATE CURRENT_TIMESTAMP` pour rafraîchir `maj` — c'est même
	// écrit dans son commentaire. Or MySQL ne déclenche pas cette clause
	// lorsqu'un UPDATE n'altère aucune valeur : la date reste en arrière, le
	// dépôt figure encore parmi ceux « à relire », et l'appelant le redemande
	// indéfiniment. SQLite, lui, la bouge — d'où un défaut qui ne se voit que
	// sur les sites en MySQL, c'est-à-dire presque tous.
	//
	// On l'écrit donc nous-mêmes : le catalogue vient réellement d'être relu,
	// la date doit le dire.
	sql_updateq('spip_depots', ['maj' => date('Y-m-d H:i:s')], 'id_depot = ' . (int) $depot['id']);

	$reste = count(dashagent_svp_depots_a_rafraichir($age_max));

	return dashagent_svp_depots_conclure(!$reste, $depot['titre'], $reste)
		+ ['journal' => dashagent_svp_texte($sortie)];
}

/**
 * Plancher de fraîcheur, en secondes.
 *
 * « Forcer » ne peut pas vouloir dire « sans condition » : l'appelant rappelle
 * tant qu'il reste des dépôts, et un dépôt qu'on vient de relire serait aussitôt
 * à relire de nouveau — la boucle ne s'arrêterait jamais. Une minute suffit à
 * distinguer « je viens de le faire » de « c'est à refaire ».
 */
if (!defined('_DASHAGENT_DEPOTS_PLANCHER')) {
	define('_DASHAGENT_DEPOTS_PLANCHER', 60);
}

/**
 * Les dépôts dont le catalogue mérite d'être relu.
 *
 * @param int $age_max Âge en secondes au-delà duquel un dépôt est périmé.
 *     Zéro force, dans la limite du plancher ci-dessus.
 * @return array
 */
function dashagent_svp_depots_a_rafraichir($age_max) {
	$age_max = $age_max > 0 ? max(_DASHAGENT_DEPOTS_PLANCHER, (int) $age_max) : _DASHAGENT_DEPOTS_PLANCHER;
	$a_faire = [];
	foreach (dashagent_svp_depots() as $depot) {
		// Un dépôt sans catalogue à relire — construit à la main, ou hérité
		// d'une version antérieure de SVP — n'a rien à rafraîchir : l'inclure
		// ferait échouer l'étape sans qu'on puisse y remédier à distance.
		if ($depot['source'] === '') {
			continue;
		}
		// Jamais actualisé : l'âge est inconnu, donc infini.
		if ($depot['age'] === null || $depot['age'] > $age_max) {
			$a_faire[] = $depot;
		}
	}

	return $a_faire;
}

/**
 * Ce qu'on rend après un dépôt actualisé, ou quand il n'y avait rien à faire.
 *
 * @param bool $termine
 * @param string $titre Dépôt qui vient d'être relu
 * @param int $reste
 * @return array
 */
function dashagent_svp_depots_conclure($termine, $titre, $reste) {
	// Le catalogue a changé : sans relecture des paquets locaux, les versions
	// disponibles restent celles d'avant, et c'est précisément ce qu'on répare.
	// Après chaque dépôt, et non à la fin seulement : un site à plusieurs dépôts
	// profite du premier relu sans attendre les autres.
	if ($titre !== '') {
		include_spip('inc/svp_depoter_local');
		$niveau = ob_get_level();
		ob_start();
		svp_actualiser_paquets_locaux();
		svp_actualiser_maj_version();
		dashagent_svp_vider_tampons($niveau);
	}

	return [
		'ok'       => true,
		'erreur'   => '',
		'termine'  => (bool) $termine,
		'actualise' => $titre,
		'reste'    => (int) $reste,
		'depots'   => dashagent_svp_depots(),
	];
}

/**
 * Le paquet local d'un préfixe, tel que SVP le connaît.
 *
 * @param string $prefixe
 * @return array|null
 */
function dashagent_svp_paquet_local($prefixe) {
	$ligne = sql_fetsel(
		['pa.id_paquet', 'pa.version', 'pa.maj_version', 'pa.actif', 'pa.src_archive', 'pa.constante', 'pl.prefixe'],
		['spip_paquets AS pa', 'spip_plugins AS pl'],
		[
			'pa.id_plugin = pl.id_plugin',
			'pa.id_depot = 0',
			'UPPER(pl.prefixe) = ' . sql_quote(strtoupper((string) $prefixe)),
		],
		'',
		'pa.etatnum DESC'
	);

	return $ligne ?: null;
}

/**
 * Ce que SVP ferait, et ce qui l'en empêcherait.
 *
 * Aucune action n'est engagée ici : le décideur travaille en mémoire. C'est ce
 * qui permet d'annoncer un refus de dépendance **avant** la sauvegarde et le
 * téléchargement, plutôt que de laisser un plugin déployé puis désactivé.
 *
 * @param array $args
 *     - string `prefixe` (obligatoire)
 * @return array
 */
function dashagent_svp_preflight($args) {
	$prefixe = strtoupper(trim((string) ($args['prefixe'] ?? '')));
	if ($prefixe === '') {
		return ['ok' => false, 'raison' => 'prefixe', 'erreur' => 'Préfixe de plugin obligatoire'];
	}

	$svp = dashagent_svp_disponible();
	if (!$svp['ok']) {
		return ['ok' => false, 'raison' => $svp['raison'], 'erreur' => $svp['erreur'], 'svp' => $svp];
	}

	$paquet = dashagent_svp_paquet_local($prefixe);
	if (!$paquet) {
		return [
			'ok' => false,
			'raison' => 'paquet_inconnu',
			'erreur' => 'SVP ne connaît pas de paquet local pour ' . $prefixe
				. ' — actualisez ses dépôts sur le site géré',
			'svp' => $svp,
		];
	}

	include_spip('svp_fonctions');
	$version = dashagent_denormaliser_version((string) $paquet['version']);
	$cible   = dashagent_denormaliser_version((string) $paquet['maj_version']);

	$etat = [
		'svp'            => $svp,
		'id_paquet'      => (int) $paquet['id_paquet'],
		'prefixe'        => $prefixe,
		'version'        => $version,
		'version_cible'  => $cible,
		'dossier'        => (string) $paquet['src_archive'],
		'actif'          => ((string) $paquet['actif'] === 'oui'),
		'verrou'         => dashagent_svp_verrou(),
	];

	if ($cible === '') {
		return [
			'ok' => false,
			'raison' => 'maj_inconnue',
			'erreur' => 'SVP n’annonce aucune mise à jour pour ' . $prefixe
				. ' — actualisez ses dépôts sur le site géré',
		] + $etat;
	}

	$plan = dashagent_svp_planifier((int) $paquet['id_paquet']);

	return [
		'ok'      => $plan['ok'],
		'raison'  => $plan['ok'] ? '' : $plan['raison'],
		'erreur'  => $plan['erreur'],
		'actions' => $plan['actions'],
		'nombre'  => count($plan['todo']),
	] + $etat;
}

/**
 * Fait calculer à SVP la liste des actions, dépendances comprises.
 *
 * @param int $id_paquet Paquet local à mettre à jour
 * @return array{ok: bool, erreur: string, actions: array, todo: array}
 */
function dashagent_svp_planifier($id_paquet) {
	include_spip('inc/svp_decider');
	// `presenter_actions()` passe par `denormaliser_version()`, que SVP range
	// dans ses fonctions de squelette : hors du privé, rien ne les a chargées.
	include_spip('svp_fonctions');

	$decideur = new Decideur();
	// Sans cela, un plugin dont la mise à jour a disparu du dépôt entre deux
	// synchronisations fait échouer tout le calcul plutôt que d'être signalé.
	$decideur->erreur_sur_maj_introuvable = true;

	// `upon` : mettre à jour **et** activer. `up` laisserait un plugin actif sur
	// son ancien dossier, qui n'existe plus après coup.
	$decideur->verifier_dependances([$id_paquet => 'upon']);

	if (!$decideur->ok) {
		$erreurs = [];
		foreach ((array) $decideur->err as $liste) {
			foreach ((array) $liste as $message) {
				$erreurs[] = is_string($message) ? $message : json_encode($message);
			}
		}

		return [
			'ok'      => false,
			// Un refus de dépendance n'est pas une indisponibilité : il ne doit
			// surtout pas faire retomber le tableau de bord sur le déploiement
			// d'archive, qui installerait justement ce que SVP refuse.
			'raison'  => 'dependances',
			'erreur'  => 'SVP refuse la mise à jour : ' . implode(' ; ', array_slice($erreurs, 0, 5)),
			'actions' => $erreurs,
			'todo'    => [],
		];
	}

	$todo = [];
	foreach ((array) $decideur->todo as $entree) {
		if (isset($entree['i'], $entree['todo'])) {
			$todo[$entree['i']] = $entree['todo'];
		}
	}

	return [
		'ok'      => (bool) $todo,
		'raison'  => $todo ? '' : 'maj_inconnue',
		'erreur'  => $todo ? '' : 'SVP n’a aucune action à faire pour ce plugin',
		'actions' => array_values((array) $decideur->presenter_actions('todo')),
		'todo'    => $todo,
	];
}

/**
 * L'état du verrou de SVP sur le site géré.
 *
 * SVP verrouille sa file au nom de l'auteur qui l'a lancée. L'agent n'a pas de
 * session : il ne pose donc aucun verrou, mais il doit reconnaître celui d'un
 * administrateur en train de travailler dans l'espace privé — sinon
 * `one_action()` refuserait d'agir, en silence et indéfiniment.
 *
 * @return array{pose: bool, id_auteur: int, date: int, age: int}
 */
function dashagent_svp_verrou() {
	include_spip('inc/svp_actionner');

	$actionneur = new Actionneur();
	$actionneur->get_actions();

	$id_auteur = (int) ($actionneur->lock['id_auteur'] ?? 0);
	$date      = (int) ($actionneur->lock['time'] ?? 0);

	return [
		'pose'      => (bool) $id_auteur,
		'id_auteur' => $id_auteur,
		'date'      => $date,
		'age'       => $date ? (time() - $date) : 0,
		'reste'     => count((array) $actionneur->end) + (($actionneur->work ?? []) ? 1 : 0),
	];
}

/**
 * Fige la file d'actions que `avancer` jouera ensuite, une par une.
 *
 * @param array $args
 *     - string `prefixe` (obligatoire)
 *     - bool `forcer` : passer outre un verrou existant
 * @return array
 */
function dashagent_svp_preparer($args) {
	$preflight = dashagent_svp_preflight($args);
	if (empty($preflight['ok'])) {
		return $preflight;
	}

	$verrou = $preflight['verrou'];
	if ($verrou['pose'] && empty($args['forcer'])) {
		return [
			'ok' => false,
			'raison' => 'verrou',
			'erreur' => 'Une série d’actions SVP est déjà en cours sur le site (auteur '
				. $verrou['id_auteur'] . ', depuis ' . $verrou['age'] . ' s) : reprise impossible sans risque',
		] + $preflight;
	}

	include_spip('inc/svp_actionner');
	$actionneur = new Actionneur();
	// Repartir d'une file propre : un reliquat d'une tentative abandonnée
	// s'ajouterait à la nôtre et serait joué sans que personne l'ait demandé.
	$actionneur->nettoyer_actions();

	$plan = dashagent_svp_planifier((int) $preflight['id_paquet']);
	if (!$plan['ok']) {
		return ['ok' => false, 'raison' => $plan['raison'], 'erreur' => $plan['erreur'],
			'actions' => $plan['actions']] + $preflight;
	}

	$actionneur->ajouter_actions($plan['todo']);
	$actionneur->sauver_actions();

	return [
		'ok'      => true,
		'erreur'  => '',
		'actions' => $plan['actions'],
		'reste'   => count((array) $actionneur->end),
	] + $preflight;
}

/**
 * Joue **une** action de la file, et rend la main.
 *
 * Une action, c'est un téléchargement, une activation, une installation de
 * schéma : quelques secondes chacune. Le tableau de bord rappelle tant que
 * `termine` est faux.
 *
 * @param array $args
 * @return array
 */
function dashagent_svp_avancer($args) {
	$svp = dashagent_svp_disponible();
	if (!$svp['ok']) {
		return ['ok' => false, 'erreur' => $svp['erreur'], 'termine' => true];
	}

	include_spip('inc/svp_actionner');
	include_spip('inc/autoriser');

	$actionneur = new Actionneur();
	$actionneur->get_actions();

	$reste_avant = count((array) $actionneur->end) + (($actionneur->work ?? []) ? 1 : 0);
	if (!$reste_avant) {
		return dashagent_svp_conclure($actionneur, $args);
	}

	// L'agent agit sans session : les autorisations de l'espace privé ne
	// s'appliquent pas, c'est la signature de la requête qui fait foi.
	dashagent_svp_autoriser();

	$niveau = ob_get_level();
	ob_start();
	$echec = null;
	try {
		$action = $actionneur->one_action();
	} catch (Throwable $e) {
		$action = false;
		$echec = $e->getMessage();
	}
	$sortie = dashagent_svp_vider_tampons($niveau);
	$actionneur->sauver_actions();

	if ($echec !== null) {
		return [
			'ok' => false,
			'erreur' => 'Action SVP interrompue : ' . $echec,
			'termine' => true,
			'journal' => dashagent_svp_texte($sortie),
		];
	}

	// `one_action()` rend false quand la file est vide, mais aussi quand le
	// verrou appartient à quelqu'un d'autre : sans cette distinction, un verrou
	// oublié passerait pour un travail terminé.
	if ($action === false && $reste_avant) {
		return [
			'ok' => false,
			'erreur' => 'SVP refuse d’agir : sa file est verrouillée par un autre administrateur',
			'termine' => true,
			'verrou' => dashagent_svp_verrou(),
		];
	}

	$reste = count((array) $actionneur->end) + (($actionneur->work ?? []) ? 1 : 0);
	$faite = is_array($action)
		? trim((string) ($action['todo'] ?? '') . ' ' . (string) ($action['n'] ?? ($action['p'] ?? '')))
		: '';

	if ($reste) {
		return [
			'ok'      => true,
			'erreur'  => '',
			'termine' => false,
			'action'  => $faite,
			'reste'   => $reste,
			'faites'  => count((array) $actionneur->done),
			'journal' => dashagent_svp_texte($sortie),
		];
	}

	return ['action' => $faite, 'journal' => dashagent_svp_texte($sortie)]
		+ dashagent_svp_conclure($actionneur, $args);
}

/**
 * Remet le site en cohérence une fois la file épuisée.
 *
 * @param Actionneur $actionneur
 * @param array $args
 * @return array
 */
function dashagent_svp_conclure($actionneur, $args) {
	include_spip('inc/dashagent_maj');
	include_spip('inc/svp_depoter_local');

	$erreurs = array_values(array_filter((array) $actionneur->err, 'is_string'));

	// SVP a déplacé des répertoires : son propre inventaire de paquets locaux
	// est périmé tant qu'on ne le lui dit pas, et le site annoncerait encore
	// l'ancienne version.
	$actualise = false;
	if (function_exists('svp_actualiser_paquets_locaux')) {
		$niveau = ob_get_level();
		ob_start();
		svp_actualiser_paquets_locaux();
		dashagent_svp_vider_tampons($niveau);
		$actualise = true;
	}

	$post = dashagent_apres_maj();
	$actionneur->nettoyer_actions();

	include_spip('inc/meta');
	lire_metas();

	$prefixe = strtoupper(trim((string) ($args['prefixe'] ?? '')));
	$apres = $prefixe ? dashagent_plugin_actif($prefixe) : null;

	return [
		'ok'            => !$erreurs,
		'erreur'        => $erreurs ? implode(' ; ', array_slice($erreurs, 0, 5)) : '',
		'termine'       => true,
		'reste'         => 0,
		'faites'        => count((array) $actionneur->done),
		'version_apres' => (string) ($apres['version'] ?? ''),
		// Le dossier retenu se lit dans la liste des plugins actifs, pas dans
		// l'inventaire de SVP : après une mise à jour celui-ci porte deux
		// paquets locaux pour le même préfixe — l'ancien dossier et le nouveau —
		// et rien n'y dit lequel SPIP a finalement activé.
		'dossier'       => (string) ($apres['dossier'] ?? ''),
		'inventaire_svp' => $actualise,
		'post'          => $post,
	];
}

/**
 * Libère une file SVP restée en plan.
 *
 * Une mise à jour interrompue laisse un verrou et des actions en attente : sans
 * moyen de les effacer à distance, le site resterait bloqué jusqu'à ce que
 * quelqu'un ouvre son espace privé.
 *
 * @param array $args
 * @return array
 */
function dashagent_svp_liberer($args) {
	$svp = dashagent_svp_disponible();
	if (!$svp['ok']) {
		return ['ok' => false, 'erreur' => $svp['erreur']];
	}

	include_spip('inc/svp_actionner');
	$actionneur = new Actionneur();
	$actionneur->get_actions();
	$avant = dashagent_svp_verrou();
	$actionneur->nettoyer_actions();

	return ['ok' => true, 'erreur' => '', 'verrou_avant' => $avant, 'verrou' => dashagent_svp_verrou()];
}

/**
 * Lève les autorisations dont SVP a besoin pour agir.
 *
 * `autoriser_exception('ajouter', '_plugins', '*')` — la formule qu'emploient
 * SVP comme spip-cli — **ne suffit pas** sur SPIP 4.4 : l'exception est rangée
 * sous le type déjà réduit (`plugins`), mais `autoriser()` la relit en
 * repassant par `autoriser_type()`, qui le transforme une seconde fois en
 * `plugin`. La clé consultée n'est donc jamais celle qui a été écrite, et
 * `action/teleporter.php` refuse le téléchargement sans autre explication que
 * « Chargement impossible de la source ».
 *
 * On déclare donc les deux orthographes. Le jour où SPIP corrigera la double
 * transformation, la seconde deviendra inutile sans rien casser.
 *
 * @return void
 */
function dashagent_svp_autoriser() {
	include_spip('inc/autoriser');
	include_spip('base/objets');

	foreach (['ajouter', 'configurer'] as $faire) {
		autoriser_exception($faire, '_plugins', '*');
		if (function_exists('autoriser_type')) {
			autoriser_exception($faire, autoriser_type('plugins'), '*');
		}
	}
}

/**
 * Referme les tampons ouverts depuis un niveau donné et rend ce qu'ils ont pris.
 *
 * Les actions de SVP affichent : sans ce garde-fou, leur HTML se mêlerait à
 * notre réponse JSON.
 *
 * @param int $niveau
 * @return string
 */
function dashagent_svp_vider_tampons($niveau) {
	$sortie = '';
	while (ob_get_level() > $niveau) {
		$sortie = ob_get_clean() . $sortie;
	}

	return $sortie;
}

/**
 * Rend lisible, et borné, ce que SVP a affiché.
 *
 * @param string $html
 * @return string
 */
function dashagent_svp_texte($html) {
	$texte = trim(preg_replace('/\s+/', ' ', strip_tags((string) $html)));

	return strlen($texte) > 2000 ? substr($texte, 0, 2000) . '…' : $texte;
}
