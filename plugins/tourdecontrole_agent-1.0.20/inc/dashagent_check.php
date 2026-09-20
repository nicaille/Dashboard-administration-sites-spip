<?php
/**
 * État, dépôt et retrait du `spip_check.php` d'un site géré.
 *
 * SPIP Check est un contrôle d'intégrité ponctuel : déposé à la racine web, il
 * compare les fichiers du site à l'archive officielle de sa version, puis
 * applique des heuristiques aux zones inscriptibles. C'est un outil tiers
 * (git.spip.net/technova69/spip-check, GPL 3) qui s'emploie comme
 * `spip_loader.php` : un fichier autonome, déposé par FTP, ouvert dans le
 * navigateur, et **retiré après usage**.
 *
 * C'est ce dernier point qui sépare les deux, et qui explique `check_retirer` :
 * le `spip_loader.php` a vocation à rester, celui-ci non. Un mégaoctet de code
 * capable de lire tout le disque et de déplacer des fichiers n'a rien à faire
 * en permanence à la racine web, fût-il derrière un contrôle d'accès.
 *
 * L'agent ne le lance pas et n'en lit pas les résultats : SPIP Check s'autorise
 * sur une **session** d'administrateur SPIP, là où l'agent s'authentifie par
 * signature. Contourner cette autorisation pour le piloter à distance
 * reviendrait à poser une porte dérobée sur tout le parc. L'agent se borne donc
 * à ce que le FTP faisait à la main : déposer, dire ce qui est là, retirer.
 *
 * @package SPIP\Dashagent\Check
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Taille maximale acceptée pour un spip_check.
 *
 * Le livrable complet pèse 953 Ko en 2.4.0, l'édition lite 532 Ko — l'écart
 * tient au corpus de signatures d'exploits que la première embarque. La borne
 * laisse de la marge pour qu'il grossisse sans qu'on le refuse.
 */
if (!defined('_DASHAGENT_CHECK_TAILLE_MAX')) {
	define('_DASHAGENT_CHECK_TAILLE_MAX', 8 * 1024 * 1024);
}

/**
 * Combien d'octets relire en fin de fichier pour y trouver la version.
 *
 * L'inverse du spip_loader, dont l'en-tête porte les constantes : ici les
 * bibliothèques embarquées occupent tout le début du fichier, et le code de
 * l'outil — dont `spipCheckToolVersion()` — vient après. En 2.4.0, la fonction
 * est au 854 687ᵉ octet sur 953 235. On relit donc la queue, bornée : charger
 * un mégaoctet en mémoire pour y lire deux nombres n'aurait pas de sens.
 */
if (!defined('_DASHAGENT_CHECK_QUEUE')) {
	define('_DASHAGENT_CHECK_QUEUE', 262144);
}

/**
 * Chemin du spip_check.php à la racine web du site.
 *
 * Le nom à souligné, et non `spip-check.php` : les deux livrables sont
 * strictement identiques, et l'auteur publie la variante à souligné pour les
 * hébergements qui bloquent ou réécrivent les noms de fichier à tiret. Autant
 * déposer d'emblée celui qui passe partout.
 *
 * @return string
 */
function dashagent_check_chemin() {
	return (_DIR_RACINE ?: './') . 'spip_check.php';
}

/**
 * Ce que le site a aujourd'hui, et s'il est possible d'y toucher.
 *
 * @return array
 */
function dashagent_check_etat() {
	$chemin = dashagent_check_chemin();
	$racine = _DIR_RACINE ?: './';

	$etat = [
		'ok'            => true,
		'erreur'        => '',
		'present'       => false,
		'fichier'       => 'spip_check.php',
		'octets'        => 0,
		'modifie'       => '',
		'version'       => '',
		'edition'       => '',
		'racine_ecrit'  => is_dir($racine) && is_writable($racine),
		'fichier_ecrit' => false,
		// SPIP Check n'ouvre son interface qu'à l'auteur nº 1, sauf si un
		// fichier de configuration en désigne d'autres. Le dire ici évite de
		// déposer l'outil pour découvrir au clic qu'on n'y a pas accès.
		'config'        => '',
	];

	foreach (['spip-check_config.php', 'spip_check_config.php', 'spip_loader_config.php'] as $nom) {
		if (@is_file($racine . $nom)) {
			$etat['config'] = $nom;
			break;
		}
	}

	if (!@is_file($chemin)) {
		return $etat;
	}

	$etat['present']       = true;
	$etat['octets']        = (int) @filesize($chemin);
	$etat['modifie']       = date('Y-m-d H:i:s', (int) @filemtime($chemin));
	$etat['fichier_ecrit'] = is_writable($chemin);

	$queue = dashagent_check_queue($chemin, $etat['octets']);
	$etat['version'] = dashagent_check_version($queue);
	$etat['edition'] = dashagent_check_edition($queue);

	return $etat;
}

/**
 * Les derniers octets d'un fichier, sans le charger en entier.
 *
 * @param string $chemin
 * @param int $octets Taille du fichier
 * @return string
 */
function dashagent_check_queue($chemin, $octets) {
	$octets = (int) $octets;
	$depuis = max(0, $octets - _DASHAGENT_CHECK_QUEUE);

	return (string) @file_get_contents($chemin, false, null, $depuis, _DASHAGENT_CHECK_QUEUE);
}

/**
 * Numéro de version annoncé par un spip_check, s'il en annonce un.
 *
 * Le livrable est engendré depuis un gabarit qui pose la version en dur :
 *
 *     function spipCheckToolVersion(): string {
 *         return '2.4.0';
 *     }
 *
 * On lit le corps de cette fonction, et non une occurrence isolée du numéro :
 * un fichier d'un mégaoctet contient quantité de nombres à points.
 *
 * @param string $source Queue du fichier
 * @return string
 */
function dashagent_check_version($source) {
	if (preg_match(
		'/function\s+spipCheckToolVersion\s*\(\s*\)\s*:\s*string\s*\{\s*return\s*[\'"]([0-9][0-9a-z.\-]{0,20})/i',
		(string) $source,
		$m
	)) {
		return $m[1];
	}

	return '';
}

/**
 * L'édition du livrable : `complete` ou `lite`.
 *
 * Les deux portent la même version et ne diffèrent que par le détecteur
 * antimalware embarqué. La distinction compte à l'usage : l'antivirus de
 * certains hébergeurs supprime le livrable complet au dépôt, dont le corpus de
 * signatures décrit du code hostile. Savoir laquelle est en place explique
 * alors un dépôt qui s'évapore.
 *
 * @param string $source
 * @return string
 */
function dashagent_check_edition($source) {
	if (preg_match(
		'/function\s+spipCheckEdition\s*\(\s*\)\s*:\s*string\s*\{\s*return\s*[\'"]([a-z]{1,12})/i',
		(string) $source,
		$m
	)) {
		return strtolower($m[1]);
	}

	return '';
}

/**
 * Ce contenu est-il bien un spip_check ?
 *
 * Même exigence que pour le spip_loader, et pour la même raison : on ne dépose
 * pas à la racine web un fichier PHP quelconque parce qu'une URL le proposait.
 * Ici la marque est la fonction que le gabarit engendre — elle ne se trouve
 * dans aucun autre fichier.
 *
 * @param string $source
 * @return array{ok: bool, erreur: string}
 */
function dashagent_check_conforme($source) {
	$source = (string) $source;

	if ($source === '') {
		return ['ok' => false, 'erreur' => 'Fichier vide'];
	}
	if (strlen($source) > _DASHAGENT_CHECK_TAILLE_MAX) {
		return ['ok' => false, 'erreur' => 'Fichier trop volumineux pour un spip_check ('
			. strlen($source) . ' octets)'];
	}
	if (strncmp(ltrim($source), '<?php', 5) !== 0) {
		return ['ok' => false, 'erreur' => 'Ce n’est pas un fichier PHP'];
	}
	if (dashagent_check_version(substr($source, -_DASHAGENT_CHECK_QUEUE)) === '') {
		return ['ok' => false, 'erreur' => 'Le contenu téléchargé ne ressemble pas à un spip_check'];
	}

	return ['ok' => true, 'erreur' => ''];
}

/**
 * Télécharge et dépose un spip_check neuf à la racine du site.
 *
 * @param array $args
 *     - string `url` : adresse du livrable, https obligatoire
 * @return array
 */
function dashagent_check_maj($args = []) {
	include_spip('inc/dashagent');
	include_spip('inc/dashagent_fs');

	$url = trim((string) ($args['url'] ?? ''));
	if ($url === '') {
		return ['ok' => false, 'erreur' => 'Aucune adresse de téléchargement indiquée'];
	}
	if (!dashagent_url_archive_acceptable($url)) {
		return ['ok' => false, 'erreur' => 'URL non https refusée : ' . $url];
	}

	$etat = dashagent_check_etat();
	if (!$etat['racine_ecrit']) {
		return ['ok' => false, 'erreur' => 'La racine du site n’est pas inscriptible', 'etat' => $etat];
	}
	if ($etat['present'] && empty($etat['fichier_ecrit'])) {
		return ['ok' => false, 'erreur' => 'spip_check.php existe et n’est pas réinscriptible', 'etat' => $etat];
	}

	$travail = dashagent_dir_travail();
	if (!$travail) {
		return ['ok' => false, 'erreur' => 'Répertoire de travail indisponible'];
	}

	$provisoire = $travail . 'check-' . bin2hex(random_bytes(6)) . '.php';
	$dl = dashagent_telecharger($url, $provisoire, _DASHAGENT_CHECK_TAILLE_MAX);
	if (!$dl['ok']) {
		return ['ok' => false, 'erreur' => $dl['erreur'], 'etat' => $etat];
	}

	$source = (string) @file_get_contents($provisoire);
	$controle = dashagent_check_conforme($source);
	if (!$controle['ok']) {
		@unlink($provisoire);

		return ['ok' => false, 'erreur' => $controle['erreur'], 'etat' => $etat];
	}

	$chemin = dashagent_check_chemin();
	$sauvegarde = '';
	if ($etat['present']) {
		$sauvegarde = dashagent_check_ecarter($chemin);
		if ($sauvegarde === '') {
			@unlink($provisoire);

			return ['ok' => false, 'erreur' => 'Impossible de mettre l’ancien spip_check de côté', 'etat' => $etat];
		}
	}

	if (!@rename($provisoire, $chemin)) {
		if ($sauvegarde !== '') {
			@rename($sauvegarde, $chemin);
		}
		@unlink($provisoire);

		return ['ok' => false, 'erreur' => 'Impossible d’écrire le nouveau spip_check', 'etat' => $etat];
	}
	@chmod($chemin, 0644);

	$apres = dashagent_check_etat();

	return [
		'ok'            => true,
		'erreur'        => '',
		'url'           => $url,
		'octets'        => $apres['octets'],
		'sha256'        => hash_file('sha256', $chemin),
		'version_avant' => $etat['version'],
		'version_apres' => $apres['version'],
		'edition'       => $apres['edition'],
		'sauvegarde'    => $sauvegarde !== '' ? basename($sauvegarde) : '',
		'etat'          => $apres,
	];
}

/**
 * Retire le spip_check de la racine du site.
 *
 * Par renommage, jamais par suppression — comme partout ailleurs ici. Le point
 * initial le soustrait au balayage de SPIP comme aux regards, et surtout le
 * rend inappelable : c'est bien le but. Le retour arrière reste possible tant
 * que la tâche d'entretien ne l'a pas effacé.
 *
 * Le fichier de configuration éventuel n'est pas touché : il ne contient que
 * des identifiants d'auteurs, il ne s'exécute pas tout seul, et il sert aussi
 * au spip_loader. L'effacer surprendrait.
 *
 * @return array
 */
function dashagent_check_retirer() {
	$etat = dashagent_check_etat();
	if (!$etat['present']) {
		return ['ok' => true, 'erreur' => '', 'retire' => '', 'etat' => $etat];
	}

	$chemin = dashagent_check_chemin();
	$ecarte = dashagent_check_ecarter($chemin);
	if ($ecarte === '') {
		return ['ok' => false, 'erreur' => 'Impossible de retirer spip_check.php', 'etat' => $etat];
	}

	return [
		'ok'      => true,
		'erreur'  => '',
		'retire'  => basename($ecarte),
		'version' => $etat['version'],
		'etat'    => dashagent_check_etat(),
	];
}

/**
 * Met un fichier de côté sous un nom caché et daté.
 *
 * @param string $chemin
 * @return string Le nouveau chemin, ou '' si le renommage a échoué
 */
function dashagent_check_ecarter($chemin) {
	$ecarte = dirname($chemin) . '/.spip_check.php.dashagent-' . date('YmdHis');

	return @rename($chemin, $ecarte) ? $ecarte : '';
}
