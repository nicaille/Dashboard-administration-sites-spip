<?php
/**
 * Web Push : chiffrement RFC 8291 et signature VAPID RFC 8292, en PHP nu.
 *
 * Prévenir quelqu'un qui ne regarde pas la page demande que le navigateur
 * reçoive quelque chose alors qu'il est fermé. C'est ce que fait le Web Push,
 * et c'est aussi le seul chemin vers un téléphone.
 *
 * Le protocole n'a rien d'un POST. Le service de distribution — Google, Mozilla,
 * Apple — relaie sans pouvoir lire : la charge est **chiffrée de bout en bout**
 * pour le navigateur abonné, avec une clef dérivée d'un accord ECDH et d'un
 * secret que lui seul et nous connaissons. Et il faut prouver qu'on est bien
 * l'émetteur déclaré à l'abonnement, par un jeton signé (VAPID).
 *
 * Tout tient dans `ext-openssl` et `hash_hkdf()` : aucune dépendance, aucun
 * Composer. `openssl_pkey_derive()` demande PHP 7.3, le plugin en exige 7.4.
 *
 * Ce fichier ne parle à personne : il fabrique des octets. Les fonctions sont
 * donc toutes pures, à l'exception de celles qui tirent de l'aléa — d'où les
 * paramètres `$sel` et `$pem_emetteur`, qui n'existent que pour rejouer le
 * vecteur d'essai de la RFC 8291 et prouver que le chiffrement est le bon.
 * `dashboard_push_dechiffrer()` est là pour la même raison : une charge qu'on
 * ne sait pas relire est une charge qu'on n'a pas vérifiée.
 *
 * @package SPIP\Dashboard\Inc
 */

if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/** Durée de validité d'un jeton VAPID. La RFC 8292 plafonne à 24 h. */
define('_DASHBOARD_PUSH_JETON_DUREE', 12 * 3600);

/** Taille de l'enregistrement chiffré annoncée dans l'en-tête aes128gcm. */
define('_DASHBOARD_PUSH_RS', 4096);

/**
 * Base64 de l'URL (RFC 4648 §5), sans remplissage.
 *
 * @param string $binaire
 * @return string
 */
function dashboard_push_b64($binaire) {
	return rtrim(strtr(base64_encode((string) $binaire), '+/', '-_'), '=');
}

/**
 * Lecture du base64 de l'URL, remplissage rétabli.
 *
 * @param string $texte
 * @return string Chaîne vide si l'entrée n'est pas lisible
 */
function dashboard_push_deb64($texte) {
	$texte = strtr(trim((string) $texte), '-_', '+/');
	$reste = strlen($texte) % 4;
	if ($reste) {
		$texte .= str_repeat('=', 4 - $reste);
	}
	$binaire = base64_decode($texte, true);

	return ($binaire === false) ? '' : $binaire;
}

/**
 * Enveloppe un bloc DER dans une armure PEM.
 *
 * @param string $der
 * @param string $etiquette
 * @return string
 */
function dashboard_push_pem($der, $etiquette) {
	return "-----BEGIN " . $etiquette . "-----\n"
		. chunk_split(base64_encode((string) $der), 64, "\n")
		. "-----END " . $etiquette . "-----\n";
}

/**
 * Clef publique P-256 à partir de son point brut de 65 octets.
 *
 * Le point non compressé (`04 || X || Y`) est ce que le navigateur publie dans
 * son abonnement. OpenSSL, lui, ne sait lire qu'un SubjectPublicKeyInfo : on
 * l'habille d'un en-tête DER constant, les deux identifiants d'objet
 * (`ecPublicKey`, `prime256v1`) ne variant jamais pour cette courbe.
 *
 * @param string $point 65 octets commençant par 0x04
 * @return \OpenSSLAsymmetricKey|resource|false
 */
function dashboard_push_clef_publique($point) {
	$point = (string) $point;
	if (strlen($point) !== 65 || $point[0] !== "\x04") {
		return false;
	}

	$entete = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');

	return openssl_pkey_get_public(dashboard_push_pem($entete . $point, 'PUBLIC KEY'));
}

/**
 * Clef privée P-256 à partir de son scalaire brut et de son point public.
 *
 * Sert à rejouer un vecteur d'essai, et à reprendre une paire VAPID fabriquée
 * ailleurs — un parc qui migre n'a pas à changer de clef, sous peine de perdre
 * tous ses abonnements d'un coup.
 *
 * @param string $scalaire 32 octets
 * @param string $point 65 octets
 * @return \OpenSSLAsymmetricKey|resource|false
 */
function dashboard_push_clef_privee($scalaire, $point) {
	$scalaire = (string) $scalaire;
	$point    = (string) $point;
	if (strlen($scalaire) !== 32 || strlen($point) !== 65) {
		return false;
	}

	// SEC1 (RFC 5915), en DER : SEQUENCE { INTEGER 1, OCTET STRING d,
	// [0] namedCurve prime256v1, [1] BIT STRING point }. Les longueurs sont
	// fixes pour cette courbe — 0x77 octets en tout — et se posent donc en
	// clair plutôt que de se calculer.
	$der = hex2bin('3077020101' . '0420') . $scalaire
		. hex2bin('a00a06082a8648ce3d030107' . 'a1440342' . '00') . $point;

	return openssl_pkey_get_private(dashboard_push_pem($der, 'EC PRIVATE KEY'));
}

/**
 * Fabrique une paire de clefs VAPID.
 *
 * @return array{pem: string, publique: string, privee: string}|array{} Vide en cas d'échec
 */
function dashboard_push_paire() {
	$clef = openssl_pkey_new([
		'curve_name'       => 'prime256v1',
		'private_key_type' => OPENSSL_KEYTYPE_EC,
	]);
	if (!$clef) {
		return [];
	}

	$pem = '';
	if (!openssl_pkey_export($clef, $pem)) {
		return [];
	}

	$detail = openssl_pkey_get_details($clef);
	if (empty($detail['ec']['x']) || empty($detail['ec']['y']) || empty($detail['ec']['d'])) {
		return [];
	}

	return [
		'pem'      => $pem,
		'publique' => dashboard_push_b64(dashboard_push_point($detail)),
		'privee'   => dashboard_push_b64(str_pad($detail['ec']['d'], 32, "\x00", STR_PAD_LEFT)),
	];
}

/**
 * Point public non compressé d'une clef, tel que le veut le protocole.
 *
 * OpenSSL rend X et Y sans remplissage : une coordonnée dont le premier octet
 * est nul revient sur 31 octets, et concaténer sans compléter décalerait tout
 * le reste. Le défaut ne se produit qu'une fois sur deux cent cinquante-six.
 *
 * @param array $detail Retour de openssl_pkey_get_details()
 * @return string 65 octets
 */
function dashboard_push_point($detail) {
	return "\x04"
		. str_pad((string) ($detail['ec']['x'] ?? ''), 32, "\x00", STR_PAD_LEFT)
		. str_pad((string) ($detail['ec']['y'] ?? ''), 32, "\x00", STR_PAD_LEFT);
}

/**
 * Chiffre une charge pour un abonné, au format `aes128gcm`.
 *
 * Le déroulé de la RFC 8291, dans l'ordre :
 *
 * 1. accord ECDH entre notre clef éphémère et la clef publique du navigateur ;
 * 2. le secret partagé qui en sort est dérivé une première fois **avec le
 *    secret d'authentification** de l'abonnement comme sel, et une information
 *    qui nomme les deux clefs publiques. C'est ce tour-là qui distingue le Web
 *    Push d'un chiffrement ECDH ordinaire : sans le secret d'authentification,
 *    quelqu'un qui intercepterait l'abonnement ne pourrait rien écrire ;
 * 3. de cette clef intermédiaire, la RFC 8188 tire la clef de contenu et le
 *    nonce, avec un sel aléatoire cette fois ;
 * 4. la charge reçoit un octet de délimitation `0x02` — « dernier
 *    enregistrement » — puis passe en AES-128-GCM ;
 * 5. l'en-tête porte le sel, la taille d'enregistrement et **notre clef
 *    publique éphémère**, sans quoi le navigateur ne pourrait pas refaire
 *    l'accord.
 *
 * Le sel et la paire éphémère sont des paramètres pour une seule raison : le
 * vecteur d'essai de la RFC les impose, et c'est ainsi qu'on prouve que ce
 * code produit exactement ce que la RFC annonce. En service, on les laisse
 * vides et ils sont tirés au sort à chaque envoi.
 *
 * @param string $charge Ce qu'on veut faire lire au navigateur
 * @param string $point_abonne Clef publique `p256dh` de l'abonnement, 65 octets bruts
 * @param string $authentification Secret `auth` de l'abonnement, 16 octets bruts
 * @param string $sel 16 octets, tirés au sort si vide
 * @param array $ephemere ['pem' => …] pour rejouer un vecteur, vide sinon
 * @return array{ok: bool, corps: string, message: string}
 */
function dashboard_push_chiffrer($charge, $point_abonne, $authentification, $sel = '', $ephemere = []) {
	$point_abonne     = (string) $point_abonne;
	$authentification = (string) $authentification;

	$publique_abonne = dashboard_push_clef_publique($point_abonne);
	if (!$publique_abonne) {
		return ['ok' => false, 'corps' => '', 'message' => 'Clef publique d’abonnement illisible'];
	}
	if (strlen($authentification) !== 16) {
		return ['ok' => false, 'corps' => '', 'message' => 'Secret d’authentification de taille inattendue'];
	}

	if (!empty($ephemere['pem'])) {
		$clef_locale = openssl_pkey_get_private((string) $ephemere['pem']);
	} else {
		$clef_locale = openssl_pkey_new([
			'curve_name'       => 'prime256v1',
			'private_key_type' => OPENSSL_KEYTYPE_EC,
		]);
	}
	if (!$clef_locale) {
		return ['ok' => false, 'corps' => '', 'message' => 'Clef éphémère impossible à fabriquer'];
	}

	$detail_local = openssl_pkey_get_details($clef_locale);
	$point_local  = dashboard_push_point($detail_local);

	$partage = openssl_pkey_derive($publique_abonne, $clef_locale, 32);
	if (!$partage) {
		return ['ok' => false, 'corps' => '', 'message' => 'Accord ECDH impossible'];
	}

	if ($sel === '') {
		$sel = random_bytes(16);
	}
	if (strlen($sel) !== 16) {
		return ['ok' => false, 'corps' => '', 'message' => 'Sel de taille inattendue'];
	}

	// L'information de dérivation nomme les deux clefs publiques, l'abonné
	// d'abord. Les intervertir donne une clef parfaitement valide que le
	// navigateur n'a aucun moyen de retrouver — et le message est rejeté sans
	// que rien, côté émetteur, n'ait l'air d'avoir échoué.
	$info_clef = 'WebPush: info' . "\x00" . $point_abonne . $point_local;
	$ikm = hash_hkdf('sha256', $partage, 32, $info_clef, $authentification);

	$cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $sel);
	$nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $sel);

	$etiquette = '';
	$chiffre = openssl_encrypt(
		$charge . "\x02",
		'aes-128-gcm',
		$cek,
		OPENSSL_RAW_DATA,
		$nonce,
		$etiquette
	);
	if ($chiffre === false) {
		return ['ok' => false, 'corps' => '', 'message' => 'Chiffrement AES-128-GCM refusé'];
	}

	$entete = $sel . pack('N', _DASHBOARD_PUSH_RS) . chr(strlen($point_local)) . $point_local;

	return ['ok' => true, 'corps' => $entete . $chiffre . $etiquette, 'message' => ''];
}

/**
 * Déchiffre une charge `aes128gcm`, du point de vue de l'abonné.
 *
 * N'a aucun usage en service : c'est le contrôle du chiffrement. Une charge
 * qu'on ne sait pas relire est une charge qu'on n'a pas vérifiée — et un
 * chiffrement faux produit des octets tout aussi plausibles que le bon.
 *
 * @param string $corps Le corps aes128gcm complet
 * @param string $pem_abonne Clef privée de l'abonné, en PEM
 * @param string $authentification Secret `auth`, 16 octets bruts
 * @return array{ok: bool, charge: string, message: string}
 */
function dashboard_push_dechiffrer($corps, $pem_abonne, $authentification) {
	$corps = (string) $corps;
	if (strlen($corps) < 21 + 16) {
		return ['ok' => false, 'charge' => '', 'message' => 'Corps trop court'];
	}

	$sel     = substr($corps, 0, 16);
	$idlen   = ord($corps[20]);
	$point_emetteur = substr($corps, 21, $idlen);
	$reste   = substr($corps, 21 + $idlen);

	if (strlen($reste) < 17) {
		return ['ok' => false, 'charge' => '', 'message' => 'Enregistrement trop court'];
	}
	$etiquette = substr($reste, -16);
	$chiffre   = substr($reste, 0, -16);

	$clef_abonne = openssl_pkey_get_private((string) $pem_abonne);
	if (!$clef_abonne) {
		return ['ok' => false, 'charge' => '', 'message' => 'Clef privée d’abonné illisible'];
	}
	$publique_emetteur = dashboard_push_clef_publique($point_emetteur);
	if (!$publique_emetteur) {
		return ['ok' => false, 'charge' => '', 'message' => 'Clef publique d’émetteur illisible'];
	}

	$partage = openssl_pkey_derive($publique_emetteur, $clef_abonne, 32);
	if (!$partage) {
		return ['ok' => false, 'charge' => '', 'message' => 'Accord ECDH impossible'];
	}

	$detail_abonne = openssl_pkey_get_details($clef_abonne);
	$point_abonne  = dashboard_push_point($detail_abonne);

	$info_clef = 'WebPush: info' . "\x00" . $point_abonne . $point_emetteur;
	$ikm   = hash_hkdf('sha256', $partage, 32, $info_clef, (string) $authentification);
	$cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $sel);
	$nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $sel);

	$clair = openssl_decrypt($chiffre, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $etiquette);
	if ($clair === false) {
		return ['ok' => false, 'charge' => '', 'message' => 'Déchiffrement refusé'];
	}

	// Le remplissage se lit par la fin : on retire les zéros, puis l'octet de
	// délimitation. `rtrim` sur "\x00" est sans risque ici, le délimiteur
	// n'étant jamais nul.
	$clair = rtrim($clair, "\x00");
	$clair = substr($clair, 0, -1);

	return ['ok' => true, 'charge' => (string) $clair, 'message' => ''];
}

/**
 * Convertit une signature ECDSA du DER d'OpenSSL vers le format brut de JOSE.
 *
 * OpenSSL rend `SEQUENCE { INTEGER r, INTEGER s }`, où chaque entier est de
 * longueur variable et porte un octet nul de tête quand son bit de poids fort
 * est armé — sans quoi le DER le lirait comme négatif. JOSE, lui, veut deux
 * nombres de trente-deux octets, collés.
 *
 * Passer le DER tel quel produit une signature que rien ne rejette ici et que
 * tous les services de distribution refusent, avec un « invalid JWT » qui ne
 * dit pas lequel des deux bouts est en cause.
 *
 * @param string $der
 * @return string 64 octets, ou chaîne vide si le DER est mal formé
 */
function dashboard_push_der_vers_brut($der) {
	$der = (string) $der;
	$n = strlen($der);
	if ($n < 8 || $der[0] !== "\x30") {
		return '';
	}

	// Longueur de la séquence : forme courte, ou forme longue sur un octet.
	$i = 1;
	if (ord($der[$i]) & 0x80) {
		$octets = ord($der[$i]) & 0x7f;
		if ($octets !== 1) {
			return '';
		}
		$i += 2;
	} else {
		$i += 1;
	}

	$nombres = [];
	for ($k = 0; $k < 2; $k++) {
		if ($i + 1 >= $n || $der[$i] !== "\x02") {
			return '';
		}
		$taille = ord($der[$i + 1]);
		$i += 2;
		if ($taille < 1 || $i + $taille > $n) {
			return '';
		}
		$valeur = substr($der, $i, $taille);
		$i += $taille;

		$valeur = ltrim($valeur, "\x00");
		if (strlen($valeur) > 32) {
			return '';
		}
		$nombres[] = str_pad($valeur, 32, "\x00", STR_PAD_LEFT);
	}

	return $nombres[0] . $nombres[1];
}

/**
 * Convertit une signature brute de JOSE vers le DER d'OpenSSL.
 *
 * L'inverse de la précédente, pour pouvoir vérifier sa propre signature — le
 * seul contrôle possible sans interroger un service de distribution.
 *
 * @param string $brut 64 octets
 * @return string
 */
function dashboard_push_brut_vers_der($brut) {
	$brut = (string) $brut;
	if (strlen($brut) !== 64) {
		return '';
	}

	$entiers = '';
	foreach ([substr($brut, 0, 32), substr($brut, 32)] as $nombre) {
		$nombre = ltrim($nombre, "\x00");
		if ($nombre === '') {
			$nombre = "\x00";
		}
		// Bit de poids fort armé : un octet nul de tête, sinon le DER lirait
		// un nombre négatif.
		if (ord($nombre[0]) & 0x80) {
			$nombre = "\x00" . $nombre;
		}
		$entiers .= "\x02" . chr(strlen($nombre)) . $nombre;
	}

	return "\x30" . chr(strlen($entiers)) . $entiers;
}

/**
 * L'audience d'un jeton VAPID : l'origine du service de distribution.
 *
 * L'origine, et rien d'autre — pas le chemin de l'abonnement, qui est un
 * secret. Un jeton signé pour `https://fcm.googleapis.com` vaut pour tous les
 * abonnements servis là, et c'est voulu : on n'en signe qu'un par service.
 *
 * @param string $endpoint
 * @return string Chaîne vide si l'adresse est inexploitable
 */
function dashboard_push_audience($endpoint) {
	$morceaux = parse_url((string) $endpoint);
	if (empty($morceaux['scheme']) || empty($morceaux['host'])) {
		return '';
	}

	$audience = $morceaux['scheme'] . '://' . $morceaux['host'];
	if (!empty($morceaux['port'])) {
		$audience .= ':' . (int) $morceaux['port'];
	}

	return $audience;
}

/**
 * Fabrique un jeton VAPID (RFC 8292), signé en ES256.
 *
 * @param string $audience Origine du service de distribution
 * @param string $sujet `mailto:` ou `https:` par quoi nous joindre
 * @param string $pem Clef privée VAPID
 * @param int $expiration Horodatage d'expiration, calculé si nul
 * @return array{ok: bool, jeton: string, message: string}
 */
function dashboard_push_jeton($audience, $sujet, $pem, $expiration = 0) {
	$audience = (string) $audience;
	if ($audience === '') {
		return ['ok' => false, 'jeton' => '', 'message' => 'Audience vide'];
	}

	$clef = openssl_pkey_get_private((string) $pem);
	if (!$clef) {
		return ['ok' => false, 'jeton' => '', 'message' => 'Clef privée VAPID illisible'];
	}

	$entete = ['typ' => 'JWT', 'alg' => 'ES256'];
	$corps  = [
		'aud' => $audience,
		'exp' => $expiration ?: (time() + _DASHBOARD_PUSH_JETON_DUREE),
		'sub' => (string) $sujet,
	];

	$signe = dashboard_push_b64(json_encode($entete, JSON_UNESCAPED_SLASHES))
		. '.' . dashboard_push_b64(json_encode($corps, JSON_UNESCAPED_SLASHES));

	$der = '';
	if (!openssl_sign($signe, $der, $clef, OPENSSL_ALGO_SHA256)) {
		return ['ok' => false, 'jeton' => '', 'message' => 'Signature ES256 refusée'];
	}

	$brut = dashboard_push_der_vers_brut($der);
	if ($brut === '') {
		return ['ok' => false, 'jeton' => '', 'message' => 'Signature DER inexploitable'];
	}

	return ['ok' => true, 'jeton' => $signe . '.' . dashboard_push_b64($brut), 'message' => ''];
}

/**
 * Remet une charge chiffrée au service de distribution.
 *
 * Le verdict reprend la distinction qui gouverne tout le reste du parc — voir
 * `dashboard_silence()` — parce qu'elle s'applique ici mot pour mot :
 *
 * - `remis` : le service a pris la charge (201, parfois 200 ou 202) ;
 * - `perime` : il dit que l'abonnement n'existe plus (404, 410). **C'est une
 *   réponse**, et elle se respecte : l'abonnement se supprime. Le navigateur
 *   en refabrique un au prochain passage dans l'espace privé ;
 * - `refuse` : il refuse pour une autre raison (401 jeton, 413 charge trop
 *   grosse, 429 trop d'envois). Une réponse aussi, mais qui ne dit rien de la
 *   validité de l'abonnement — on garde ;
 * - `silence` : rien n'est revenu. **Ne dit rien de l'issue** : la charge a
 *   très bien pu être remise. On ne supprime pas, on ne conclut pas.
 *
 * Supprimer un abonnement sur un silence reviendrait à débrancher le webmestre
 * parce que le réseau a hoqueté.
 *
 * @param array $abonnement ['endpoint' => …, 'p256dh' => …, 'auth' => …] en base64url
 * @param string $charge Ce que le navigateur lira
 * @param array $vapid ['pem' => …, 'publique' => …, 'sujet' => …]
 * @param array $options ['ttl' => int, 'timeout' => int]
 * @return array{verdict: string, code: int, message: string}
 */
function dashboard_push_envoyer($abonnement, $charge, $vapid, $options = []) {
	$endpoint = (string) ($abonnement['endpoint'] ?? '');
	$audience = dashboard_push_audience($endpoint);
	if ($audience === '') {
		return ['verdict' => 'refuse', 'code' => 0, 'message' => 'Adresse de distribution inexploitable'];
	}

	$chiffre = dashboard_push_chiffrer(
		$charge,
		dashboard_push_deb64((string) ($abonnement['p256dh'] ?? '')),
		dashboard_push_deb64((string) ($abonnement['auth'] ?? ''))
	);
	if (empty($chiffre['ok'])) {
		return ['verdict' => 'refuse', 'code' => 0, 'message' => (string) $chiffre['message']];
	}

	$jeton = dashboard_push_jeton($audience, (string) ($vapid['sujet'] ?? ''), (string) ($vapid['pem'] ?? ''));
	if (empty($jeton['ok'])) {
		return ['verdict' => 'refuse', 'code' => 0, 'message' => (string) $jeton['message']];
	}

	$entetes = [
		'Authorization: vapid t=' . $jeton['jeton'] . ', k=' . (string) ($vapid['publique'] ?? ''),
		'Content-Encoding: aes128gcm',
		'Content-Type: application/octet-stream',
		'TTL: ' . (int) ($options['ttl'] ?? 86400),
	];

	$ch = curl_init($endpoint);
	curl_setopt_array($ch, [
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => $chiffre['corps'],
		CURLOPT_HTTPHEADER     => $entetes,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => (int) ($options['timeout'] ?? 15),
		CURLOPT_CONNECTTIMEOUT => 10,
		// Le service de distribution est un tiers : on vérifie son certificat,
		// et on ne suit aucune redirection — une redirection sur un POST signé
		// n'a pas de sens ici, et rejouerait la charge ailleurs.
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_FOLLOWLOCATION => false,
	]);

	$corps = curl_exec($ch);
	$code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$erreur = curl_error($ch);
	curl_close($ch);

	if ($corps === false || $code === 0) {
		return ['verdict' => 'silence', 'code' => 0, 'message' => $erreur ?: 'sans réponse'];
	}

	return [
		'verdict' => dashboard_push_verdict($code),
		'code'    => $code,
		'message' => dashboard_push_verdict($code) === 'remis'
			? ''
			: trim(substr((string) $corps, 0, 300)),
	];
}

/**
 * Traduit un code HTTP de service de distribution en verdict.
 *
 * Séparée de l'envoi pour pouvoir s'éprouver sans réseau : c'est elle qui
 * décide si un abonnement se supprime, et cette décision-là ne doit pas
 * dépendre d'un service tiers pour être vérifiable.
 *
 * @param int $code
 * @return string remis|perime|refuse|silence
 */
function dashboard_push_verdict($code) {
	$code = (int) $code;

	if ($code === 0) {
		return 'silence';
	}
	// 404 : l'adresse n'existe plus. 410 : elle a été révoquée. Les deux
	// disent la même chose — cet abonnement est mort, et il ne ressuscitera
	// pas. Le navigateur en refabriquera un.
	if ($code === 404 || $code === 410) {
		return 'perime';
	}
	if ($code >= 200 && $code < 300) {
		return 'remis';
	}
	// Une panne du service (500, 502, 503) ne dit rien de l'abonnement : elle
	// se lit comme un silence, et se retentera.
	if ($code >= 500) {
		return 'silence';
	}

	return 'refuse';
}
