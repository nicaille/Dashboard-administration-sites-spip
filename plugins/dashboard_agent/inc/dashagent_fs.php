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

	if (!dashagent_url_archive_acceptable($url)) {
		return ['ok' => false, 'erreur' => 'URL non https refusée : ' . $url, 'octets' => 0];
	}

	include_spip('inc/distant');
	$contenu = recuperer_url($url, [
		'taille_max'   => $taille_max,
		'file'         => $destination,
		'follow_location' => 3,
	]);

	// Un message qui ne nomme ni l'adresse ni la cause ne permet pas de
	// diagnostiquer : recuperer_url() rend false sur échec de connexion, et un
	// simple « HTTP ? » laissait sans piste.
	$adresse = strlen($url) > 120 ? substr($url, 0, 117) . '…' : $url;
	if (!is_array($contenu)) {
		@unlink($destination);

		return ['ok' => false, 'erreur' => dashagent_cause_echec_reseau($url) . ' : ' . $adresse, 'octets' => 0];
	}
	if (empty($contenu['status']) || $contenu['status'] != 200) {
		$statut = $contenu['status'] ?? 0;
		@unlink($destination);

		return [
			'ok'     => false,
			'erreur' => ($statut ? 'HTTP ' . $statut : 'Aucune réponse') . ' sur ' . $adresse,
			'octets' => 0,
		];
	}

	if (!file_exists($destination) || !filesize($destination)) {
		@unlink($destination);

		return ['ok' => false, 'erreur' => 'Archive vide reçue de ' . $adresse, 'octets' => 0];
	}

	return ['ok' => true, 'erreur' => '', 'octets' => (int) filesize($destination)];
}

/**
 * Pourquoi une requête sortante n'a-t-elle pas abouti ?
 *
 * `recuperer_url()` se contente de rendre false. Distinguer un nom d'hôte qui
 * ne résout pas d'un serveur qui refuse la connexion change complètement le
 * diagnostic : dépôt à rafraîchir d'un côté, pare-feu ou proxy de l'autre.
 *
 * @param string $url
 * @return string
 */
function dashagent_cause_echec_reseau($url) {
	$morceaux = parse_url($url);
	$hote = $morceaux['host'] ?? '';
	if (!$hote) {
		return 'URL illisible';
	}

	// gethostbyname() rend le nom inchangé quand la résolution échoue.
	if (!filter_var($hote, FILTER_VALIDATE_IP)
		&& function_exists('gethostbyname')
		&& gethostbyname($hote) === $hote
	) {
		return 'Nom d’hôte ' . $hote . ' introuvable (DNS)';
	}

	// Le nom résout : reste à savoir si c'est le réseau, le pare-feu ou le
	// certificat. Une sonde courte donne le message du système, autrement dit
	// la seule information réellement exploitable.
	$port = (int) ($morceaux['port'] ?? (($morceaux['scheme'] ?? 'https') === 'http' ? 80 : 443));
	$securise = ($morceaux['scheme'] ?? 'https') !== 'http';

	if (!function_exists('stream_socket_client')) {
		return 'Connexion impossible (sonde réseau indisponible)';
	}

	$cible = ($securise ? 'ssl://' : 'tcp://') . $hote . ':' . $port;
	$erreur = '';
	$flux = @stream_socket_client($cible, $code, $erreur,
		5, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['peer_name' => $hote]]));
	if ($flux) {
		fclose($flux);

		return 'Connexion possible mais téléchargement interrompu';
	}
	$erreur = trim(preg_replace('/\s+/', ' ', (string) $erreur));

	// La même connexion sans vérification du certificat : si elle passe, le
	// réseau va bien et c'est la validation TLS qui bloque. Sur beaucoup
	// d'installations locales (WAMP, MAMP, XAMPP) le PHP livré n'a aucun
	// magasin d'autorités, et alors *tous* les téléchargements https échouent,
	// quelle que soit l'adresse. Le message doit le dire, sinon on cherche du
	// côté du dépôt ce qui se règle dans php.ini.
	if ($securise) {
		$sans_verif = @stream_socket_client('ssl://' . $hote . ':' . $port, $code2, $erreur2,
			5, STREAM_CLIENT_CONNECT,
			stream_context_create(['ssl' => [
				'peer_name' => $hote,
				'verify_peer' => false,
				'verify_peer_name' => false,
				'allow_self_signed' => true,
			]]));
		if ($sans_verif) {
			fclose($sans_verif);

			return 'Certificat https non validé par ce serveur : ' . dashagent_conseil_autorites();
		}
	}

	return 'Connexion impossible' . ($erreur !== '' ? ' (' . substr($erreur, 0, 160) . ')' : '');
}

/**
 * Où ce PHP va-t-il chercher les autorités de certification ?
 *
 * php.ini l'emporte, sinon OpenSSL retombe sur les emplacements compilés dans
 * la bibliothèque. Sur les paquets Linux ils existent ; sur les piles locales
 * pour Windows (WAMP, XAMPP, Laragon) ils pointent souvent vers un répertoire
 * absent, et alors aucun téléchargement https n'aboutit.
 *
 * @param array|null $emplacements Emplacements OpenSSL, injectables pour les tests
 * @return array ['ok' => bool, 'source' => string, 'chemin' => string]
 */
function dashagent_magasin_autorites($emplacements = null) {
	if ($emplacements === null) {
		$emplacements = function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations() : [];
	}

	$fichiers = [
		'curl.cainfo'    => (string) ini_get('curl.cainfo'),
		'openssl.cafile' => (string) ini_get('openssl.cafile'),
		'défaut OpenSSL' => (string) ($emplacements['default_cert_file'] ?? ''),
	];
	foreach ($fichiers as $source => $chemin) {
		if ($chemin !== '' && @is_file($chemin)) {
			return ['ok' => true, 'source' => $source, 'chemin' => $chemin];
		}
	}

	$dossiers = [
		'openssl.capath'  => (string) ini_get('openssl.capath'),
		'défaut OpenSSL'  => (string) ($emplacements['default_cert_dir'] ?? ''),
	];
	foreach ($dossiers as $source => $chemin) {
		// Un répertoire vide ne vaut pas mieux qu'un répertoire absent.
		if ($chemin !== '' && @is_dir($chemin) && glob(rtrim($chemin, '/\\') . '/*')) {
			return ['ok' => true, 'source' => $source, 'chemin' => $chemin];
		}
	}

	// Rien d'exploitable : on garde le premier emplacement annoncé, c'est celui
	// que l'administrateur ira vérifier.
	foreach ($fichiers + $dossiers as $source => $chemin) {
		if ($chemin !== '') {
			return ['ok' => false, 'source' => $source, 'chemin' => $chemin];
		}
	}

	return ['ok' => false, 'source' => '', 'chemin' => ''];
}

/**
 * Comment réparer une validation de certificat qui échoue ?
 *
 * Le conseil dépend de ce que ce PHP déclare : sans magasin d'autorités il faut
 * en installer un, avec un magasin annoncé mais absent c'est le chemin qui est
 * faux. Dans les deux cas la réponse tient dans le message d'erreur, là où
 * l'administrateur la lit.
 *
 * @return string
 */
function dashagent_conseil_autorites() {
	$magasin = dashagent_magasin_autorites();
	$remede = 'Téléchargez https://curl.se/ca/cacert.pem, renseignez curl.cainfo et '
		. 'openssl.cafile avec son chemin dans php.ini, puis redémarrez le serveur.';

	if (!$magasin['ok']) {
		return ($magasin['chemin'] !== ''
			? 'le magasin d’autorités annoncé (' . $magasin['source'] . ' = ' . $magasin['chemin'] . ') est introuvable. '
			: 'aucun magasin d’autorités de certification n’est lisible par ce PHP. ')
			. $remede;
	}

	return 'le magasin d’autorités (' . $magasin['source'] . ' : ' . $magasin['chemin']
		. ') est en place : le certificat de ce serveur est probablement expiré, '
		. 'ou un proxy interpose ses propres certificats.';
}


/**
 * L'URL d'archive est-elle téléchargeable ?
 *
 * https par défaut, et uniquement : une archive récupérée en clair peut être
 * altérée en transit, ce qui revient à laisser exécuter du code arbitraire sur
 * le site. Le http n'est toléré que si `mes_options.php` le demande
 * explicitement, pour du développement local.
 *
 * @param string $url
 * @return bool
 */
function dashagent_url_archive_acceptable($url) {
	if (preg_match('#^https://#i', (string) $url)) {
		return true;
	}

	return _DASHAGENT_ARCHIVES_HTTP && preg_match('#^http://#i', (string) $url);
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
