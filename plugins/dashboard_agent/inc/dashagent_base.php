<?php
/**
 * Mise à jour du schéma de base du core SPIP, à distance.
 *
 * Remplacer les fichiers du noyau ne suffit pas : quand une version fait
 * évoluer le schéma, SPIP attend que `maj_base()` soit jouée, et bloque
 * l'espace privé derrière un bouton tant que ce n'est pas fait. Cette page-là
 * n'est pas atteignable depuis le tableau de bord, d'où ce module.
 *
 * @package SPIP\Dashagent\Base
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Budget de temps, en secondes, accordé à une tranche de migration.
 *
 * La migration reprend là où elle s'est arrêtée : SPIP écrit la meta
 * `version_installee` après chaque palier. Découper en tranches courtes vaut
 * donc mieux qu'une seule requête interminable, qu'un hébergeur coupera au
 * milieu sans rien nous dire.
 */
if (!defined('_DASHAGENT_BASE_BUDGET')) {
	define('_DASHAGENT_BASE_BUDGET', 25);
}

/**
 * Où en est le schéma de base de ce site ?
 *
 * Deux nombres : celui que les fichiers attendent (`spip_version_base`, dans
 * `ecrire/inc_version.php`) et celui que la base contient (meta
 * `version_installee`). SPIP considère qu'une migration est due dès qu'ils
 * diffèrent — y compris à la baisse, qui signale une base plus récente que les
 * fichiers et qu'il ne faut surtout pas migrer.
 *
 * @return array
 */
function dashagent_base_etat() {
	$attendue = (string) ($GLOBALS['spip_version_base'] ?? '');
	$installee = (string) ($GLOBALS['meta']['version_installee'] ?? '');
	$comparable = str_replace(',', '.', $installee);

	return [
		// La version de branche que ce PHP exécute *en ce moment*. Après un
		// remplacement de fichiers, elle peut encore être l'ancienne : c'est ce
		// qui permet à l'appelant de savoir s'il regarde du code à jour.
		'version'               => (string) ($GLOBALS['spip_version_branche'] ?? ''),
		'version_base'          => $installee,
		'version_base_attendue' => $attendue,
		'maj_requise'           => ($attendue !== '' && $attendue != $comparable),
		// Fichiers plus anciens que la base : une double installation, ou un
		// retour arrière. Migrer aggraverait les choses.
		'base_plus_recente'     => ($attendue !== '' && $comparable !== '' && $attendue < $comparable),
	];
}

/**
 * Contrôles préalables à la migration du schéma, sans rien modifier.
 *
 * @return array
 */
function dashagent_base_preflight() {
	$etat = dashagent_base_etat();

	$bloquant = $etat['base_plus_recente']
		? 'La base est en version ' . $etat['version_base'] . ', plus récente que les fichiers ('
			. $etat['version_base_attendue'] . ') : mettez d’abord le core à jour.'
		: '';

	return array_merge($etat, [
		'ok'     => ($bloquant === ''),
		'erreur' => $bloquant,
	]);
}

/**
 * Joue une tranche de la migration du schéma de base.
 *
 * `maj_base()` est écrite pour une page d'administration : elle écrit du HTML
 * au fil de l'eau, et sur dépassement du temps imparti elle appelle
 * `relance_maj()`, qui affiche un formulaire de redirection puis `exit()`.
 * Ici, la sortie est capturée et la sortie prématurée rattrapée à l'extinction
 * du script, de sorte que l'appelant reçoive du JSON dans tous les cas — et
 * sache s'il doit rappeler.
 *
 * @param array $args
 *     - int `budget` : secondes accordées à cette tranche
 * @return array
 */
function dashagent_base_maj($args = []) {
	$etat = dashagent_base_preflight();
	if (!$etat['ok']) {
		return ['ok' => false, 'erreur' => $etat['erreur'], 'etat' => $etat];
	}
	if (!$etat['maj_requise']) {
		return array_merge(['ok' => true, 'erreur' => '', 'termine' => true, 'journal' => ''], $etat);
	}

	$budget = (int) ($args['budget'] ?? _DASHAGENT_BASE_BUDGET);
	$budget = max(5, min(120, $budget));
	@set_time_limit($budget * 4);

	// `base/upgrade.php` fige ces deux constantes à l'inclusion et `maj_while()`
	// s'en sert pour décider quand rendre la main : les poser d'abord, c'est
	// choisir nous-mêmes la durée d'une tranche.
	if (!defined('_UPGRADE_TIME_OUT')) {
		define('_UPGRADE_TIME_OUT', $budget);
	}
	if (!defined('_TIME_OUT')) {
		define('_TIME_OUT', time() + $budget);
	}

	include_spip('inc/meta');
	include_spip('base/create');
	include_spip('base/upgrade');

	$avant = (string) ($GLOBALS['meta']['version_installee'] ?? '');

	// `relance_maj()` sort du script sans rien nous rendre. Le rattraper ici est
	// le seul moyen de répondre « à poursuivre » plutôt que de laisser le
	// tableau de bord devant une réponse tronquée.
	$rendu = false;
	$niveau_entree = ob_get_level();
	register_shutdown_function(function () use (&$rendu, $avant, $niveau_entree) {
		if ($rendu) {
			return;
		}
		$sortie = dashagent_base_vider_tampons($niveau_entree);
		include_spip('inc/meta');
		lire_metas();
		$apres = (string) ($GLOBALS['meta']['version_installee'] ?? '');
		dashagent_repondre([
			'data' => [
				'ok'       => true,
				'erreur'   => '',
				'termine'  => false,
				'interrompu' => true,
				'progresse' => ($apres !== $avant),
				'journal'  => dashagent_base_journal_lisible($sortie),
			] + dashagent_base_etat(),
			'duree_ms' => 0,
		]);
	});

	$niveau = ob_get_level();
	ob_start();
	$echec = null;
	try {
		// SPIP recrée d'abord les tables éventuellement disparues, exactement
		// comme le fait `base_upgrade_dist()` avant d'appeler `maj_base()`.
		creer_base();
		// Troisième argument à false : sans lui, `maj_debut_page()` ouvre une
		// page de progression HTML et appelle `ob_flush()`, ce qui expédie le
		// tout au client par-dessus notre tampon — la réponse n'est plus du JSON.
		$resultat = maj_base(0, '', false);
	} catch (Throwable $e) {
		$echec = $e->getMessage();
		$resultat = false;
	}
	$sortie = dashagent_base_vider_tampons($niveau);
	$rendu = true;

	lire_metas();
	$apres = (string) ($GLOBALS['meta']['version_installee'] ?? '');

	if ($echec !== null) {
		return [
			'ok'      => false,
			'erreur'  => 'Migration interrompue : ' . $echec,
			'journal' => dashagent_base_journal_lisible($sortie),
		] + dashagent_base_etat();
	}

	// `maj_base()` rend un tableau non vide quand une étape a échoué, et true
	// quand la base ne supporte pas les ALTER nécessaires.
	if ($resultat) {
		$detail = is_array($resultat) ? implode(' ', array_map('strval', $resultat)) : 'ALTER TABLE indisponible sur cette base';

		return [
			'ok'      => false,
			'erreur'  => 'Migration en échec : ' . $detail,
			'journal' => dashagent_base_journal_lisible($sortie),
		] + dashagent_base_etat();
	}

	$etat = dashagent_base_etat();
	if (!$etat['maj_requise']) {
		$sortie .= dashagent_base_terminer();
	}

	return [
		'ok'        => true,
		'erreur'    => '',
		'termine'   => !$etat['maj_requise'],
		'progresse' => ($apres !== $avant),
		'journal'   => dashagent_base_journal_lisible($sortie),
	] + $etat;
}

/**
 * Remise en cohérence une fois le schéma à jour.
 *
 * Reprend la seconde moitié de `base_upgrade_dist()` : les caches calculés à
 * partir de l'ancien schéma sont faux, et la configuration doit être relue.
 *
 * @return string Ce que SPIP a pu écrire au passage
 */
function dashagent_base_terminer() {
	include_spip('inc/flock');
	$niveau = ob_get_level();
	ob_start();

	foreach (['_CACHE_RUBRIQUES', '_CACHE_PIPELINES', '_CACHE_PLUGINS_PATH',
		'_CACHE_PLUGINS_OPT', '_CACHE_PLUGINS_FCT', '_CACHE_CHEMIN'] as $constante) {
		if (defined($constante) && constant($constante)) {
			@spip_unlink(constant($constante));
		}
	}

	include_spip('inc/auth');
	if (function_exists('auth_synchroniser_distant')) {
		auth_synchroniser_distant();
	}
	$config = charger_fonction('config', 'inc', true);
	if ($config) {
		$config();
	}

	return dashagent_base_vider_tampons($niveau);
}

/**
 * Referme les tampons de sortie ouverts depuis un niveau donné.
 *
 * Borné à ce niveau, et pas à zéro : SPIP peut avoir ouvert un tampon bien
 * avant nous, et le refermer à sa place rendrait la réponse incohérente.
 *
 * @param int $niveau Niveau d'imbrication à retrouver
 * @return string
 */
function dashagent_base_vider_tampons($niveau = 0) {
	$sortie = '';
	while (ob_get_level() > max(0, (int) $niveau)) {
		$morceau = ob_get_clean();
		if ($morceau === false) {
			break;
		}
		$sortie = $morceau . $sortie;
	}

	return $sortie;
}

/**
 * Transforme le HTML craché par la migration en quelques lignes lisibles.
 *
 * Ce qui intéresse l'administrateur tient dans les « MAJ <version> » que SPIP
 * égrène ; le reste est de l'habillage de page d'installation.
 *
 * @param string $html
 * @return string
 */
function dashagent_base_journal_lisible($html) {
	$texte = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $html);
	$texte = preg_replace('#<br\s*/?>|</p>|</div>|</li>#i', "\n", (string) $texte);
	$texte = trim(preg_replace('/[ \t]*\n[ \t\n]*/', "\n", html_entity_decode(strip_tags((string) $texte), ENT_QUOTES, 'UTF-8')));

	$lignes = [];
	foreach (explode("\n", $texte) as $ligne) {
		$ligne = trim(preg_replace('/\s+/', ' ', $ligne));
		if ($ligne === '') {
			continue;
		}
		// `relance_maj()` termine en écrivant un formulaire de redirection
		// (« HTTP 302 », « cliquez ici pour continuer »). C'est de la plomberie
		// de navigateur, sans intérêt dans un compte rendu, et toujours en
		// dernier : on coupe là plutôt que de traduire ses phrases.
		if (preg_match('/^HTTP\s+\d{3}$/', $ligne)) {
			break;
		}
		$lignes[] = $ligne;
	}

	// Au-delà de quelques dizaines de paliers, c'est le début et la fin qui
	// racontent ce qui s'est passé.
	if (count($lignes) > 40) {
		$lignes = array_merge(
			array_slice($lignes, 0, 20),
			['… ' . (count($lignes) - 40) . ' ligne(s) omise(s) …'],
			array_slice($lignes, -20)
		);
	}

	return implode("\n", $lignes);
}
