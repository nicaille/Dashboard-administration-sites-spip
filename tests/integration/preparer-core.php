<?php
/**
 * Fabrique une archive de core « plus récente » à partir d'une archive SPIP réelle.
 *
 * Trois retouches : la version de branche annoncée, la version de schéma
 * attendue, et un palier de migration qui ajoute une colonne témoin. Le reste
 * est un SPIP authentique, si bien que le site fonctionne encore une fois
 * l'archive déployée — et que la migration de schéma qui suit le remplacement
 * est une vraie migration, avec un effet vérifiable en base.
 *
 * Usage : php preparer-core.php <archive.zip> <version> <version_base>
 */

$chemin  = $argv[1] ?? '';
$version = $argv[2] ?? '';
$base    = $argv[3] ?? '';

if (!is_file($chemin) || !preg_match('/^\d+\.\d+\.\d+$/', $version) || !preg_match('/^\d{10}$/', $base)) {
	fwrite(STDERR, "usage : php preparer-core.php <archive.zip> <version> <version_base>\n");
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

/**
 * Réécrit une affectation de variable dans le source de `inc_version.php`.
 *
 * @param string $source
 * @param string $variable
 * @param string $valeur
 * @param string $guillemets Délimiteurs, vides pour une valeur numérique
 * @return string
 */
function reecrire_affectation($source, $variable, $valeur, $guillemets = "'") {
	$motif = '/(\$' . preg_quote($variable, '/') . '\s*=\s*)[^;]+;/';
	$remplace = preg_replace_callback($motif, function ($m) use ($valeur, $guillemets) {
		return $m[1] . $guillemets . $valeur . $guillemets . ';';
	}, $source, 1, $n);

	if (!$n) {
		fwrite(STDERR, "affectation introuvable : \$$variable\n");
		exit(1);
	}

	return $remplace;
}

$patche = reecrire_affectation($source, 'spip_version_branche', $version);
$patche = reecrire_affectation($patche, 'spip_version_base', $base, '');

foreach ([['spip_version_branche', $version], ['spip_version_base', $base]] as [$variable, $attendue]) {
	if (!preg_match('/\$' . $variable . '\s*=\s*[\'"]?([^\'";]+)/', $patche, $m) || trim($m[1]) !== $attendue) {
		fwrite(STDERR, "\$$variable n'a pas été correctement réécrite\n");
		exit(1);
	}
}

$zip->addFromString('ecrire/inc_version.php', $patche);
$zip->addFromString('ecrire/temoin-core.txt', 'archive de test ' . $version);

// Un palier de migration qui laisse une trace en base : sans lui, le schéma
// « migrerait » sans rien faire, et le test ne prouverait qu'une écriture de
// meta. La colonne témoin, elle, se constate.
$maj = $zip->getFromName('ecrire/maj/2026.php');
if ($maj === false) {
	fwrite(STDERR, "ecrire/maj/2026.php absent de l'archive\n");
	exit(1);
}
$maj .= "\n\n// Palier ajouté par le test d'intégration.\n"
	. '$GLOBALS[\'maj\'][' . $base . "] = [\n"
	. "\t['sql_alter', \"TABLE spip_jobs ADD temoin_dashboard CHAR(8) NOT NULL DEFAULT ''\"],\n"
	. "];\n";
$zip->addFromString('ecrire/maj/2026.php', $maj);

$zip->close();

echo "ARCHIVE_CORE_PRETE\n";
