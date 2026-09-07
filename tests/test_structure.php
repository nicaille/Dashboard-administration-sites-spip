<?php
/**
 * Vérifie que les manifestes et les squelettes pointent vers du code qui existe.
 *
 * Ces erreurs-là ne se voient qu'à l'installation ou au premier clic dans
 * l'espace privé : les attraper ici coûte une seconde, les attraper en
 * production coûte un aller-retour.
 *
 * Usage : php tests/test_structure.php
 */

$racine = dirname(__DIR__);
$plugins = [$racine . '/plugins/dashboard', $racine . '/plugins/dashboard_agent'];

$echecs = 0;
$total  = 0;

/**
 * @param string $titre
 * @param bool $condition
 * @return void
 */
function verifier($titre, $condition) {
	global $echecs, $total;
	$total++;
	if ($condition) {
		echo "  ok   $titre\n";
	} else {
		$echecs++;
		echo "  ÉCHEC $titre\n";
	}
}

/**
 * Liste tous les fichiers d'une arborescence portant l'une des extensions données.
 *
 * @param string $racine
 * @param array $extensions
 * @return array
 */
function fichiers($racine, $extensions) {
	$trouves = [];
	$iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
	foreach ($iterateur as $fichier) {
		if ($fichier->isFile() && in_array(strtolower($fichier->getExtension()), $extensions, true)) {
			$trouves[] = $fichier->getPathname();
		}
	}
	sort($trouves);

	return $trouves;
}

/**
 * Balises acceptées par la DTD de paquet.xml, dans leur ordre attendu.
 *
 * `slogan`, `description` et `install` n'en font pas partie : les deux
 * premières vivent dans lang/paquet-<prefixe>_XX.php, la troisième a disparu
 * avec le format plugin.xml.
 */
$balises_paquet = ['nom', 'auteur', 'licence', 'credit', 'traduire', 'pipeline',
	'necessite', 'utilise', 'procure', 'menu', 'onglet', 'genie', 'script'];

foreach ($plugins as $plugin) {
	$nom_court = basename($plugin);
	echo "\n== $nom_court : manifeste ==\n";

	$xml = simplexml_load_file($plugin . '/paquet.xml');
	verifier('paquet.xml bien formé', $xml !== false);
	if ($xml === false) {
		continue;
	}

	$prefixe = (string) $xml['prefix'];
	verifier('attribut prefix présent', $prefixe !== '');

	$rang = -1;
	$ordre_ok = true;
	foreach ($xml->children() as $enfant) {
		$balise = $enfant->getName();
		verifier("balise <$balise> reconnue par la DTD", in_array($balise, $balises_paquet, true));
		$position = array_search($balise, $balises_paquet, true);
		if ($position !== false) {
			if ($position < $rang) {
				$ordre_ok = false;
			}
			$rang = $position;
		}
	}
	verifier('balises dans l’ordre attendu par la DTD', $ordre_ok);

	$logo = (string) $xml['logo'];
	verifier("logo présent sur le disque ($logo)", $logo !== '' && is_file($plugin . '/' . $logo));

	// `schema` déclenche l'installation, trouvée par convention de nommage.
	if ((string) $xml['schema'] !== '') {
		$administrations = $plugin . '/' . $prefixe . '_administrations.php';
		verifier("$prefixe" . '_administrations.php présent', is_file($administrations));
		if (is_file($administrations)) {
			$source = file_get_contents($administrations);
			verifier("fonction {$prefixe}_upgrade()", strpos($source, "function {$prefixe}_upgrade(") !== false);
			verifier("fonction {$prefixe}_vider_tables()", strpos($source, "function {$prefixe}_vider_tables(") !== false);
		}
	}

	echo "\n== $nom_court : pipelines ==\n";
	foreach ($xml->pipeline as $pipeline) {
		$nom     = (string) $pipeline['nom'];
		$inclure = (string) $pipeline['inclure'];
		$action  = (string) $pipeline['action'];
		if ($inclure === '') {
			continue;
		}
		$fichier = $plugin . '/' . $inclure;
		verifier("pipeline $nom : fichier $inclure", is_file($fichier));
		if (is_file($fichier) && $action === '') {
			verifier(
				"pipeline $nom : fonction {$prefixe}_{$nom}()",
				strpos(file_get_contents($fichier), "function {$prefixe}_{$nom}(") !== false
			);
		}
	}

	echo "\n== $nom_court : menus ==\n";
	foreach ($xml->menu as $menu) {
		$nom   = (string) $menu['nom'];
		$icone = (string) $menu['icone'];
		$page  = (string) $menu['action'] ?: $nom;
		if ($icone !== '') {
			// L'attribut icone se résout depuis le thème privé, pas la racine du plugin.
			verifier("menu $nom : icône prive/themes/spip/$icone", is_file($plugin . '/prive/themes/spip/' . $icone));
		}
		verifier("menu $nom : page prive/squelettes/contenu/$page.html", is_file($plugin . "/prive/squelettes/contenu/$page.html"));
	}

	echo "\n== $nom_court : squelettes ==\n";
	foreach (fichiers($plugin, ['html']) as $squelette) {
		$source  = file_get_contents($squelette);
		$affiche = str_replace($plugin . '/', '', $squelette);

		if (preg_match_all('/#URL_ACTION_AUTEUR\{([a-z0-9_]+)/', $source, $trouves)) {
			foreach (array_unique($trouves[1]) as $action) {
				$fichier = $plugin . "/action/$action.php";
				verifier("$affiche : action/$action.php", is_file($fichier));
				if (is_file($fichier)) {
					verifier(
						"$affiche : action_{$action}_dist()",
						strpos(file_get_contents($fichier), "function action_{$action}_dist(") !== false
					);
				}
			}
		}

		if (preg_match_all('/#FORMULAIRE_([A-Z0-9_]+)/', $source, $trouves)) {
			foreach (array_unique($trouves[1]) as $formulaire) {
				$base = strtolower($formulaire);
				verifier("$affiche : formulaires/$base.php", is_file($plugin . "/formulaires/$base.php"));
				verifier("$affiche : formulaires/$base.html", is_file($plugin . "/formulaires/$base.html"));
			}
		}
	}

	echo "\n== $nom_court : tâches périodiques ==\n";
	foreach (fichiers($plugin, ['php']) as $fichier) {
		if (!preg_match_all('/\$taches\[\'([a-z0-9_]+)\'\]/', file_get_contents($fichier), $trouves)) {
			continue;
		}
		foreach (array_unique($trouves[1]) as $tache) {
			$genie = $plugin . "/genie/$tache.php";
			verifier("tâche $tache : genie/$tache.php", is_file($genie));
			if (is_file($genie)) {
				verifier(
					"tâche $tache : genie_{$tache}_dist()",
					strpos(file_get_contents($genie), "function genie_{$tache}_dist(") !== false
				);
			}
		}
	}

	echo "\n== $nom_court : langue ==\n";
	verifier(
		"lang/paquet-$prefixe" . '_fr.php présent',
		is_file($plugin . "/lang/paquet-$prefixe" . '_fr.php')
	);
	foreach (glob($plugin . '/lang/*_fr.php') as $reference) {
		$module = basename($reference, '_fr.php');
		$traduction = $plugin . "/lang/$module" . '_en.php';
		if (!is_file($traduction)) {
			continue;
		}
		preg_match_all("/^\t'([a-z0-9_]+)'\s*=>/m", file_get_contents($reference), $a);
		preg_match_all("/^\t'([a-z0-9_]+)'\s*=>/m", file_get_contents($traduction), $b);
		verifier("$module : traduction anglaise complète", !array_diff($a[1], $b[1]));
		verifier("$module : pas de clef surnuméraire en anglais", !array_diff($b[1], $a[1]));
	}
}

echo "\n== Cohérence installation / déclaration des tables ==\n";

foreach ($plugins as $plugin) {
	$nom_court = basename($plugin);
	$xml       = simplexml_load_file($plugin . '/paquet.xml');
	$prefixe   = (string) $xml['prefix'];
	$schema    = (string) $xml['schema'];
	$administrations = $plugin . '/' . $prefixe . '_administrations.php';
	if ($schema === '' || !is_file($administrations)) {
		continue;
	}
	$install = file_get_contents($administrations);

	$declarees = [];
	$base = '';
	foreach (glob($plugin . '/base/*.php') as $fichier) {
		$source = file_get_contents($fichier);
		$base .= $source;
		preg_match_all('/\$tables\[\'(spip_[a-z0-9_]+)\'\]/', $source, $trouves);
		$declarees = array_merge($declarees, $trouves[1]);
	}
	$declarees = array_unique($declarees);
	verifier("$nom_court : des tables sont déclarées", (bool) $declarees);

	// Les descripteurs doivent être accessibles sans passer par le registre de
	// SPIP : c'est ce qui permet à l'installation de se vérifier elle-même.
	verifier(
		"$nom_court : {$prefixe}_descriptions_tables() expose les descripteurs",
		strpos($base, "function {$prefixe}_descriptions_tables(") !== false
	);
	verifier(
		"$nom_court : l’installation appelle {$prefixe}_creer_tables",
		strpos($install, "{$prefixe}_creer_tables") !== false
	);
	verifier(
		"$nom_court : la création contrôle son résultat",
		strpos($install, "{$prefixe}_tables_manquantes(") !== false
	);

	foreach ($declarees as $table) {
		verifier("$nom_court : $table supprimée par la désinstallation", strpos($install, "sql_drop_table('$table')") !== false);

		// Sans correspondance nom de boucle -> table, le compilateur répond
		// « Table SQL inconnue » alors que la table existe bel et bien. Seules
		// les tables réellement parcourues par un squelette sont concernées.
		$boucle = preg_replace('/^spip_/', '', $table);
		$bouclee = false;
		foreach (fichiers($plugin, ['html']) as $squelette) {
			if (preg_match('/<BOUCLE[a-z0-9_]*\\(' . strtoupper($boucle) . '\\)/i', file_get_contents($squelette))) {
				$bouclee = true;
				break;
			}
		}
		if ($bouclee) {
			verifier(
				"$nom_court : <BOUCLE(" . strtoupper($boucle) . ")> associée à $table",
				strpos($base, "['table_des_tables']['$boucle']") !== false
			);
		}
	}

	// Une table déclarée seulement comme objet éditorial n'est pas créée :
	// maj_tables() ne consulte pas ce registre-là.
	if (preg_match_all('/\\$tables\\[\'(spip_[a-z0-9_]+)\'\\]/', $base, $t)
		&& preg_match('/function ' . $prefixe . '_declarer_tables_objets_sql\\(.*?\\n}/s', $base, $bloc)
	) {
		preg_match_all('/\\$tables\\[\'(spip_[a-z0-9_]+)\'\\]/', $bloc[0], $objets);
		preg_match('/function ' . $prefixe . '_declarer_tables_principales\\(.*?\\n}/s', $base, $bloc_principales);
		foreach (array_unique($objets[1]) as $table) {
			verifier(
				"$nom_court : $table déclarée aussi comme table principale",
				!empty($bloc_principales[0]) && strpos($bloc_principales[0], $table) !== false
			);
		}
	}

	// Créer des tables sans réinitialiser les caches laisse le compilateur sur
	// une vue périmée du schéma.
	verifier(
		"$nom_court : la création réinitialise les caches de schéma",
		strpos($install, "charger_fonction('trouver_table', 'base'") !== false
	);

	// « create » n'étant jamais rejouée, il faut une étape versionnée au niveau
	// du schéma pour rattraper une installation restée incomplète.
	preg_match_all('/\$maj\[\'([0-9]+\.[0-9]+\.[0-9]+)\'\]/', $install, $trouves);
	$etapes = array_unique($trouves[1]);
	verifier("$nom_court : au moins une étape de migration versionnée", (bool) $etapes);
	if ($etapes) {
		usort($etapes, 'version_compare');
		$plus_haute = end($etapes);
		verifier("$nom_court : schema=$schema aligné sur la plus haute étape ($plus_haute)", $schema === $plus_haute);
	}

	// Marqueur de dernière modification attendu par SPIP sur toute table gérée.
	foreach (glob($plugin . '/base/*.php') as $fichier) {
		$source = file_get_contents($fichier);
		foreach ($declarees as $table) {
			$position = strpos($source, "\$tables['$table']");
			if ($position === false) {
				continue;
			}
			$suite = substr($source, $position, 3000);
			verifier("$nom_court : $table porte une colonne maj TIMESTAMP", strpos($suite, "'maj'") !== false);
		}
	}
}

echo "\n== Autorisations ==\n";

foreach ($plugins as $plugin) {
	$nom_court = basename($plugin);
	$prefixe   = (string) simplexml_load_file($plugin . '/paquet.xml')['prefix'];
	$fichier   = $plugin . "/{$prefixe}_autorisations.php";
	if (!is_file($fichier)) {
		continue;
	}
	$source = file_get_contents($fichier);

	// La chaîne de résolution de SPIP essaie plusieurs noms pour un même
	// contrôle. Si l'un délègue à un autre, ou repasse par autoriser(), la
	// récursion est sans fin — et le symptôme est un dépassement de pile très
	// loin de la cause.
	preg_match_all('/function\s+(autoriser_[a-z0-9_]+)\s*\([^)]*\)\s*\{(.*?)\n\}/s', $source, $trouves, PREG_SET_ORDER);
	verifier("$nom_court : des fonctions d’autorisation sont définies", (bool) $trouves);
	foreach ($trouves as $fonction) {
		verifier(
			"$nom_court : {$fonction[1]}() ne délègue à aucune autre autorisation",
			!preg_match('/\bautoriser[_(]/', $fonction[2])
		);
	}

	// Sans ce chargement, autoriser() n'existe pas encore dans un fichier action.
	foreach (glob($plugin . '/action/*.php') as $action) {
		$code = file_get_contents($action);
		if (strpos($code, 'autoriser(') === false) {
			continue;
		}
		verifier(
			"$nom_court : " . basename($action) . " charge inc/autoriser",
			strpos($code, "include_spip('inc/autoriser')") !== false
		);
	}
}

echo "\n== Conventions de nommage des objets ==\n";

foreach ($plugins as $plugin) {
	$nom_court = basename($plugin);
	$prefixe   = (string) simplexml_load_file($plugin . '/paquet.xml')['prefix'];
	$fichier   = $plugin . "/base/{$prefixe}_tables.php";
	if (!is_file($fichier)) {
		continue;
	}
	preg_match_all("/'type'\s*=>\s*'([a-z0-9_]+)'/", file_get_contents($fichier), $types);
	if (empty($types[1])) {
		continue;
	}

	$code = '';
	foreach (fichiers($plugin, ['php']) as $f) {
		$code .= file_get_contents($f);
	}

	// objet_inserer('x', …) appelle x_inserer() si elle existe, objet_modifier()
	// appelle x_modifier(), etc. Définir ces fonctions et y appeler l'API
	// générique produit une récursion infinie : chacune rappelle l'autre.
	foreach (array_unique($types[1]) as $type) {
		foreach (['inserer', 'modifier', 'instituer', 'supprimer', 'dupliquer'] as $verbe) {
			verifier(
				"$nom_court : pas de fonction {$type}_{$verbe}() en collision avec l’API générique",
				strpos($code, "function {$type}_{$verbe}(") === false
			);
		}
	}
}

echo "\n== Recalcul de la liste des plugins ==\n";

foreach ($plugins as $plugin) {
	$nom_court = basename($plugin);
	$code = '';
	foreach (fichiers($plugin, ['php']) as $f) {
		$code .= file_get_contents($f);
	}
	if (strpos($code, 'ecrire_plugin_actifs(') === false) {
		continue;
	}
	// ecrire_plugin_actifs() en mode « raz » écrit la liste telle qu'on la lui
	// donne : appelée avec autre chose que la liste complète des chemins, elle
	// désactive les plugins absents de l'argument — y compris l'appelant.
	verifier(
		"$nom_court : ecrire_plugin_actifs() n’est jamais appelée en mode « raz »",
		!preg_match("/ecrire_plugin_actifs\\([^;]*'raz'/", $code)
	);
	verifier(
		"$nom_court : recalcul par « ajoute » sur liste vide",
		(bool) preg_match("/ecrire_plugin_actifs\\(\\s*\\[\\s*\\]\\s*,[^;]*'ajoute'/", $code)
	);
}

echo "\n== Champs éditables ==\n";

foreach ($plugins as $plugin) {
	$nom_court = basename($plugin);
	$prefixe   = (string) simplexml_load_file($plugin . '/paquet.xml')['prefix'];
	$fichier   = $plugin . "/base/{$prefixe}_tables.php";
	if (!is_file($fichier)) {
		continue;
	}
	// objet_modifier() route tout « statut » posté vers objet_instituer() : le
	// déclarer éditable revient à écrire le champ par deux mécanismes à la fois.
	if (preg_match("/'champs_editables'\s*=>\s*\[([^\]]*)\]/", file_get_contents($fichier), $bloc)) {
		verifier(
			"$nom_court : « statut » n’est pas déclaré champ éditable",
			strpos($bloc[1], "'statut'") === false
		);
	}
}

echo "\n== Noms de colonnes ==\n";

foreach ($plugins as $plugin) {
	$nom_court = basename($plugin);
	$prefixe   = (string) simplexml_load_file($plugin . '/paquet.xml')['prefix'];
	$fichier   = $plugin . "/base/{$prefixe}_tables.php";
	if (!is_file($fichier)) {
		continue;
	}

	// La couche SQL de SPIP réécrit tout identifiant préfixé « spip_ » en nom de
	// table : une colonne ainsi nommée casse le CREATE TABLE, et la table n'est
	// jamais créée. Le symptôme est une erreur de syntaxe SQL sans rapport
	// apparent avec la colonne fautive.
	preg_match_all("/^\t+'(spip_[a-z0-9_]+)'\s*=>\s*[\"']/m", file_get_contents($fichier), $trouves);
	$suspectes = [];
	foreach ($trouves[1] as $nom) {
		// Les clefs de premier niveau sont des noms de tables, elles sont légitimes.
		if (strpos(file_get_contents($fichier), "\$tables['$nom']") === false) {
			$suspectes[] = $nom;
		}
	}
	verifier(
		"$nom_court : aucune colonne préfixée « spip_ »",
		!$suspectes
	);
	foreach ($suspectes as $nom) {
		echo "         colonne fautive : $nom\n";
	}
}

echo "\n== Filtres appelés par les squelettes ==\n";

foreach ($plugins as $plugin) {
	$prefixe = (string) simplexml_load_file($plugin . '/paquet.xml')['prefix'];
	$fonctions = $plugin . '/' . $prefixe . '_fonctions.php';
	$disponibles = '';
	foreach (array_merge(glob($plugin . '/*_fonctions.php'), glob($plugin . '/inc/*.php')) as $fichier) {
		$disponibles .= file_get_contents($fichier);
	}
	foreach (fichiers($plugin, ['html']) as $squelette) {
		$affiche = str_replace($plugin . '/', '', $squelette);
		preg_match_all('/\|(' . $prefixe . '_[a-z0-9_]+)/', file_get_contents($squelette), $trouves);
		foreach (array_unique($trouves[1]) as $filtre) {
			verifier("$affiche : filtre |$filtre défini", strpos($disponibles, "function $filtre(") !== false);
		}
	}
	if (glob($plugin . '/*_fonctions.php')) {
		verifier(basename($plugin) . " : {$prefixe}_fonctions.php chargé automatiquement", is_file($fonctions));
	}
}

echo "\n== Surface d’API SPIP utilisée ==\n";

/**
 * Fonctions du core SPIP que ce projet s’autorise à appeler.
 *
 * Toute fonction absente de cette liste fait échouer le test : c’est le seul
 * garde-fou contre l’appel d’une API supposée exister. Ajouter une entrée doit
 * être un geste délibéré, fait après avoir vérifié la fonction.
 */
$api_spip = [
	// Noyau, disponible sans inclusion
	'include_spip', 'charger_fonction', '_T', '_request', 'spip_log', 'ecrire_meta',
	'effacer_meta', 'parametre_url', 'redirige_par_entete', 'url_de_base', 'generer_url_ecrire',
	// inc/
	'autoriser', 'lire_config', 'ecrire_config', 'recuperer_url', 'purger_repertoire',
	'lire_metas', 'plugin_installes_meta',
	'sous_repertoire', 'spip_version_compare', 'session_get',
	'liste_plugin_actifs', 'ecrire_plugin_actifs',
	// formulaires CVT sur objet
	'formulaires_editer_objet_charger', 'formulaires_editer_objet_verifier',
	'formulaires_editer_objet_traiter',
	// action/editer_objet — objet_modifier_champs() n’en fait pas partie :
	// elle n’est pas exposée, et l’avoir appelée provoquait une erreur fatale.
	'objet_inserer', 'objet_modifier', 'objet_instituer',
	// base/
	'maj_plugin', 'maj_tables',
	// abstract_sql
	'sql_allfetsel', 'sql_alltable', 'sql_countsel', 'sql_create', 'sql_delete',
	'sql_drop_table', 'sql_error', 'sql_fetch', 'sql_fetsel', 'sql_free', 'sql_insertq',
	'sql_query', 'sql_quote', 'sql_select', 'sql_showtable', 'sql_updateq', 'sql_version',
];

$definies = [];
$appels   = [];
foreach ($plugins as $plugin) {
	foreach (fichiers($plugin, ['php']) as $fichier) {
		$source = file_get_contents($fichier);
		$affiche = basename($plugin) . '/' . str_replace($plugin . '/', '', $fichier);

		// Analyse sur les jetons PHP : les commentaires et les chaînes ne sont
		// pas du code, et un extracteur textuel y ramasse n'importe quoi.
		$jetons = token_get_all($source);
		$nb = count($jetons);
		for ($i = 0; $i < $nb; $i++) {
			$jeton = $jetons[$i];
			if (!is_array($jeton) || $jeton[0] !== T_STRING) {
				continue;
			}

			// Ce qui précède : une déclaration, une méthode, une instanciation ?
			$avant = null;
			for ($j = $i - 1; $j >= 0; $j--) {
				if (is_array($jetons[$j]) && in_array($jetons[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
					continue;
				}
				$avant = $jetons[$j];
				break;
			}
			if (is_array($avant) && in_array($avant[0], [T_FUNCTION, T_NEW, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
				if ($avant[0] === T_FUNCTION) {
					$definies[strtolower($jeton[1])] = true;
				}
				continue;
			}

			// Ce qui suit : une parenthèse ouvrante, sinon c'est une constante.
			$apres = null;
			for ($j = $i + 1; $j < $nb; $j++) {
				if (is_array($jetons[$j]) && $jetons[$j][0] === T_WHITESPACE) {
					continue;
				}
				$apres = $jetons[$j];
				break;
			}
			if ($apres !== '(') {
				continue;
			}

			$appels[strtolower($jeton[1])] = $affiche;
		}
	}
}

$natives   = array_flip(get_defined_functions()['internal']);
$structures = array_flip(['if', 'for', 'foreach', 'while', 'switch', 'catch', 'return', 'echo',
	'array', 'isset', 'unset', 'empty', 'list', 'print', 'exit', 'die', 'include', 'require',
	'include_once', 'require_once', 'elseif', 'fn', 'match', 'and', 'or', 'xor', 'clone', 'yield', 'use']);
$autorisees = array_flip(array_map('strtolower', $api_spip));

$inconnues = [];
foreach ($appels as $nom => $ou) {
	if (isset($definies[$nom]) || isset($natives[$nom]) || isset($structures[$nom]) || isset($autorisees[$nom])) {
		continue;
	}
	$inconnues[$nom] = $ou;
}

verifier(count($appels) . ' appels de fonction analysés, aucun hors contrat', !$inconnues);
foreach ($inconnues as $nom => $ou) {
	echo "         fonction non déclarée au contrat : $nom() dans $ou\n";
}

// Le diagnostic affiché à l’utilisateur doit couvrir la même surface.
$fonctions = file_get_contents($racine . '/plugins/dashboard/dashboard_fonctions.php');
if (preg_match('/function dashboard_api_requise\(.*?\n}/s', $fonctions, $bloc)) {
	preg_match_all("/'([a-z_][a-z0-9_]*)'/", $bloc[0], $t);
	$declarees = array_diff($t[1], ['inc', 'action', 'base']);
	$hors = [];
	foreach ($declarees as $nom) {
		if (strpos($nom, '/') === false && !isset($autorisees[$nom]) && !isset($natives[$nom])) {
			$hors[] = $nom;
		}
	}
	verifier('le diagnostic d’API n’annonce que des fonctions du contrat', !$hors);
	foreach ($hors as $nom) {
		echo "         annoncée mais hors contrat : $nom\n";
	}
}

echo "\n== Fichiers d’inclusion des API SPIP ==\n";

/**
 * Fichier qui définit réellement chaque fonction, relevé dans les sources de
 * SPIP 4.4.23. Une fonction appelée sans son include_spip() est fatale à
 * l’exécution — c’est ainsi que purger_repertoire(), cherchée à tort dans
 * inc/flock, restait indéfinie.
 */
$fournisseur = [
	'autoriser'            => 'inc/autoriser',
	'ecrire_config'        => 'inc/config',
	'lire_config'          => 'inc/config',
	'ecrire_meta'          => 'inc/meta',
	'lire_metas'           => 'inc/meta',
	'plugin_installes_meta' => 'inc/plugin',
	'effacer_meta'         => 'inc/meta',
	'purger_repertoire'    => 'inc/invalideur',
	'sous_repertoire'      => 'inc/flock',
	'recuperer_url'        => 'inc/distant',
	'session_get'          => 'inc/session',
	'redirige_par_entete'  => 'inc/headers',
	'spip_version_compare' => 'inc/plugin',
	'liste_plugin_actifs'  => 'plugins/installer',
	'ecrire_plugin_actifs' => 'inc/plugin',
	'objet_inserer'        => 'action/editer_objet',
	'objet_modifier'       => 'action/editer_objet',
	'objet_instituer'      => 'action/editer_objet',
	'maj_tables'           => 'base/create',
	'maj_plugin'           => 'base/upgrade',
	'formulaires_editer_objet_charger'  => 'inc/editer',
	'formulaires_editer_objet_verifier' => 'inc/editer',
	'formulaires_editer_objet_traiter'  => 'inc/editer',
];

// Chargés par le noyau avant tout code de plugin.
$toujours_charges = ['inc/autoriser', 'inc/config', 'inc/plugin', 'inc/session'];

$defauts = [];
foreach ($plugins as $plugin) {
	foreach (fichiers($plugin, ['php']) as $fichier) {
		$source  = file_get_contents($fichier);
		$affiche = basename($plugin) . '/' . str_replace($plugin . '/', '', $fichier);
		preg_match_all("/include_spip\(\s*'([^']+)'/", $source, $inc);
		$inclus = array_flip($inc[1]);

		$jetons = token_get_all($source);
		$nb = count($jetons);
		for ($i = 0; $i < $nb; $i++) {
			if (!is_array($jetons[$i]) || $jetons[$i][0] !== T_STRING) {
				continue;
			}
			$nom = strtolower($jetons[$i][1]);
			if (!isset($fournisseur[$nom])) {
				continue;
			}
			for ($j = $i + 1; $j < $nb && is_array($jetons[$j]) && $jetons[$j][0] === T_WHITESPACE; $j++);
			if (($jetons[$j] ?? null) !== '(') {
				continue;
			}
			for ($k = $i - 1; $k >= 0 && is_array($jetons[$k])
				&& in_array($jetons[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true); $k--);
			$avant = $jetons[$k] ?? null;
			if (is_array($avant) && in_array($avant[0], [T_FUNCTION, T_NEW, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
				continue;
			}

			$requis = $fournisseur[$nom];
			if (in_array($requis, $toujours_charges, true) || isset($inclus[$requis])) {
				continue;
			}
			$defauts[$affiche . ' : ' . $nom . '() sans include_spip(\'' . $requis . '\')'] = true;
		}
	}
}

verifier('chaque API SPIP est appelée avec son include_spip', !$defauts);
foreach (array_keys($defauts) as $defaut) {
	echo "         $defaut\n";
}

echo "\n== Clefs de langue référencées ==\n";

$modules = [];
foreach ($plugins as $plugin) {
	foreach (glob($plugin . '/lang/*_fr.php') as $fichier) {
		preg_match_all("/^\t'([a-z0-9_]+)'\s*=>/m", file_get_contents($fichier), $trouves);
		$modules[basename($fichier, '_fr.php')] = array_flip($trouves[1]);
	}
}

$manquantes = [];
$verifiees  = 0;
foreach ($plugins as $plugin) {
	foreach (fichiers($plugin, ['html', 'php', 'xml']) as $fichier) {
		$source = file_get_contents($fichier);
		$refs = [];
		preg_match_all('/<:([a-z0-9_\-]+):([a-z0-9_]+)[:{]/', $source, $t, PREG_SET_ORDER);
		$refs = array_merge($refs, $t);
		preg_match_all('/_T\(\s*\'([a-z0-9_\-]+):([a-z0-9_]+)\'/', $source, $t, PREG_SET_ORDER);
		$refs = array_merge($refs, $t);
		preg_match_all('/=>\s*\'(dashboard|dashagent):([a-z0-9_]+)\'/', $source, $t, PREG_SET_ORDER);
		$refs = array_merge($refs, $t);
		preg_match_all('/titre="([a-z0-9_\-]+):([a-z0-9_]+)"/', $source, $t, PREG_SET_ORDER);
		$refs = array_merge($refs, $t);

		foreach ($refs as $ref) {
			// Les modules du core (spip:, ecrire:, public:) ne sont pas vérifiables ici.
			if (!isset($modules[$ref[1]])) {
				continue;
			}
			$verifiees++;
			if (!isset($modules[$ref[1]][$ref[2]])) {
				$manquantes[$ref[1] . ':' . $ref[2]] = str_replace($plugin . '/', '', $fichier);
			}
		}
	}
}

verifier("$verifiees références de langue résolues", !$manquantes);
foreach ($manquantes as $clef => $ou) {
	echo "         manque $clef (utilisée dans $ou)\n";
}

echo "\n----------------------------------------\n";
echo ($total - $echecs) . " / $total vérifications passées\n";

exit($echecs === 0 ? 0 : 1);
