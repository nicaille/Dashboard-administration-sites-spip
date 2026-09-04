<?php
/**
 * Utilitaires système de l'agent Dashboard : parcours, mesure, copie,
 * suppression et téléchargement de fichiers.
 *
 * @package SPIP\Dashagent\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/**
 * Mesure la taille et le nombre de fichiers d'un répertoire.
 *
 * Le parcours est borné : sur un site avec des centaines de milliers de
 * vignettes, une mesure exacte coûterait plus cher que l'opération demandée.
 * Le drapeau `partiel` indique que la mesure a été interrompue.
 *
 * @param string $dir
 * @param int $max_fichiers
 * @return array{existe: bool, octets: int, fichiers: int, partiel: bool}
 */
function dashagent_mesurer_repertoire($dir, $max_fichiers = 20000) {
	$mesure = ['existe' => false, 'octets' => 0, 'fichiers' => 0, 'partiel' => false];
	if (!is_dir($dir)) {
		return $mesure;
	}
	$mesure['existe'] = true;

	try {
		$iterateur = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ($iterateur as $fichier) {
			if ($mesure['fichiers'] >= $max_fichiers) {
				$mesure['partiel'] = true;
				break;
			}
			if ($fichier->isFile()) {
				$mesure['fichiers']++;
				$mesure['octets'] += (int) $fichier->getSize();
			}
		}
	} catch (Exception $e) {
		$mesure['partiel'] = true;
	}

	return $mesure;
}

/**
 * Supprime récursivement un répertoire (et éventuellement le répertoire lui-même).
 *
 * @param string $dir
 * @param bool $supprimer_racine
 * @return int Nombre de fichiers supprimés
 */
function dashagent_supprimer_repertoire($dir, $supprimer_racine = false) {
	if (!is_dir($dir)) {
		return 0;
	}
	$n = 0;
	$entrees = @scandir($dir);
	if ($entrees === false) {
		return 0;
	}
	foreach ($entrees as $entree) {
		if ($entree === '.' || $entree === '..') {
			continue;
		}
		$chemin = rtrim($dir, '/') . '/' . $entree;
		if (is_dir($chemin) && !is_link($chemin)) {
			$n += dashagent_supprimer_repertoire($chemin, true);
		} elseif (@unlink($chemin)) {
			$n++;
		}
	}
	if ($supprimer_racine) {
		@rmdir($dir);
	}

	return $n;
}

/**
 * Copie récursivement un répertoire.
 *
 * @param string $source
 * @param string $cible
 * @return bool
 */
function dashagent_copier_repertoire($source, $cible) {
	if (!is_dir($source)) {
		return false;
	}
	if (!is_dir($cible) && !@mkdir($cible, 0777, true) && !is_dir($cible)) {
		return false;
	}
	$entrees = @scandir($source);
	if ($entrees === false) {
		return false;
	}
	foreach ($entrees as $entree) {
		if ($entree === '.' || $entree === '..') {
			continue;
		}
		$src = rtrim($source, '/') . '/' . $entree;
		$dst = rtrim($cible, '/') . '/' . $entree;
		if (is_dir($src)) {
			if (!dashagent_copier_repertoire($src, $dst)) {
				return false;
			}
		} elseif (!@copy($src, $dst)) {
			return false;
		}
	}

	return true;
}

/**
 * Télécharge un fichier distant vers un chemin local.
 *
 * Seules les URL https sont acceptées : une mise à jour de code téléchargée en
 * clair serait modifiable en transit, ce qui reviendrait à offrir l'exécution
 * de code arbitraire sur le site géré.
 *
 * @param string $url
 * @param string $destination
 * @param int $taille_max
 * @return array{ok: bool, erreur: string, octets: int}
 */
function dashagent_telecharger($url, $destination, $taille_max = null) {
	$taille_max = $taille_max ?: _DASHAGENT_TAILLE_MAX_ARCHIVE;

	if (!preg_match('#^https://#i', $url)) {
		return ['ok' => false, 'erreur' => 'URL non https refusée', 'octets' => 0];
	}

	include_spip('inc/distant');
	$contenu = recuperer_url($url, [
		'taille_max'   => $taille_max,
		'file'         => $destination,
		'follow_location' => 3,
	]);

	if (!is_array($contenu) || empty($contenu['status']) || $contenu['status'] != 200) {
		$statut = is_array($contenu) ? ($contenu['status'] ?? '?') : '?';
		@unlink($destination);

		return ['ok' => false, 'erreur' => 'Téléchargement échoué (HTTP ' . $statut . ')', 'octets' => 0];
	}

	if (!file_exists($destination) || !filesize($destination)) {
		@unlink($destination);

		return ['ok' => false, 'erreur' => 'Archive vide', 'octets' => 0];
	}

	return ['ok' => true, 'erreur' => '', 'octets' => (int) filesize($destination)];
}

/**
 * Décompresse une archive ZIP dans un répertoire.
 *
 * Les chemins de l'archive sont validés un par un : une entrée contenant
 * `..` ou un chemin absolu permettrait d'écrire n'importe où sur le disque
 * (« zip slip »).
 *
 * @param string $archive
 * @param string $destination
 * @return array{ok: bool, erreur: string, racine: string}
 */
function dashagent_dezipper($archive, $destination) {
	if (!class_exists('ZipArchive')) {
		return ['ok' => false, 'erreur' => 'Extension PHP zip absente', 'racine' => ''];
	}

	$zip = new ZipArchive();
	if ($zip->open($archive) !== true) {
		return ['ok' => false, 'erreur' => 'Archive ZIP illisible', 'racine' => ''];
	}

	$racines = [];
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$nom = $zip->getNameIndex($i);
		if ($nom === false) {
			continue;
		}
		if (strpos($nom, '..') !== false || strpos($nom, "\0") !== false || $nom[0] === '/' || preg_match('#^[a-z]:#i', $nom)) {
			$zip->close();

			return ['ok' => false, 'erreur' => 'Chemin d’archive suspect : ' . $nom, 'racine' => ''];
		}
		$premier = explode('/', trim($nom, '/'))[0];
		if ($premier !== '') {
			$racines[$premier] = true;
		}
	}

	if (!is_dir($destination) && !@mkdir($destination, 0777, true) && !is_dir($destination)) {
		$zip->close();

		return ['ok' => false, 'erreur' => 'Répertoire de destination non créable', 'racine' => ''];
	}

	$ok = $zip->extractTo($destination);
	$zip->close();

	if (!$ok) {
		return ['ok' => false, 'erreur' => 'Extraction impossible (droits ou espace disque)', 'racine' => ''];
	}

	// Une archive de plugin ou de core est normalement enveloppée dans un unique
	// répertoire racine : on le remonte pour simplifier l'appelant.
	$racine = (count($racines) === 1) ? rtrim($destination, '/') . '/' . key($racines) : rtrim($destination, '/');

	return ['ok' => true, 'erreur' => '', 'racine' => $racine];
}

/**
 * Le répertoire est-il inscriptible en profondeur ?
 *
 * @param string $dir
 * @param int $max_verifications
 * @return bool
 */
function dashagent_repertoire_inscriptible($dir, $max_verifications = 200) {
	if (!is_dir($dir) || !is_writable($dir)) {
		return false;
	}
	$n = 0;
	try {
		$iterateur = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($iterateur as $entree) {
			if (++$n > $max_verifications) {
				break;
			}
			if (!is_writable($entree->getPathname())) {
				return false;
			}
		}
	} catch (Exception $e) {
		return false;
	}

	return true;
}

/**
 * Espace disque disponible sur la partition du site, en octets.
 *
 * @return int|null
 */
function dashagent_espace_disque_libre() {
	$octets = @disk_free_space(_DIR_RACINE ?: '.');

	return ($octets === false) ? null : (int) $octets;
}
