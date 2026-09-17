<?php
/**
 * État et renouvellement du `spip_loader.php` d'un site géré.
 *
 * `spip_loader.php` est le script d'installation et de mise à jour de SPIP :
 * déposé à la racine web, il télécharge et déploie une version du core. Il vit
 * donc **hors** de l'arborescence que remplace une mise à jour de core, et
 * vieillit de son côté sans que rien ne le signale.
 *
 * C'est aussi le fichier le plus dangereux du site : un `spip_loader.php` que
 * n'importe qui peut appeler installe ce qu'il veut. D'où une autorisation à
 * part, refusée par défaut, et un contrôle du contenu téléchargé avant de
 * l'écrire — on ne dépose pas à la racine web un fichier PHP arbitraire parce
 * qu'une URL le proposait.
 *
 * @package SPIP\Dashagent\Loader
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Taille maximale acceptée pour un spip_loader.
 *
 * Le fichier officiel pèse quelques dizaines de kilo-octets ; au-delà d'un
 * mégaoctet, ce n'est pas lui.
 */
if (!defined('_DASHAGENT_LOADER_TAILLE_MAX')) {
	define('_DASHAGENT_LOADER_TAILLE_MAX', 1024 * 1024);
}

/**
 * Adresse officielle du script.
 */
if (!defined('_DASHAGENT_LOADER_URL')) {
	define('_DASHAGENT_LOADER_URL', 'https://get.spip.net/spip_loader.php');
}

/**
 * Chemin du spip_loader.php à la racine web du site.
 *
 * @return string
 */
function dashagent_loader_chemin() {
	return (_DIR_RACINE ?: './') . 'spip_loader.php';
}

/**
 * Ce que le site a aujourd'hui, et s'il est possible d'y toucher.
 *
 * @return array
 */
function dashagent_loader_etat() {
	$chemin = dashagent_loader_chemin();
	$racine = _DIR_RACINE ?: './';

	$etat = [
		'ok'            => true,
		'erreur'        => '',
		'present'       => false,
		'fichier'       => 'spip_loader.php',
		'octets'        => 0,
		'modifie'       => '',
		'version'       => '',
		'racine_ecrit'  => is_dir($racine) && is_writable($racine),
	];

	if (!@is_file($chemin)) {
		return $etat;
	}

	$etat['present'] = true;
	$etat['octets']  = (int) @filesize($chemin);
	$etat['modifie'] = date('Y-m-d H:i:s', (int) @filemtime($chemin));
	$etat['version'] = dashagent_loader_version((string) @file_get_contents($chemin, false, null, 0, 65536));
	// Un fichier présent mais non réinscriptible bloquerait le remplacement : le
	// dire ici évite de ne le découvrir qu'au moment d'écrire.
	$etat['fichier_ecrit'] = is_writable($chemin);

	return $etat;
}

/**
 * Numéro de version annoncé par un spip_loader, s'il en annonce un.
 *
 * @param string $source
 * @return string
 */
function dashagent_loader_version($source) {
	$source = (string) $source;
	foreach ([
		'/\$spip_loader_version\s*=\s*[\'"]([0-9][0-9a-z.\-]{0,20})/i',
		'/define\s*\(\s*[\'"]_?SPIP_LOADER_VERSION[\'"]\s*,\s*[\'"]([0-9][0-9a-z.\-]{0,20})/i',
		'/spip_loader\s+v?([0-9]+\.[0-9][0-9a-z.\-]{0,10})/i',
	] as $motif) {
		if (preg_match($motif, $source, $m)) {
			return $m[1];
		}
	}

	return '';
}

/**
 * Ce contenu est-il bien un spip_loader ?
 *
 * Trois exigences : du PHP, pas trop volumineux, et des marques qui ne se
 * trouvent que dans ce script. Sans ce contrôle, la moindre erreur d'adresse —
 * ou un dépôt détourné — déposerait à la racine web un fichier PHP quelconque,
 * appelable par le premier venu.
 *
 * @param string $source
 * @return array{ok: bool, erreur: string}
 */
function dashagent_loader_conforme($source) {
	$source = (string) $source;

	if ($source === '') {
		return ['ok' => false, 'erreur' => 'Fichier vide'];
	}
	if (strlen($source) > _DASHAGENT_LOADER_TAILLE_MAX) {
		return ['ok' => false, 'erreur' => 'Fichier trop volumineux pour un spip_loader ('
			. strlen($source) . ' octets)'];
	}
	if (strncmp(ltrim($source), '<?php', 5) !== 0) {
		return ['ok' => false, 'erreur' => 'Ce n’est pas un fichier PHP'];
	}

	// Le script se nomme lui-même, et parle de SPIP : deux marques présentes
	// dans toutes ses versions, et qu'un fichier étranger n'aurait pas les deux.
	$marques = 0;
	foreach (['spip_loader', 'SPIP'] as $marque) {
		if (stripos($source, $marque) !== false) {
			$marques++;
		}
	}
	if ($marques < 2) {
		return ['ok' => false, 'erreur' => 'Le contenu téléchargé ne ressemble pas à un spip_loader'];
	}

	return ['ok' => true, 'erreur' => ''];
}

/**
 * Télécharge et dépose un spip_loader neuf à la racine du site.
 *
 * L'ancien est conservé sous un nom caché, comme pour le core et les plugins :
 * un retour arrière reste possible tant que la tâche d'entretien ne l'a pas
 * effacé.
 *
 * @param array $args
 *     - string `url` : adresse du script, https obligatoire
 * @return array
 */
function dashagent_loader_maj($args = []) {
	include_spip('inc/dashagent');
	include_spip('inc/dashagent_fs');

	$url = trim((string) ($args['url'] ?? ''));
	if ($url === '') {
		$url = _DASHAGENT_LOADER_URL;
	}
	if (!dashagent_url_archive_acceptable($url)) {
		return ['ok' => false, 'erreur' => 'URL non https refusée : ' . $url];
	}

	$etat = dashagent_loader_etat();
	if (!$etat['racine_ecrit']) {
		return ['ok' => false, 'erreur' => 'La racine du site n’est pas inscriptible', 'etat' => $etat];
	}
	if ($etat['present'] && empty($etat['fichier_ecrit'])) {
		return ['ok' => false, 'erreur' => 'spip_loader.php existe et n’est pas réinscriptible', 'etat' => $etat];
	}

	$travail = dashagent_dir_travail();
	if (!$travail) {
		return ['ok' => false, 'erreur' => 'Répertoire de travail indisponible'];
	}

	$provisoire = $travail . 'loader-' . bin2hex(random_bytes(6)) . '.php';
	$dl = dashagent_telecharger($url, $provisoire, _DASHAGENT_LOADER_TAILLE_MAX);
	if (!$dl['ok']) {
		return ['ok' => false, 'erreur' => $dl['erreur'], 'etat' => $etat];
	}

	$source = (string) @file_get_contents($provisoire);
	$controle = dashagent_loader_conforme($source);
	if (!$controle['ok']) {
		@unlink($provisoire);

		return ['ok' => false, 'erreur' => $controle['erreur'], 'etat' => $etat];
	}

	$chemin = dashagent_loader_chemin();
	$sauvegarde = '';
	if ($etat['present']) {
		// Le point initial le soustrait au balayage de SPIP comme aux regards :
		// ce n'est pas un fichier qu'on veut laisser appelable sous un autre nom.
		$sauvegarde = dirname($chemin) . '/.spip_loader.php.dashagent-' . date('YmdHis');
		if (!@rename($chemin, $sauvegarde)) {
			@unlink($provisoire);

			return ['ok' => false, 'erreur' => 'Impossible de mettre l’ancien spip_loader de côté', 'etat' => $etat];
		}
	}

	if (!@rename($provisoire, $chemin)) {
		// Remettre l'ancien : mieux vaut le fichier d'hier que pas de fichier.
		if ($sauvegarde !== '') {
			@rename($sauvegarde, $chemin);
		}
		@unlink($provisoire);

		return ['ok' => false, 'erreur' => 'Impossible d’écrire le nouveau spip_loader', 'etat' => $etat];
	}
	@chmod($chemin, 0644);

	$apres = dashagent_loader_etat();

	return [
		'ok'             => true,
		'erreur'         => '',
		'url'            => $url,
		'octets'         => $apres['octets'],
		'sha256'         => hash_file('sha256', $chemin),
		'version_avant'  => $etat['version'],
		'version_apres'  => $apres['version'],
		'sauvegarde'     => $sauvegarde !== '' ? basename($sauvegarde) : '',
		'etat'           => $apres,
	];
}
