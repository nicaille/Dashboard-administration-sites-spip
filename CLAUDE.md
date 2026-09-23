# Conventions du dépôt

## Les dossiers de plugins portent leur version

`plugins/<prefixe>-<version>` : `plugins/tourdecontrole-1.0.34`,
`plugins/tourdecontrole_agent-1.0.24`.

**À chaque montée de version d'un plugin, renommer son dossier en conséquence**,
dans le même commit que le changement de `version=` dans son `paquet.xml`. Un
`git mv` conserve l'historique des fichiers.

La raison : déposer la nouvelle version à côté de l'ancienne, plutôt que
par-dessus, évite qu'un fichier supprimé entre deux versions survive à une mise
en ligne par FTP et continue d'être chargé. Revenir en arrière se réduit alors à
supprimer le nouveau dossier.

SPIP s'y retrouve seul : il indexe les plugins par préfixe et retient la version
la plus élevée quand deux dossiers déclarent le même (`ecrire/inc/plugin.php`,
`plugin_valide_resume()`).

À ne pas confondre avec le déclenchement des migrations : `maj_plugin()` compare
la version du `paquet.xml` à celle mémorisée en meta, jamais le nom du dossier.

### Une version publiée est figée

Corollaire, et la faute est arrivée : l'authentification HTTP a été livrée dans
un dossier `1.0.27` **déjà publié au dépôt**, sans montée de version. Le dépôt
s'est mis à servir un 1.0.27 dont le contenu différait de celui que les sites
avaient installé — et SVP compare des numéros. Même numéro, aucune mise à jour
proposée : la fonction était inatteignable, et la migration de schéma qui
l'accompagnait ne pouvait pas se jouer.

Rien ne le signale. Le dépôt publie sans broncher, les tests passent, et le parc
affiche une version qui a l'air juste.

**Dès qu'un `outils/generer-depot.php` a tourné sur une version, cette version
est morte** : le moindre changement de code dans son dossier impose un
numéro de plus, fût-ce dans la même session et pour trois lignes. Dans le doute,
regarder les exécutions de `.github/workflows/depot.yml` : ce qui y est passé
est dehors.

**Sur les sites administrés, c'est SVP qui applique la règle** quand il est là :
`inc/dashagent_svp.php` lui délègue la mise à jour, et il range les plugins dans
`plugins/auto/<prefixe>/v<version>`. Un refus de sa part ne se contourne pas —
voir `dashboard_chantier_svp_repli()`.

À défaut de SVP, l'agent déploie l'archive lui-même sans écraser :
`dashagent_dossier_versionne()` et `dashagent_installer_a_cote()`, dans
`inc/dashagent_maj.php`. Comme SPIP tient sa liste de plugins actifs par
dossier, le nouveau lui est déclaré dans la foulée — sans quoi le plugin serait
désactivé par son propre changement de nom.

### Un piège de SPIP 4.4 : autoriser_exception() et les types en `_`

`autoriser_exception('ajouter', '_plugins', '*')` — la formule qu'emploient SVP
comme spip-cli — **n'a aucun effet**. L'exception est rangée sous le type réduit
(`plugins`), mais `autoriser()` la relit en repassant par `autoriser_type()`,
qui le transforme une seconde fois en `plugin` : la clé consultée n'est jamais
celle qui a été écrite. Il faut déclarer les deux orthographes
(`dashagent_svp_autoriser()`). Sans cela, `action/teleporter.php` refuse le
téléchargement avec un laconique « Chargement impossible de la source ».

### Ne pas figer la version dans les références

Tout ce qui doit atteindre un plugin le retrouve **par préfixe**, jamais par un
chemin complet — autrement chaque montée de version casserait une dizaine de
fichiers :

- `tests/bootstrap.php` et `tests/test_structure.php` : fonction
  `chemin_plugin('tourdecontrole')`, qui prend la version la plus élevée ;
- `tests/integration/executer.sh` : `plugins/tourdecontrole-*` au glob ;
- `README.md` et `docs/` : écrire `plugins/tourdecontrole-<version>`.

Le code des plugins, lui, ne doit jamais mentionner son propre nom de dossier.
SPIP fournit `_DIR_PLUGIN_<PREFIXE>` pour cela.

## Un silence n'est pas une réponse

`dashboard_silence()`, dans `inc/dashboard_client.php`, distingue les deux —
c'est elle qui dit ce qu'on a le droit de conclure d'un échec :

- une **réponse** de l'agent (« verrou SVP posé », « opération non autorisée »)
  se respecte, et l'opération s'arrête là ;
- un **silence** — `transport`, ou `reponse_illisible` — ne dit rien de l'issue.
  L'opération a très bien pu aboutir.

Deux causes de silence, toutes deux hors de portée de l'agent : le plugin qui se
remplace lui-même pendant qu'il répond, et le **cache ou répartiteur en frontal**
(Varnish, nginx, Cloudflare) dont la patience est plus courte que la nôtre — son
`first_byte_timeout` vaut soixante secondes par défaut, là où un export SQL en
prend davantage, et il rend un 503 en HTML pendant que PHP travaille encore.
Rencontré en vrai sur l'option CDN d'un hébergement OVH, où ce délai n'est
exposé dans **aucun réglage accessible** : un frontal qu'on administre se règle,
un CDN d'hébergeur se contourne — en pointant `url_agent`, champ distinct de
`url_site`, vers un nom que le CDN ne couvre pas.

Toute opération longue doit donc savoir **aller constater** plutôt que conclure :

- la mise à jour d'un plugin reste sur son étape et redemande des nouvelles
  (`dashboard_chantier_plugin_verifier()`) ;
- la sauvegarde interroge l'inventaire du site et adopte celle qu'elle y trouve
  (`dashboard_sauvegarde_rattraper()`, `dashboard_sauvegarde_retrouvee()`).

La règle d'adoption est stricte, parce qu'annoncer une sauvegarde qu'on n'a pas
serait pire que l'échec : inconnue de nous, non vide, et récente — avec une
tolérance d'horloge, la date venant du site géré et non de nous.

Une sauvegarde adoptée n'a pas d'empreinte : l'inventaire n'en publie pas. Le
rapatriement contrôle alors la taille annoncée, faute de SHA-256.

### Mieux que constater : ne pas s'exposer au silence

Le rattrapage sait dire ce qui s'est passé ; il ne sait pas l'empêcher. Depuis
l'agent 1.0.21, la sauvegarde **se découpe** : l'agent exporte pendant
`_DASHAGENT_SAUVEGARDE_BUDGET` — vingt secondes, choisies sur la patience du
frontal et non sur ce que PHP tient — puis rend la main, et la tour rappelle la
même opération tant que `termine` est faux. Le contrat est celui des chantiers
et des actions de parc.

Ce qui tient le fil d'une tranche à l'autre est un état à côté du `.partiel` :
table en cours, rang atteint, et **taille du fichier au dernier point de
contrôle**. Cette dernière est tout : un processus tué en plein milieu d'une
tranche laisse des octets que l'état ne mentionne pas, et
`dashagent_sauvegarde_recaler()` les tronque avant de reprendre. Sans elle, la
reprise écrirait à la suite d'un membre gzip inachevé, ou rejouerait des lignes
déjà exportées — une archive plausible, que rien ensuite ne signalerait comme
fausse.

Le découpage renverse la règle du silence sur un point, et sur un seul : ici
**un silence se retente**, parce que la reprise est idempotente. Rien de tel
dans l'export d'un seul tenant, où retenter voudrait dire tout refaire.
`dashboard_sauvegarde_suite()` porte cette décision, et se vérifie donc sans
réseau.

Deux compatibilités, dans les deux sens, et c'est là qu'est le piège : un agent
d'avant la 1.0.21 ignore `decoupee` et **ne dit rien de `termine`**. Son silence
sur cette clef vaut « c'est fini » — le prendre pour un « pas fini » ferait
boucler la tour sur un export déjà publié. Réciproquement, une tour ancienne
n'envoie pas `decoupee`, et l'agent lui doit alors l'export d'un seul tenant.

**Présente ne veut pas dire valide.** Empreinte et taille attestent le transfert,
jamais le contenu : un export interrompu sur le site géré voyage parfaitement.
Trois protections, à trois endroits :

- l'agent écrit sous `.partiel` et **renomme au succès** — le renommage étant
  atomique, le nom définitif ne désigne jamais un fichier incomplet ;
- chaque dump se termine par `_DASHAGENT_SAUVEGARDE_FIN`. Le pied de page du gzip
  prouve qu'aucun octet n'a été perdu ; il ne dit rien de ce que le script avait
  encore à écrire quand il a été tué entre deux tables ;
- `dashboard_sauvegarde_verifier()` relit l'archive au rapatriement, membre par
  membre : chacun doit se terminer proprement — zlib vérifie lui-même son CRC32
  et sa taille décompressée —, et le fichier doit s'achever sur une frontière de
  membre. C'est ce que fait `gzip -t`.

**Un gzip n'a pas forcément un seul membre** (RFC 1952), et une sauvegarde
découpée en a un par tranche. Le contrôle d'avant ne lisait que les huit derniers
octets, c'est-à-dire le pied du *dernier* membre, et les comparait au flux tout
entier : il aurait déclaré tronquée toute archive découpée. Le parcours passe par
`inflate_add()`, dont `inflate_get_read_len()` donne la frontière exacte de
chaque membre — la seule façon de savoir où l'un finit sans deviner.

Ses deux verdicts ne se confondent pas : `ok` à faux **prouve** que le fichier
est abîmé, `complet` à faux dit seulement que la marque n'a pas été vue. Les
sauvegardes d'avant l'agent 1.0.16 n'en portent pas, et les refuser reviendrait
à jeter des sauvegardes valides.

Corollaire à ne pas oublier en écrivant un outil de restauration :
**`gzdecode()` ne rend que le premier membre**, sans erreur ni avertissement.
Sur une sauvegarde découpée il rendrait une poignée de tables au lieu de la base
entière, et le script de restauration n'aurait rien à signaler. `gunzip`, `zcat`
et `gzip -t` les lisent tous ; en PHP, il faut la boucle `inflate_add()` /
`inflate_get_read_len()`. `tests/integration/scenario.mjs` en porte une, sur
les deux relectures qu'il fait de l'archive.

## Le préfixe d'un plugin n'est pas le préfixe de ses fonctions

Les deux plugins ont changé de préfixe en 1.0.19 et 1.0.15 — `dashboard` était
déjà pris par un autre plugin SPIP, et SVP indexe par préfixe **tous dépôts
confondus** : il aurait fini par proposer l'un pour l'autre.

Ce que SPIP dérive du préfixe, et qu'il faut donc renommer avec lui :

- les fichiers `<prefixe>_fonctions.php`, `<prefixe>_options.php`,
  `<prefixe>_administrations.php`, que SPIP charge tout seul pour les plugins
  actifs ;
- les fonctions `<prefixe>_upgrade()` et `<prefixe>_vider_tables()` ;
- **les fonctions de pipeline** : `<prefixe>_autoriser()`,
  `<prefixe>_declarer_tables_*()`, `<prefixe>_taches_generales_cron()`. Le
  `inclure=` du `paquet.xml` dit dans quel fichier chercher, jamais comment la
  fonction s'appelle ;
- la meta de version de schéma `<prefixe>_base_version` — d'où
  `dashboard_reprendre_schema()`, sans quoi SPIP croit le plugin neuf et rejoue
  toutes ses migrations sur une base déjà à jour ;
- le module de langue du manifeste `lang/paquet-<prefixe>_XX.php`, et ses clefs
  `<prefixe>_nom`, `<prefixe>_slogan`, `<prefixe>_description` ;
- **la page de configuration `configurer_<prefixe>`**. SPIP n'affiche le bouton
  « Configurer » de sa page *Gestion des plugins* qu'à cette condition :
  `plugin_bouton_config()` compose l'exec depuis `strtolower($infos['prefix'])`,
  et `prive/squelettes/inclure/cfg.html` ne rend le lien que si
  `tester_url_ecrire` le trouve. Nos pages s'appelaient `configurer_dashboard`
  et `configurer_dashagent` : le bouton n'a jamais pu apparaître, et rien ne le
  disait. L'autorisation suit le même nom — `autoriser('configurer',
  '_tourdecontrole')` —, sous les trois orthographes que la chaîne de résolution
  essaie.

Ce que SPIP ne dérive de rien, et qui a donc gardé son nom : **les fonctions
internes et les filtres de squelette** (`dashboard_*`, `dashagent_*`), **les noms
de tables** (`spip_dashboard_sites`), **les chemins de configuration**
(`lire_config('dashboard/…')`) et **les modules de langue ordinaires**
(`<:dashboard:clef:>`, qui tiennent au nom du fichier). Les renommer aurait
touché deux cents fonctions et les tables — donc les données des sites déjà
installés — pour un gain nul.

`tests/test_structure.php` porte cette distinction dans son tableau
`$familles` : préfixe du plugin d'un côté, famille de fonctions de l'autre. Sans
lui, il cherchait `|tourdecontrole_…` dans les squelettes, n'en trouvait aucun,
et **passait au vert en ayant cessé de vérifier trente-six filtres**. Un test qui
perd son sujet ne le dit pas : quand un renommage fait tomber le nombre de
vérifications, c'est là qu'il faut regarder.

Un underscore dans un préfixe ne pose aucun problème — `porte_plume`, livré avec
SPIP, en porte un.

## Une lecture de meta figée sur un ancien préfixe ne casse rien de visible

`dashagent_version_plugin()` cherchait `$infos['DASHAGENT']` dans la liste des
plugins actifs, que SPIP range sous le **préfixe courant**. Après le renommage,
elle rendait sa valeur par défaut — « 0 » — et le parc a affiché « Version de
l'agent : 0 » pendant des semaines sans que rien n'échoue par ailleurs.

C'est le propre de cette faute : elle ne lève aucune erreur, elle rend un
défaut. Les renommages de préfixe doivent donc être relus **aussi** du côté des
lectures de `$GLOBALS['meta']['plugin']`, que la liste des fichiers dérivés du
préfixe ne couvre pas.

`tests/test_structure.php` refuse maintenant toute lecture indexée sur un
préfixe abandonné, et vérifie que l'agent cherche bien le sien.

## Les opérations longues ont un budget, pas seulement un plafond

La mesure des caches parcourait jusqu'à vingt mille fichiers par répertoire, un
`stat()` par fichier. Le plafond en nombre ne protège pas du temps : sur un
disque partagé, ce parcours dépassait à lui seul les trente secondes que la tour
de contrôle accorde à une synchronisation, et l'inventaire entier était perdu
pour un chiffre accessoire.

`_DASHAGENT_CACHES_BUDGET` borne désormais l'inventaire **entier**, pas chaque
répertoire — cinq cibles à trois secondes chacune auraient reproduit le
problème. Ce qui n'a pas été parcouru ressort `partiel`, et l'inventaire est
rendu quand même : une valeur approximative vaut mieux qu'une synchronisation
perdue.

## Le dépôt SVP se fabrique, il ne s'écrit pas à la main

`outils/generer-depot.php` produit le catalogue et les archives que publie
`.github/workflows/depot.yml` sur GitHub Pages. Trois règles que SVP impose et
qu'aucun message d'erreur n'explique — `tests/test_depot.php` les vérifie :

- **Le catalogue n'a pas de racine unique** : `<depot>` et `<archives>` sont deux
  blocs frères. SVP le lit à l'expression régulière (`svp_phraser_depot()`),
  jamais avec un analyseur XML. Passer par `DOMDocument` produirait une racine
  englobante et un dépôt inajoutable.
- **`<type>http</type>` désigne le téléporteur**, et lui seul fait télécharger
  l'archive à `url_archives` + `/` + `<file>` — d'où une `url_archives` **sans**
  barre oblique finale.
- **Le zip a une racine unique, du nom du préfixe** : le téléporteur la retire au
  déballage et range le reste dans `plugins/auto/<prefixe>/v<version>`.

Et un piège qui ne se voit qu'à l'usage : avant de demander le catalogue, SVP
sonde `plugins.thin.spip-<branche>.xml` puis `plugins.thin.xml`
(`svp_depoter_distant_copie_xml_paquets()`). **Un hébergement qui répond 200 à un
fichier absent** — le serveur intégré de PHP sert `index.html` — fait lire cette
page en guise de catalogue, pour un laconique « le fichier XML n'est pas
conforme ». GitHub Pages répond bien 404 ; on publie tout de même
`plugins.thin.xml`.

La règle du dossier versionné se vérifie là où elle compte : le générateur
**refuse de publier** un dossier dont le nom ne s'accorde pas avec la version de
son `paquet.xml`.

La publication suit un geste, jamais un commit : un tag, ou le workflow lancé à
la main. Le nom du tag n'est qu'une étiquette — le dépôt publie tous les plugins
présents, chacun à la version de son dossier.

## L'habillage est celui du privé, pas le nôtre

Le thème de l'espace privé habille déjà tout ce dont ces squelettes ont besoin.
Redéfinir par-dessus produit surtout des accidents : un fond blanc posé sans
redéfinir la couleur du texte, que le thème met en blanc, donnait des libellés
invisibles sur les boutons de pagination.

- **Boutons** : `class="btn"`, `btn btn_secondaire`, `btn btn_danger`. Jamais de
  classe maison.
- **Actions** : `#BOUTON_ACTION{libelle,url,classes[,confirmation]}`, qui rend un
  formulaire POST. Une action qui change l'état d'un site ne se déclenche pas en
  suivant un lien. Les vrais liens de navigation restent des `<a class="btn …">`.
- **Pagination** : `{pagination N}` sur la boucle, puis
  `[<nav class="pagination" role="navigation">(#PAGINATION{page_precedent_suivant})</nav>]`
  — page précédente, les numéros de page, page suivante.
  L'argument de `#PAGINATION{X}` est **d'abord un type**, accessoirement un nom
  de modèle : `filtre_pagination_dist()` pose `type_pagination = X`, puis prend
  `modeles/pagination_X.html` s'il existe et le modèle par défaut sinon. Or
  `pagination.html` teste lui-même `type_pagination == page_precedent_suivant`
  pour ajouter précédent et suivant à ses numéros. SPIP 4.4 ne livre que trois
  modèles — `pagination`, `pagination_precedent_suivant`, `pagination_prive` —
  et `page_precedent_suivant` n'en est pas un : c'est voulu.
- **Listes paginées côté JavaScript** (le contenu des tables d'un site géré ne
  vient d'aucune boucle) : reprendre le balisage à l'identique —
  `nav.pagination` > `ul.pagination-items.pagination_page_precedent_suivant` >
  `li.pagination-item.prev|next[.disabled]`, `li.pagination-item[.on.active]`
  pour les numéros, `li.pagination-item.tbc.disabled` pour les points de
  suspension > `a.pagination-item-label` (ou un `span` quand l'item est figé).
  Sans total connu, s'en tenir à précédent/suivant : on ne peut pas numéroter
  ce qu'on n'a pas compté.
- **Champs de formulaire hors formulaire** : les envelopper dans
  `div.formulaire_spip > div.editer > label + champ`, sinon un `select` reste
  celui du navigateur.
- **Retour à l'encadré** : chaque bloc porte une ancre (`id="plugins"`), et
  l'URL de retour des actions la désigne — sinon la page recharge en haut et le
  compte rendu reste hors de vue.
- **Critère facultatif** : seul `{champ ?}` l'est, et il lit le contexte. Écrire
  `{champ = #GET{x} ?}` ne rend rien facultatif — le `?` est pris pour un
  caractère de la valeur, et la boucle cherche `champ = 'non?'`. Pour filtrer
  depuis une variable, dire ce qu'on **écarte** : `{champ != #GET{x}}`, où un
  `x` vide ne retient rien et laisse donc tout passer. Une liste n'est pas une
  échappatoire : `#SET{x,non,oui}` est coupé sur sa virgule et `x` vaut `non`.
- **Un libellé qui porte un décompte** se calcule avec `#SET` avant l'appel, et
  par `#VAL{clef}|_T{#ARRAY{nb,#BALISE}}` — une chaîne de langue à arguments
  écrite directement dans un `#SET` rend une chaîne vide. Et il y faut une
  balise **simple** : `#ARRAY{nb,#GET{x}}` ajoute un niveau d'accolades que
  l'analyse ne suit plus, pour un « Argument manquant dans la balise SET » qui
  emporte la page entière. Quand le nombre vient d'une fonction et non d'un
  champ de boucle, faire la chaîne de langue en PHP
  (`dashboard_agent_maj_libelle()`).

## `#VAL|filtre` passe une chaîne vide, pas « rien »

SPIP compile `#VAL|mon_filtre` en `mon_filtre('')`. Une valeur par défaut
déclarée dans la signature **ne joue donc pas** — l'argument est bel et bien
passé — et la chaîne vide atterrit dans le premier paramètre.

`dashboard_waf_serie_parc($jours = 90)` en est mort à petit feu : le `''` valait
zéro, la fenêtre se réduisait au jour même, et la tendance du parc traçait deux
courbes vides. L'encadré était là, son JSON aussi, et seules les courbes
manquaient — rien, sur la page, ne le disait. Le parcours d'intégration ne
vérifiait pour ce graphique-là que sa *présence*, là où celui de la fiche d'un
site relisait déjà les pixels du canvas : le défaut a vécu dans cet écart.

La convention, que le reste du code suivait déjà : **un filtre appelé
`#VAL|nom` ne prend pas de paramètre, ou en prend un nommé `$rien`.** Ce nom est
le seul moyen de dire « je sais que SPIP me passe une chaîne vide, et je n'en
fais rien ». `tests/test_structure.php` refuse tout autre premier paramètre.

Et, ceinture et bretelles, une fenêtre de jours se normalise au lieu de se
supposer : `dashboard_waf_fenetre()` ramène zéro, le vide et le négatif à la
fenêtre par défaut.

## Une bascule a trois états, `dashboard_config()` n'en voit que deux

`dashboard_config()` traite la chaîne vide comme une absence et rend le défaut.
C'est juste pour un délai — un délai vide n'est pas un délai de zéro — et faux
pour tout ce qui peut être **vidé exprès** : une case décochée vaut la chaîne
vide, et se relirait donc cochée. Le réglage devient impossible à éteindre.

Deux fois le même piège, à deux endroits :

- l'adresse de SPIP Check, vidable pour n'offrir aucun dépôt
  (`dashboard_url_check_source()`) ;
- la synchronisation de fond (`dashboard_sync_auto()`), où s'ajoutait un défaut
  qui divergeait selon le lecteur : le génie lisait « on », le formulaire lisait
  vide. La case s'affichait décochée sur une install neuve où la tâche tournait
  bel et bien, et **le premier enregistrement l'éteignait** sans que personne
  l'ait demandé.

La règle : pour un réglage qui peut être vidé, passer par `lire_config(…, null)`
et distinguer les trois états — jamais réglé, réglé, vidé. Et n'avoir qu'un seul
lecteur, que le formulaire et le génie appellent tous les deux : deux défauts
écrits à deux endroits finissent toujours par diverger.

## Le fil d'Ariane d'un objet sans rubrique

Sans squelette à nous, SPIP retombe sur
`prive/echafaudage/hierarchie/objet.sans_rubrique.html`, qui fabrique le lien de
remontée **depuis le nom de la table** : `?exec=dashboard_sites`. Cette page
n'existe pas — le parc a la sienne, `?exec=dashboard` — et l'intitulé venait de
`texte_objets`, « Sites gérés », qui ne désigne rien d'atteignable.

Le fond est choisi par `prive/squelettes/hierarchie/<type-page>`, et `type-page`
vaut le nom de l'exec. D'où `prive/squelettes/hierarchie/dashboard_site.html`,
qui rend la remontée vers le parc et le titre du site — `#INFO_TITRE`, qui
échappe, parce que ce titre vient d'un site géré.

## Ce qui vient d'un site géré est inerte, toujours

L'échappement de SPIP n'est pas une protection contre l'injection de balisage :
`interdire_scripts()` laisse passer `<svg onload>` et `<details ontoggle>`. Or un
site géré peut être compromis, et son inventaire s'affiche dans l'espace privé de
la tour de contrôle, qui détient les secrets de tout le parc.

- **à l'entrée** : tout ce qu'un agent répond passe par `dashboard_inerte()`
  avant d'atteindre la base — `dashboard_synchroniser()`,
  `dashboard_enregistrer_plugins()`, `dashboard_journaliser()`,
  `dashboard_chantier_ecrire()` et `dashboard_enregistrer_waf_serie()`, qui ne
  laisse passer qu'une date et des entiers. Un nouveau champ venu de l'agent se
  range derrière l'un de ces cinq points, pas à côté ;
- **à l'affichage** : un champ SQL rendu tel quel (`#VERSION_SPIP`, `#PREFIXE`…)
  porte `|entites_html`. `|textebrut` et `|couper{N}` suffisent aussi — ils
  retirent les balises ; `|typo` ne suffit qu'à moitié (il ôte les attributs,
  garde la balise).

Le `phpinfo()` d'un site est la seule exception, parce que c'est du HTML par
nature : il va dans une `<iframe sandbox>`, et nulle part ailleurs. Le reste de
l'onglet *Serveur* se construit par `textContent` — aucun `innerHTML` ne reçoit
autre chose que `''`.

`tests/test_protocole.php` vérifie le filtre, `tests/integration/scenario.mjs`
le rendu, charge utile à l'appui.

## Cinq pièges du compilateur, tous rencontrés

1. **Une balise à accolades dans un argument composite** (`op/#ID/#GET{x}`)
   désorganise l'analyse : les balises voisines arrivent littéralement dans la
   sortie. Calculer l'argument à part avec `#SET`.

   Variante rencontrée depuis, et qui ne pardonne pas : `#INFO_TITRE{dashboard_site,#ENV{id_dashboard_site}}`
   — un argument **littéral** suivi d'une balise à accolades. SPIP perd le nom
   de la balise en chemin (« Argument manquant dans la balise INFO_ ») et la
   page entière tombe. L'échafaudage du core écrit
   `#INFO_TITRE{#ENV{objet},#ENV{id_objet}}`, dont les deux arguments sont des
   balises, et passe. Le remède est le même : passer par une boucle, ou calculer
   à part. Aucun contrôle statique ne le voit — `#BOUTON_ACTION{libellé,#URL_ACTION_AUTEUR{…},btn}`
   a la même forme et fonctionne —, c'est le parcours d'intégration qui l'a
   attrapé, sur trois pages d'un coup.
2. **Un filtre à accolades dans l'argument d'un autre filtre**
   (`|lien_ou_expose{…,#GET{x}|=={non},…}`) est relu comme la suite de la chaîne
   de filtres : SPIP cherche alors un filtre nommé `non,btn btn_secondaire`.
   Même remède.
3. **SPIP compile les balises jusque dans les commentaires** — les commentaires
   JavaScript compris. Un `#PAGINATION` écrit dans un `/* … */` pour
   l'explication produit une erreur de compilation que rien, sur la page, ne
   rattache au commentaire fautif.
4. **Deux balises dans un même bloc optionnel** : la dernière en est le sujet,
   la première n'est plus interprétée et ses parenthèses ressortent telles
   quelles. `[<a href="(#URL_SITE)">(#URL_SITE|couper{48})</a>]` produisait
   `href="(https://exemple.org/)"` — une adresse relative, du point de vue du
   navigateur, qui ramenait sur l'espace privé du parc. Donner ses crochets à
   chaque balise, ou calculer à part avec `#SET`.

5. **Ce qui suit `</BOUCLE_x>` n'est pas la fin du squelette** : jusqu'au
   `<//B_x>`, c'est la branche que SPIP rend **quand la boucle n'a rien
   trouvé**. Les deux `<script src>` de Chart.js y avaient atterri, en bas de
   fichier, à l'endroit qui semblait naturel — et les graphiques ne se
   traçaient que sur un parc dont les tables sont absentes, c'est-à-dire
   jamais. Rien sur la page ne le dit : le bloc est là, son JSON aussi, seul le
   tracé manque.

`tests/test_structure.php` vérifie les cinq — le quatrième en refusant toute
valeur d'attribut qui s'ouvre sur une parenthèse littérale, le cinquième tout
`<script src>` rangé dans une branche « sinon ». Il refuse aussi tout
`#ARRAY{…#GET{…}}`, variante du premier.

## Une case à cocher ne donne aucun droit

Les actions de parc — relire les dépôts, synchroniser ou vider une sélection,
mettre à jour l'agent — se pilotent depuis le navigateur, un site par requête
(`action/dashboard_parc.php`, `javascript/dashboard_parc.js`). D'où une règle
qui tient tout :

**Aucune adresse d'action n'est fabriquée en JavaScript.** Le squelette écrit
une file par opération, chacune ne contenant que les sites que l'utilisateur a
le droit d'opérer, et chaque adresse est signée pour son couple
`operation/id_site`. La case à cocher, elle, ne porte qu'un identifiant : elle
**désigne**, elle n'autorise pas. Un site coché absent de la file est écarté.

Trois écritures doivent donc s'accorder, et rien ne les relie à l'exécution : le
bouton (`data-parc-action="sync"`), la file (`data-parc-file="sync"`) et le
`case 'sync':` de l'action. Qu'une seule manque et le bouton ne fait rien, sans
erreur ni message — `tests/test_structure.php` les confronte.

Le contrat de réponse est le même pour les quatre opérations : tant que
`termine` est faux, le pilote rappelle **la même adresse**. C'est ainsi qu'une
opération en plusieurs temps — six dépôts à relire, un chantier de cinq étapes —
tient dans une boucle qui n'en sait rien.

## Porter un outil tiers, sans le piloter

SPIP Check (`git.spip.net/technova69/spip-check`, GPL 3) est un contrôle
d'intégrité autonome, déposé à la racine d'un site et ouvert dans le navigateur.
Le parc sait le déposer, en lire la version et le retirer
(`inc/dashagent_check.php`, encadré `#check` de la fiche d'un site) — et **rien
de plus**.

La raison est dans l'outil : il s'autorise sur une **session** d'administrateur
du site géré, là où l'agent s'authentifie par signature. Contourner cette
autorisation pour le piloter à distance reviendrait à poser une porte dérobée
sur tout le parc. L'agent se borne donc à ce que le FTP faisait à la main.

Trois différences avec le `spip_loader.php`, dont tout le reste est copié :

- **il se retire** (`check_retirer`), parce qu'un mégaoctet de code capable de
  lire tout le disque n'a rien à faire en permanence à la racine web. Et il se
  **supprime**, seule exception au « jamais d'effacement » qui gouverne le reste
  de l'agent : il se retélécharge d'un clic, et un script dont le nom commence
  par un point est exactement ce que SPIP Check signale comme dissimulation —
  lui laisser sa dépouille sous ce nom lui fabriquerait son constat. Le
  `spip_loader.php` a reçu le même bouton, pour les mêmes raisons ;
- **sa version se lit dans la queue du fichier**, pas dans l'en-tête : les
  bibliothèques embarquées occupent tout le début du livrable. On relit
  `_DASHAGENT_CHECK_QUEUE` octets à la fin, pas le mégaoctet entier ;
- **son adresse de téléchargement désigne une branche d'un dépôt tiers**, pas
  une archive officielle versionnée comme celle du core. Chaque dépôt sert donc
  ce qui a été poussé sur cette branche ; c'est un réglage, et un parc de
  production préférera une release ou un miroir interne. Vidée, l'encadré le dit
  et ne propose rien.

Le dépôt publie les deux orthographes, identiques. On prend celle à **souligné**
des deux côtés : elle passe sur les hébergements qui réécrivent les noms à
tiret, et c'est celle de son voisin de palier, `spip_loader.php`.

L'autorisation `op_check` lui est propre : `op_loader` ne l'ouvre pas, et
réciproquement.

Son adresse désignant une branche, le parc peut **épingler une empreinte**
(`sha256_spip_check`) : l'agent refuse alors tout fichier qui n'y répond pas, et
rend celle qu'il a reçue — sans quoi mettre l'épingle à jour après une
publication amont demanderait d'aller télécharger le mégaoctet à la main pour le
hacher. Vide par défaut, parce qu'une épingle posée d'office sur une branche
bloquerait le dépôt au premier commit venu. Le https atteste du transport,
jamais du contenu.

## Franchir la porte n'est pas s'authentifier

Un site géré derrière un `htpasswd` renvoie un **401 avant que PHP ne
s'exécute** : la signature n'est jamais examinée, puisque le code qui la
vérifie n'est jamais atteint. D'où deux colonnes sur la fiche d'un site —
`auth_user` en clair, `auth_pass` chiffré comme le secret partagé — jointes en
en-tête `Authorization: Basic` par `dashboard_auth_http()`.

Les deux ne se confondent pas, et le code le dit : les identifiants **ouvrent
la porte**, la signature **authentifie l'appel**. Un appelant qui connaîtrait le
htpasswd sans le secret franchirait le 401 pour se faire refuser par
`dashagent_verifier_signature()`.

Deux règles qui tiennent à la fuite d'identifiants :

- **en-tête, jamais dans l'adresse.** `https://user:pass@site/` finit dans les
  journaux du serveur, dans les référents et dans les messages d'erreur ;
- **Basic et rien d'autre** (`CURLAUTH_BASIC`). `CURLAUTH_ANY` ferait un premier
  appel à blanc pour découvrir la méthode, et accepterait qu'un serveur réclame
  une authentification qui laisse fuir davantage.

Tout ce qui part vers un agent passe par `dashboard_http_post()` — l'appel signé
et le rapatriement de sauvegarde. Un nouveau chemin vers un site géré se range
derrière elle, pas à côté, sans quoi il ignorera le htpasswd.

## « Relire les dépôts » ne relisait rien

SVP ne télécharge pas le catalogue d'un dépôt. Il appelle
`copie_locale($url, 'modif')`, qui ne va le chercher **que si le serveur le dit
plus récent que la copie locale** — rangée non pas sous `tmp/`, où on la
cherche, mais sous **`IMG/distant/xml/<nom>-<hachage>.xml`**.

Or cette copie voit sa date rafraîchie à chaque vérification, même quand rien
n'est rapatrié. D'où un verrou qui se referme tout seul :

1. une relecture tombe pendant qu'un cache en frontal sert encore l'ancien
   fichier → 304, copie conservée, **date poussée à maintenant** ;
2. la date de la copie dépasse désormais celle du catalogue réellement publié ;
3. toute relecture ultérieure reçoit donc un 304, **pour toujours**.

Rencontré en vrai sur un site du parc : six heures de relectures, une date de
fraîcheur qui avançait à chaque fois, et un catalogue vieux d'une publication
entière. Rien ne le signalait — ni SVP, qui rend `true`, ni le parc, qui
comptait ses mises à jour sur un inventaire faux. Vider `tmp/`, supprimer et
recréer le dépôt, changer son adresse : aucun de ces gestes n'y touche. Le
changement d'adresse encore moins que les autres, puisque SVP n'utilise pas
celle qu'on déclare — il en dérive des variantes (`…thin.spip-<branche>.xml`,
`…thin.xml`, puis l'originale) et prend la première qui répond en HEAD.

**Forcer veut dire forcer** : depuis l'agent 1.0.22, `age_max = 0` efface la
copie locale de chaque variante et vide `sha_paquets` avant d'appeler SVP. Sans
fichier local, `copie_locale()` n'a plus de date à comparer.

Et comme partout ailleurs ici, on va **constater** plutôt que conclure : la
copie ayant été effacée avant l'appel, une copie revenue prouve le
téléchargement, et rien de revenu prouve le contraire — quoi que SVP ait
répondu. `dashagent_svp_relecture_constat()` porte cette règle, sans réseau ni
base, donc vérifiable. `dashboard_depots_constat()` la relit côté tour, et un
agent d'avant la 1.0.22 qui ne rend aucun constat vaut « on ne sait pas », pas
« c'est cassé ».

## Un fichier de langue rend son tableau — et remplit encore la globale

SPIP 4.4 déprécie `$GLOBALS[$GLOBALS['idx_lang']] = [ … ];` au profit d'un
`return [ … ];`. `lire_fichier_langue()` fait `include $fichier`, prend le
retour s'il est un tableau, et ne retombe sur la globale qu'en signalant la
dépréciation — un avertissement par module et par langue, dans les journaux de
chaque site du parc.

**Mais s'en tenir au retour casserait les SPIP d'avant la 4.4**, dont le
chargeur ne regarde que la globale : le module perdrait toutes ses chaînes, et
l'espace privé afficherait ses clefs brutes. Nos paquets se déclarent
`[4.1.0;4.*]`, donc les deux formes cohabitent, départagées par la fonction qui
a apporté la nouvelle :

```php
$lang = [ … ];

if (!function_exists('lire_fichier_langue') && isset($GLOBALS['idx_lang'])) {
	$GLOBALS[$GLOBALS['idx_lang']] = $lang;
}

return $lang;
```

Sur 4.4, la condition est fausse et rien ne traîne — la clef que le chargeur
pose dans `idx_lang` y est temporaire et n'est jamais relue. Sur 4.1 à 4.3, la
globale est remplie comme avant. Les deux chemins sont éprouvés en dur, chacun
dans son propre processus : PHP hisse les déclarations de fonction, si bien
qu'un `function_exists()` testé après coup dans le même script répond déjà vrai
— le premier essai s'y est laissé prendre.

Le garde-fou `if (!defined('_ECRIRE_INC_VERSION')) { return; }` **n'a plus sa
place ici** : un `return;` nu rend `null`, que SPIP refuse. Il journalise alors
« Fichier de langue incorrect » et rend un tableau vide. Les fichiers de langue
du core n'en portent aucun : ils ne font que rendre un tableau.

`tests/test_structure.php` exige les trois : un `return $lang;`, jamais de
`return;` nu, et toute écriture de globale gardée par le `function_exists()`.

Ce que la conversion ne touche pas : le test lit les clefs **au texte**, par une
expression régulière sur `^\t'clef' =>`, jamais par `include`. Le décompte des
références résolues n'a donc pas bougé — c'est la première chose à regarder
après un tel remaniement, un test qui perd son sujet ne le disant jamais.

## Prévenir quelqu'un qui ne regarde pas la page

Le Web Push est le seul chemin vers un navigateur fermé, et le seul vers un
téléphone. `inc/dashboard_push.php` l'écrit en PHP nu — `ext-openssl` et
`hash_hkdf()` suffisent, aucun Composer —, et ce fichier ne parle à personne :
il fabrique des octets. C'est ce qui le rend vérifiable.

**Le contrôle qui prouve quelque chose est le vecteur d'essai de la RFC 8291
§5**, rejoué dans `tests/test_protocole.php` : en-tête, chiffré et étiquette
GCM identiques à la référence, puis l'aller-retour qui relit le texte. Sans
lui, un chiffrement faux produirait des octets tout aussi plausibles que le
bon, et on ne s'en apercevrait que par un navigateur resté muet — six mois
plus tard, sans rien pour le relier à la cause. D'où les paramètres `$sel` et
`$ephemere` de `dashboard_push_chiffrer()`, et l'existence même de
`dashboard_push_dechiffrer()`, qui n'a aucun usage en service : une charge
qu'on ne sait pas relire est une charge qu'on n'a pas vérifiée.

Trois pièges, tous silencieux :

- **OpenSSL rend X et Y sans remplissage.** Une coordonnée dont le premier
  octet est nul revient sur trente et un octets, et concaténer sans compléter
  décale tout le point. Une fois sur deux cent cinquante-six — donc jamais en
  développement, et un jour en production. D'où `dashboard_push_point()`, et
  jamais de concaténation à la main ;
- **une signature ECDSA sort en DER**, avec deux entiers de longueur variable
  qui gagnent un octet nul de tête quand leur bit de poids fort est armé. JOSE
  en veut deux de trente-deux octets, collés. Passer le DER tel quel produit
  une signature que rien ne rejette ici et que **tous** les services de
  distribution refusent, avec un « invalid JWT » qui ne dit pas lequel des deux
  bouts est en cause. Le test en fait deux cents et **vérifie que plusieurs
  longueurs de DER ont été rencontrées** — sans cela il passerait au vert sans
  avoir rien éprouvé ;
- **l'ordre des deux clefs publiques** dans `'WebPush: info'` : l'abonné
  d'abord, l'émetteur ensuite. Les intervertir donne une clef parfaitement
  valide que le navigateur n'a aucun moyen de retrouver, et le message est
  rejeté sans que rien, côté émetteur, n'ait l'air d'avoir échoué.

La règle du silence s'applique mot pour mot au verdict d'un envoi
(`dashboard_push_verdict()`, pure et donc vérifiable) : **404 et 410 sont des
réponses** — l'abonnement est mort, on le supprime, le navigateur en
refabriquera un ; **500 et l'absence de réponse sont des silences** — on
compte, on ne conclut pas. Supprimer un abonnement sur un silence reviendrait
à débrancher le webmestre parce que le réseau a hoqueté.

### Un service worker s'enregistre par une adresse, pas par un fichier

Le chemin d'un plugin porte son numéro de version. Enregistrer le service
worker par ce chemin fabriquerait **un enregistrement de plus à chaque mise à
jour**, les précédents restant actifs avec leurs abonnements — des
notifications en double, puis en triple. D'où `spip.php?action=dashboard_sw`,
adresse qui ne bouge pas.

Cette action **n'est pas signée**, et ne doit pas l'être : le navigateur
revient chercher ce fichier tout seul pour savoir s'il a changé, longtemps
après la visite qui l'a enregistré, et une adresse signée aurait expiré. Ce
qu'elle sert est du code public, le même pour tous.

Sa portée est **l'espace privé**, pas la racine : un seul service worker peut
tenir une portée donnée, et prendre `/` priverait un autre plugin de la
sienne. Le nôtre n'a de toute façon aucun gestionnaire `fetch` — il ne sert
qu'à recevoir.

La paire VAPID se fabrique au premier besoin et **ne se regénère jamais** :
les abonnements pris par les navigateurs sont liés à la clef publique qui leur
a été présentée. En changer les invaliderait tous d'un coup, sans que personne
ne s'en aperçoive avant la première alerte non reçue.

## Une alerte qui se répète est une alerte qu'on n'ouvre plus

`inc/dashboard_alertes.php` n'écrit **que s'il y a du nouveau**. Un
récapitulatif quotidien identique à celui de la veille cesse d'être lu au bout
d'une semaine, et le jour où il porte enfin quelque chose, personne ne
l'ouvre.

L'empreinte porte sur ce qui est en retard **site par site**, jamais sur des
compteurs globaux : deux sites qui échangent leurs rôles laisseraient un total
inchangé, et le silence serait alors un mensonge. Elle exclut le *détail* des
pannes — « timeout après 30 s » puis « connexion refusée » décrivent la même
panne, et la feraient repartir chaque jour — mais retient leur *genre*.

Deux conséquences qu'on oublie en écrivant ce genre de chose :

- **l'empreinte ne se mémorise qu'après un envoi réussi.** L'enregistrer
  quand rien n'est parti ferait taire l'alerte pour de bon : la nouveauté
  aurait été consommée sans être dite, et il faudrait qu'un *autre* changement
  survienne pour que le canal se réveille ;
- **une levée d'alerte est aussi une nouvelle.** Sans elle, on resterait sur
  la dernière mauvaise nouvelle sans jamais savoir qu'elle est périmée. Un
  parc neuf fait exception : son empreinte connue est vide alors que celle
  d'un parc sain ne l'est pas, et la première alerte serait « tout va bien »,
  ce qui ne se demande pas.

Tout est **éteint par défaut**, liste de destinataires comprise : une tour de
contrôle qui se met à écrire à quelqu'un dès son installation est une tour
qu'on désinstalle. La bascule a les trois états habituels.

Ce qui vient d'un site géré reste inerte **hors du HTML aussi** :
`dashboard_alerte_nom()` neutralise les caractères de contrôle d'un titre de
site. Dans un message en texte brut, ce sont les retours à la ligne qui
mordent — ils fabriqueraient de fausses lignes dans la liste et, dans un sujet
de courriel, un en-tête supplémentaire.

Enfin, les clefs de langue des pannes sont écrites **en toutes lettres** dans
un tableau, jamais composées par concaténation : une clef fabriquée échappe au
contrôle qui vérifie que toute référence de langue existe, et la faute de
frappe se lirait dans l'alerte elle-même.

## Faire travailler SPIP sans visiteurs

SPIP n'a pas d'horloge à lui : sa file de travaux est relancée à la fin de
chaque visite. Une tour de contrôle que personne ne visite ne synchronise
jamais son parc — et c'est précisément le site dont on attend qu'il travaille
tout seul.

`spip.php?action=cron` répond à cela partout où l'on a un vrai cron Unix. Sur
un mutualisé, il n'y en a pas, et le frontal coupe la requête avant qu'une
synchronisation de dix sites ait fini — le même frontal que partout ailleurs
ici. D'où `outils/cron.php`, qui amorce SPIP **en ligne de commande** et
appelle `cron()` directement.

Il vit dans l'espace web, puisque c'est la seule chose qu'une tâche planifiée
d'hébergeur sait exécuter, et **refuse donc tout autre SAPI que la ligne de
commande**.

Trois défauts trouvés en l'éprouvant sur un SPIP 4.4 réel, aucun deviné :

- **la boucle s'emballait** : soixante-huit mille tours en vingt-cinq
  secondes. Quand il reste des travaux qu'un passage n'a pas épuisés, SPIP
  écrit « dès que possible » dans son fichier d'échéance
  (`queue_update_next_job_time(0)`), et la boucle le relisait sans fin. Le
  temps ne borne pas un tour qui coûte une milliseconde : il faut **deux
  bornes**, une de durée et une de nombre de tours ;
- **l'heure de la file était gelée.** Elle compare les dates des travaux à
  `$_SERVER['REQUEST_TIME']`, que rien ne met à jour hors du web. Posée une
  fois à l'amorçage, elle faisait conclure, après trois minutes de
  synchronisation, qu'il restait six secondes à attendre — six secondes
  passées depuis longtemps — et le reste du parc était remis à la fois
  d'après ;
- **un génie tiers qui explose emportait les nôtres.** Celui de SVP lève une
  `ValueError` sur un catalogue injoignable. On l'attrape et on continue, ce
  qui est sans danger : SPIP réinsère un travail au statut « en cours » *avant*
  de l'exécuter et ne le repasse à « planifié » qu'une fois fini, si bien qu'un
  travail mort en route n'est pas repris au tour suivant et que la boucle ne
  peut pas s'y enfermer. Sortie en code 1, pour que l'hébergeur le fasse
  remonter dans son rapport.

`_DIRECT_CRON_FORCE` se pose **sous garde** : SPIP la définit lui-même quand
la file déborde.

### Un hébergeur qui ne descend pas sous l'heure

Une seule tâche en souffre, `dashboard_chantiers`, déclarée à deux minutes —
le filet qui reprend une mise à jour abandonnée pendant que le site géré est à
mi-chemin. Les trois autres sont à six ou vingt-quatre heures.

Mais le retard horaire n'était pas le pire : **le script sortait de sa boucle**
dès que la file annonçait n'avoir plus rien d'échu, et la tâche replanifiée à
deux minutes rendait l'attente positive. Le budget `--duree` n'était jamais
consommé — soixante secondes de chantier par heure, puis cinquante-neuf
minutes de sommeil.

D'où `--taches`, qui force les tâches nommées à chaque tour sans regarder leur
échéance. `cron($taches)` les met en file **dès que possible**
(`inc_genie_dist()`), ce qui est exactement le levier cherché.

Deux choses apprises en l'éprouvant :

- **un nom sans `genie/<nom>.php` tue le passage.** La file appelle
  `charger_fonction($nom, 'genie', false)`, dont le troisième argument à faux
  veut dire « meurs si tu ne trouves pas » : SPIP rend sa page d'erreur HTML
  sur la sortie standard, que l'hébergeur poste par courriel, et aucune tâche
  suivante ne passe. Le script vérifie donc d'abord avec le même appel en mode
  tolérant, et ignore un nom inconnu en le disant ;
- **une tâche forcée qui n'a rien à faire rend la main tout de suite**, et
  consommerait les tours prévus dans la seconde. Une pause d'une seconde entre
  deux tours forcés rend au budget de temps son sens — et elle ne s'applique
  qu'aux tours forcés, un tour immédiat sans forçage signifiant qu'il reste du
  travail échu.

La mesure, sur un SPIP réel, est ce qui tranche : vingt tours forcés, vingt
passages du génie ; sans l'option, deux tours et aucun passage.

## Le serveur intégré de PHP n'est pas exempt d'opcache

`php -S` tourne sous le SAPI **`cli-server`**, pas `cli` : c'est donc
`opcache.enable` qui décide de l'activation, et non `opcache.enable_cli` qu'on
croit seul en cause. Là où opcache est actif par défaut,
`opcache.revalidate_freq` vaut deux secondes.

Conséquence pour le parcours d'intégration, qui modifie des fichiers du site
pendant qu'il tourne : **un fichier réécrit peut rester invisible deux
secondes**, l'ancien code continuant d'être exécuté. Un fichier créé pour
l'occasion ne pose pas ce problème — c'est la réécriture qui mord.

`tests/integration/scenario.mjs` ramène le budget d'une tranche de sauvegarde à
zéro en réécrivant `config/mes_options.php`. Une fois sur deux, l'export se
faisait d'un seul tenant et le contrôle échouait sans que rien, ni dans le
journal ni sur la page, ne désigne opcache. Deux parades, parce que le serveur
peut aussi être lancé à la main : `-d opcache.revalidate_freq=0` au démarrage
dans `executer.sh`, et un battement de deux secondes et demie après chaque
réécriture.

## Tests à passer avant tout commit

```bash
php tests/test_protocole.php    # protocole, masquage, chantiers
php tests/test_structure.php    # manifestes, tables, contrat d'API, squelettes
php tests/test_depot.php        # catalogue SVP et archives publiées
```

Le parcours sur SPIP réel (`tests/integration/executer.sh`) est à relancer dès
qu'on touche à une opération distante, à une table ou à un squelette de la fiche
d'un site. Voir `tests/integration/README.md`.

## Langue

Le code, les commentaires, les messages de commit et la documentation sont en
français. Les chaînes affichées passent par `lang/`, en français et en anglais.
