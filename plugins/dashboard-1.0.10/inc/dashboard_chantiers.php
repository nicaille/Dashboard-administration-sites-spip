<?php
/**
 * Chantiers : les mises à jour distantes, menées étape par étape.
 *
 * Une mise à jour ne tient pas dans une requête HTTP. Télécharger une
 * sauvegarde, remplacer un noyau, migrer un schéma : chacune de ces opérations
 * peut durer des minutes, et l'hébergeur du site géré comme celui du tableau de
 * bord coupera bien avant. Un chantier est donc une suite d'étapes persistée en
 * base, dont chaque avancement ne fait qu'un aller-retour avec l'agent.
 *
 * Deux conséquences heureuses : l'avancement est visible pendant qu'il se
 * déroule, et un chantier interrompu — navigateur fermé, requête coupée —
 * reprend là où il en était, poussé par le cron.
 *
 * @package SPIP\Dashboard\Chantiers
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Nombre d'avancements au-delà duquel un chantier est déclaré en échec.
 *
 * Une migration de schéma rend la main toutes les vingt-cinq secondes environ ;
 * ce plafond laisse la place à une grosse migration tout en empêchant un
 * chantier qui ne progresse plus de tourner indéfiniment.
 */
if (!defined('_DASHBOARD_CHANTIER_AVANCEMENTS_MAX')) {
	define('_DASHBOARD_CHANTIER_AVANCEMENTS_MAX', 60);
}

/**
 * Délai après lequel un chantier sans nouvelle est considéré comme abandonné.
 *
 * Le navigateur qui le poussait a été fermé, ou la requête a été coupée : le
 * cron peut le reprendre sans risque de doubler un avancement en cours.
 */
if (!defined('_DASHBOARD_CHANTIER_ABANDON')) {
	define('_DASHBOARD_CHANTIER_ABANDON', 180);
}

/**
 * Étapes de chaque opération, dans l'ordre.
 *
 * La sauvegarde ouvre toutes les listes sans exception : rien n'est remplacé
 * sur un site géré sans qu'une copie de sa base ait d'abord été mise à l'abri.
 *
 * @param string $operation
 * @return array
 */
function dashboard_chantier_etapes($operation) {
	$etapes = [
		'plugin_maj'      => ['sauvegarde', 'plugin', 'sync'],
		// Un inventaire relu juste avant de constituer la file : entre le clic
		// et la sauvegarde, il a pu se passer plusieurs minutes, et mettre à
		// jour depuis une liste périmée n'aurait guère de sens.
		'plugin_maj_tous' => ['sauvegarde', 'sync', 'plugins', 'sync'],
		'core_maj'        => ['sauvegarde', 'preflight', 'core', 'base', 'sync'],
		'base_maj'        => ['sauvegarde', 'base', 'sync'],
	];

	return $etapes[$operation] ?? [];
}

/**
 * Cette opération se mène-t-elle en chantier ?
 *
 * @param string $operation
 * @return bool
 */
function dashboard_chantier_operation_connue($operation) {
	return (bool) dashboard_chantier_etapes($operation);
}

/**
 * Ouvre un chantier sur un site, s'il n'y en a pas déjà un en cours.
 *
 * @param int $id_dashboard_site
 * @param string $operation
 * @param string $cible Préfixe de plugin, version de SPIP… selon l'opération
 * @return array{ok: bool, id: int, message: string}
 */
function dashboard_chantier_creer($id_dashboard_site, $operation, $cible = '') {
	$etapes = dashboard_chantier_etapes($operation);
	if (!$etapes) {
		return ['ok' => false, 'id' => 0, 'message' => 'Opération inconnue : ' . $operation];
	}

	// Deux chantiers de front sur le même site se marcheraient dessus : l'un
	// remplacerait des fichiers pendant que l'autre migre le schéma.
	$encours = dashboard_chantier_courant($id_dashboard_site);
	if ($encours) {
		return [
			'ok'      => false,
			'id'      => (int) $encours['id_dashboard_chantier'],
			'message' => 'Une opération est déjà en cours sur ce site : ' . dashboard_chantier_resume($encours),
		];
	}

	$id = sql_insertq('spip_dashboard_chantiers', [
		'id_dashboard_site' => (int) $id_dashboard_site,
		'id_auteur'         => (int) ($GLOBALS['visiteur_session']['id_auteur'] ?? 0),
		'operation'         => $operation,
		'cible'             => (string) $cible,
		'etape'             => $etapes[0],
		'statut'            => 'attente',
		'rang'              => 0,
		'total'             => count($etapes),
		'date'              => date('Y-m-d H:i:s'),
		'date_etape'        => date('Y-m-d H:i:s'),
	]);

	if (!$id) {
		return ['ok' => false, 'id' => 0, 'message' => 'Chantier non enregistré'];
	}

	return ['ok' => true, 'id' => (int) $id, 'message' => ''];
}

/**
 * Le chantier en cours sur un site, s'il y en a un.
 *
 * @param int $id_dashboard_site
 * @return array|null
 */
function dashboard_chantier_courant($id_dashboard_site) {
	$ligne = sql_fetsel(
		'*',
		'spip_dashboard_chantiers',
		[
			'id_dashboard_site = ' . (int) $id_dashboard_site,
			sql_in('statut', ['attente', 'encours']),
		],
		'',
		'id_dashboard_chantier DESC'
	);

	return $ligne ?: null;
}

/**
 * Un chantier par son identifiant.
 *
 * @param int $id_dashboard_chantier
 * @return array|null
 */
function dashboard_chantier_charger($id_dashboard_chantier) {
	$ligne = sql_fetsel('*', 'spip_dashboard_chantiers', 'id_dashboard_chantier = ' . (int) $id_dashboard_chantier);

	return $ligne ?: null;
}

/**
 * Écrit l'état d'un chantier.
 *
 * @param int $id_dashboard_chantier
 * @param array $champs
 * @return void
 */
function dashboard_chantier_ecrire($id_dashboard_chantier, $champs) {
	$champs['date_etape'] = date('Y-m-d H:i:s');
	sql_updateq('spip_dashboard_chantiers', $champs, 'id_dashboard_chantier = ' . (int) $id_dashboard_chantier);
}

/**
 * Un chantier est-il terminé, dans un sens ou dans l'autre ?
 *
 * @param array|null $chantier
 * @return bool
 */
function dashboard_chantier_fini($chantier) {
	return !$chantier || in_array((string) $chantier['statut'], ['ok', 'erreur'], true);
}

/**
 * Fait avancer un chantier d'une étape, et d'une seule.
 *
 * Un avancement fait au plus un aller-retour avec l'agent, de sorte qu'il tient
 * toujours dans une requête. L'appelant rappelle tant que le chantier n'est pas
 * fini : le navigateur pour donner à voir la progression, le cron pour finir le
 * travail quand plus personne ne regarde.
 *
 * @param int $id_dashboard_chantier
 * @return array|null Le chantier après avancement
 */
function dashboard_chantier_avancer($id_dashboard_chantier) {
	include_spip('inc/dashboard_operations');
	include_spip('inc/dashboard_journal');

	$chantier = dashboard_chantier_charger($id_dashboard_chantier);
	if (dashboard_chantier_fini($chantier)) {
		return $chantier;
	}

	$tentatives = (int) $chantier['tentatives'] + 1;
	if ($tentatives > _DASHBOARD_CHANTIER_AVANCEMENTS_MAX) {
		return dashboard_chantier_conclure(
			$chantier,
			false,
			'Opération abandonnée après ' . _DASHBOARD_CHANTIER_AVANCEMENTS_MAX . ' étapes sans aboutir'
				. ' — dernier état : ' . (string) $chantier['message']
		);
	}
	dashboard_chantier_ecrire($id_dashboard_chantier, ['statut' => 'encours', 'tentatives' => $tentatives]);
	$chantier['tentatives'] = $tentatives;
	$chantier['statut'] = 'encours';

	$resultat = dashboard_chantier_executer_etape($chantier);

	if (!$resultat['ok']) {
		return dashboard_chantier_conclure($chantier, false, $resultat['message']);
	}

	// Une étape peut demander à rester en place : c'est le cas de la migration
	// du schéma, que SPIP rend par tranches.
	if (!empty($resultat['rester'])) {
		dashboard_chantier_ecrire($id_dashboard_chantier, [
			'message' => (string) $resultat['message'],
			'reste'   => (string) ($resultat['reste'] ?? $chantier['reste']),
			'detail'  => (string) ($resultat['detail'] ?? $chantier['detail']),
		]);

		return dashboard_chantier_charger($id_dashboard_chantier);
	}

	$etapes = dashboard_chantier_etapes((string) $chantier['operation']);
	$rang   = (int) $chantier['rang'] + 1;

	if ($rang >= count($etapes)) {
		return dashboard_chantier_conclure($chantier, true, (string) $resultat['message']);
	}

	dashboard_chantier_ecrire($id_dashboard_chantier, [
		'rang'    => $rang,
		'etape'   => $etapes[$rang],
		'message' => (string) $resultat['message'],
		'reste'   => (string) ($resultat['reste'] ?? $chantier['reste']),
		'detail'  => (string) ($resultat['detail'] ?? $chantier['detail']),
	]);

	return dashboard_chantier_charger($id_dashboard_chantier);
}

/**
 * Clôt un chantier et en journalise l'issue.
 *
 * @param array $chantier
 * @param bool $ok
 * @param string $message
 * @return array|null
 */
function dashboard_chantier_conclure($chantier, $ok, $message) {
	include_spip('inc/dashboard_journal');

	dashboard_chantier_ecrire((int) $chantier['id_dashboard_chantier'], [
		'statut'   => $ok ? 'ok' : 'erreur',
		'message'  => $message,
		'date_fin' => date('Y-m-d H:i:s'),
	]);

	dashboard_journaliser(
		(int) $chantier['id_dashboard_site'],
		(string) $chantier['operation'],
		$ok ? 'ok' : 'erreur',
		$message,
		['cible' => $chantier['cible'], 'etapes' => (int) $chantier['tentatives']]
	);

	return dashboard_chantier_charger((int) $chantier['id_dashboard_chantier']);
}

/**
 * Exécute l'étape courante d'un chantier.
 *
 * @param array $chantier
 * @return array{ok: bool, message: string, rester?: bool, reste?: string}
 */
function dashboard_chantier_executer_etape($chantier) {
	$id_site = (int) $chantier['id_dashboard_site'];
	$cible   = (string) $chantier['cible'];

	switch ((string) $chantier['etape']) {
		case 'sauvegarde':
			return dashboard_chantier_etape_sauvegarde($id_site);

		case 'preflight':
			$reponse = dashboard_operation_core_preflight($id_site);

			return [
				'ok'      => !empty($reponse['ok']),
				'message' => $reponse['ok'] ? 'Contrôles préalables passés' : (string) $reponse['message'],
			];

		case 'core':
			$reponse = dashboard_operation_core_maj($id_site, $cible);
			if (empty($reponse['ok'])) {
				return ['ok' => false, 'message' => (string) $reponse['message']];
			}

			// La version qui vient d'être posée sur le disque : l'étape suivante
			// s'en sert pour savoir si le site exécute déjà le nouveau code.
			$detail = json_decode((string) $chantier['detail'], true);
			$detail = is_array($detail) ? $detail : [];
			$detail['core_deploye'] = (string) ($reponse['data']['version_apres'] ?? '');

			return [
				'ok'      => true,
				'detail'  => json_encode($detail, JSON_UNESCAPED_UNICODE),
				'message' => (string) $reponse['message'],
			];

		case 'base':
			return dashboard_chantier_etape_base($chantier);

		case 'plugin':
			$reponse = dashboard_operation_plugin_maj($id_site, $cible);

			return ['ok' => !empty($reponse['ok']), 'message' => (string) $reponse['message']];

		case 'plugins':
			return dashboard_chantier_etape_plugins($chantier);

		case 'sync':
			include_spip('inc/dashboard_sync');
			$reponse = dashboard_synchroniser($id_site);
			if (empty($reponse['ok'])) {
				return ['ok' => false, 'message' => (string) $reponse['message']];
			}

			// Le bilan ne vaut qu'au terme : une synchronisation intermédiaire,
			// comme celle qui précède la mise à jour des plugins, annoncerait
			// sinon un chantier terminé alors qu'il commence à peine.
			$etapes = dashboard_chantier_etapes((string) $chantier['operation']);
			$dernier = ((int) $chantier['rang'] + 1) >= count($etapes);

			return [
				'ok'      => true,
				'message' => $dernier ? dashboard_chantier_bilan($chantier) : 'Inventaire rafraîchi',
			];
	}

	return ['ok' => false, 'message' => 'Étape inconnue : ' . $chantier['etape']];
}

/**
 * Sauvegarde préalable, systématique.
 *
 * Une sauvegarde toute fraîche est acceptée telle quelle : enchaîner deux
 * chantiers sur un même site ne doit pas coûter deux dumps complets.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_chantier_etape_sauvegarde($id_dashboard_site) {
	include_spip('inc/dashboard_client');

	$fraicheur = (int) dashboard_config('fraicheur_sauvegarde', 900);
	$recente = $fraicheur > 0
		? sql_fetsel(
			['fichier', 'octets'],
			'spip_dashboard_sauvegardes',
			[
				'id_dashboard_site = ' . (int) $id_dashboard_site,
				'statut = ' . sql_quote('locale'),
				'date > ' . sql_quote(date('Y-m-d H:i:s', time() - $fraicheur)),
			],
			'',
			'date DESC'
		)
		: null;

	if ($recente) {
		return ['ok' => true, 'message' => 'Sauvegarde récente réutilisée : ' . $recente['fichier']];
	}

	$reponse = dashboard_operation_sauvegarder($id_dashboard_site, ['rapatrier' => true]);
	if (empty($reponse['ok'])) {
		return ['ok' => false, 'message' => 'Mise à jour annulée, sauvegarde impossible : ' . (string) $reponse['message']];
	}

	return ['ok' => true, 'message' => (string) $reponse['message']];
}

/**
 * Une tranche de migration du schéma de base.
 *
 * L'agent rend la main dès qu'il a consommé son budget de temps ; tant qu'il
 * annonce qu'il reste à faire, l'étape ne bouge pas.
 *
 * @param int $id_dashboard_site
 * @return array
 */
function dashboard_chantier_etape_base($chantier) {
	$id_dashboard_site = (int) $chantier['id_dashboard_site'];

	// Remplacer des fichiers ne suffit pas à changer le code en service : PHP
	// garde les fichiers déjà compilés, et l'agent peut annoncer encore
	// l'ancienne version pendant quelques secondes. Migrer le schéma sur cette
	// lecture-là ne ferait rien, en silence, et laisserait le site bloqué.
	$detail = json_decode((string) $chantier['detail'], true);
	$attendue = (string) (is_array($detail) ? ($detail['core_deploye'] ?? '') : '');
	if ($attendue !== '') {
		$vue = dashboard_operation_base_preflight($id_dashboard_site);
		$servie = (string) ($vue['data']['version'] ?? '');
		if ($servie !== '' && $servie !== $attendue) {
			// Une seconde avant de redemander : le cache d'opcode se revalide
			// en quelques secondes, et marteler l'agent épuiserait le compteur
			// d'avancements avant qu'il ait eu le temps de basculer.
			sleep(1);

			return [
				'ok'      => true,
				'rester'  => true,
				'message' => 'Le site sert encore SPIP ' . $servie . ' : attente de la prise en compte de ' . $attendue,
			];
		}
	}

	$reponse = dashboard_operation_base_maj($id_dashboard_site);

	if (empty($reponse['ok'])) {
		return ['ok' => false, 'message' => (string) $reponse['message']];
	}
	if (empty($reponse['termine'])) {
		return ['ok' => true, 'rester' => true, 'message' => (string) $reponse['message']];
	}

	return ['ok' => true, 'message' => (string) $reponse['message']];
}

/**
 * Les plugins à mettre à jour, un par avancement.
 *
 * Un plugin par aller-retour : vingt plugins dans une seule requête, c'est la
 * coupure assurée, et l'impossibilité de dire lequel a échoué.
 *
 * @param array $chantier
 * @return array
 */
function dashboard_chantier_etape_plugins($chantier) {
	$id_site = (int) $chantier['id_dashboard_site'];
	$reste = array_values(array_filter(explode(',', (string) $chantier['reste'])));

	// File vide : c'est le premier passage sur cette étape, il faut la constituer.
	// Une fois le dernier plugin traité, l'étape avance et l'on ne repasse plus
	// ici, donc il n'y a pas de risque de reconstituer une file déjà épuisée.
	if (!$reste) {
		$lignes = sql_allfetsel(
			'prefixe',
			'spip_dashboard_plugins',
			[
				'id_dashboard_site = ' . $id_site,
				'maj_disponible = ' . sql_quote('oui'),
				'distribue = ' . sql_quote('non'),
			],
			'',
			'prefixe'
		);
		$reste = array_column($lignes, 'prefixe');
		if (!$reste) {
			return ['ok' => true, 'message' => 'Aucun plugin à mettre à jour'];
		}
	}

	$prefixe = (string) array_shift($reste);
	$reponse = dashboard_operation_plugin_maj($id_site, $prefixe);
	$message = (string) $reponse['message'];

	// Un plugin récalcitrant n'annule pas les autres, mais il n'est pas non plus
	// passé sous silence : son échec est retenu et figurera au compte rendu.
	$echecs = dashboard_chantier_echecs($chantier);
	if (empty($reponse['ok'])) {
		$echecs[$prefixe] = $message;
	}
	$detail = json_encode(['echecs' => $echecs], JSON_UNESCAPED_UNICODE);

	if ($reste) {
		return [
			'ok'      => true,
			'rester'  => true,
			'reste'   => implode(',', $reste),
			'detail'  => $detail,
			'message' => $message . ' — ' . count($reste) . ' restant(s)',
		];
	}

	return ['ok' => true, 'reste' => '', 'detail' => $detail, 'message' => $message];
}

/**
 * Les échecs déjà retenus au cours d'un chantier.
 *
 * @param array $chantier
 * @return array
 */
function dashboard_chantier_echecs($chantier) {
	$detail = json_decode((string) $chantier['detail'], true);

	return is_array($detail['echecs'] ?? null) ? $detail['echecs'] : [];
}

/**
 * Ce qu'il faut retenir d'un chantier arrivé à son terme.
 *
 * @param array $chantier
 * @return string
 */
function dashboard_chantier_bilan($chantier) {
	$site = sql_fetsel(
		['version_spip', 'nb_plugins_maj', 'base_maj'],
		'spip_dashboard_sites',
		'id_dashboard_site = ' . (int) $chantier['id_dashboard_site']
	);

	$echecs = dashboard_chantier_echecs($chantier);
	$bilan = dashboard_chantier_libelle((string) $chantier['operation'], (string) $chantier['cible'])
		. ($echecs ? ' : terminé, ' . count($echecs) . ' en échec (' . implode(', ', array_keys($echecs)) . ')' : ' : terminé');
	if ($site) {
		$bilan .= ' — SPIP ' . $site['version_spip'];
		if ((string) $site['base_maj'] === 'oui') {
			$bilan .= ', base encore en attente de migration';
		}
		if ((int) $site['nb_plugins_maj'] > 0) {
			$bilan .= ', ' . (int) $site['nb_plugins_maj'] . ' plugin(s) encore à mettre à jour';
		}
	}

	return $bilan;
}

/**
 * Nom lisible d'une opération.
 *
 * @param string $operation
 * @param string $cible
 * @return string
 */
function dashboard_chantier_libelle($operation, $cible = '') {
	$libelles = [
		'plugin_maj'      => 'Mise à jour du plugin ' . $cible,
		'plugin_maj_tous' => 'Mise à jour de tous les plugins',
		'core_maj'        => 'Mise à jour du core SPIP',
		'base_maj'        => 'Migration de la base',
	];

	return $libelles[$operation] ?? $operation;
}

/**
 * Résumé d'un chantier en une ligne.
 *
 * @param array $chantier
 * @return string
 */
function dashboard_chantier_resume($chantier) {
	$etapes = dashboard_chantier_etapes((string) $chantier['operation']);
	$rang   = min((int) $chantier['rang'] + 1, max(1, count($etapes)));

	return dashboard_chantier_libelle((string) $chantier['operation'], (string) $chantier['cible'])
		. ' (étape ' . $rang . '/' . max(1, count($etapes)) . ')';
}

/**
 * Les chantiers laissés en plan, que le cron peut reprendre.
 *
 * @param int $limite
 * @return array
 */
function dashboard_chantiers_abandonnes($limite = 5) {
	return sql_allfetsel(
		'*',
		'spip_dashboard_chantiers',
		[
			sql_in('statut', ['attente', 'encours']),
			'date_etape < ' . sql_quote(date('Y-m-d H:i:s', time() - _DASHBOARD_CHANTIER_ABANDON)),
		],
		'',
		'date_etape',
		'0,' . max(1, (int) $limite)
	);
}
