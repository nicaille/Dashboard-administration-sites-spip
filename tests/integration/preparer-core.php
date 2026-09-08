<?php
/**
 * Fabrique une archive de core « plus récente » à partir d'une archive SPIP réelle.
 *
 * Deux retouches seulement : la version de branche annoncée, et un témoin dans
 * ecrire/. Le reste est un SPIP authentique, donc le site fonctionne encore une
 * fois l'archive déployée — ce qui est précisément ce que le test vérifie.
 *
 * Usage : php preparer-core.php <archive.zip> <version>
 */

$chemin  = $argv[1] ?? '';
$version = $argv[2] ?? '';

if (!is_file($chemin) || !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
	fwrite(STDERR, "usage : php preparer-core.php <archive.zip> <version>\n");
	exit(2);
}

$zip = new ZipArchive();
if ($zip->open($chemin) !== true) {
	fwrite(STDERR, "archive illisible : $chemin\n");
	exit(1);
}

$source = $zip->getFromName('ecrire/inc_version.php');
if ($source === false) {
	fwrite(STDERR, "ecrire/inc_version.php absent de l'archive\n");
	exit(1);
}

// La même expression que celle dont se sert l'agent pour lire la version d'une
// archive : les deux doivent voir la même chose, sinon le test ne prouve rien.
$motif = '/(spip_version_branche\s*=\s*[\'"])[^\'"]+/';
$patche = preg_replace_callback($motif, function ($m) use ($version) {
	return $m[1] . $version;
}, $source, 1, $n);

if (!$n) {
	fwrite(STDERR, "version de branche introuvable dans inc_version.php\n");
	exit(1);
}
if (!preg_match('/spip_version_branche\s*=\s*[\'"]([^\'"]+)/', $patche, $m) || $m[1] !== $version) {
	fwrite(STDERR, "la version de branche n'a pas été correctement réécrite\n");
	exit(1);
}

$zip->addFromString('ecrire/inc_version.php', $patche);
$zip->addFromString('ecrire/temoin-core.txt', 'archive de test ' . $version);
$zip->close();

echo "ARCHIVE_CORE_PRETE\n";
