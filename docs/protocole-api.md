# Protocole d'API — version 1.0

Le tableau de bord et l'agent dialoguent par un unique point d'entrée HTTP.

```
POST https://exemple.org/spip.php?action=dashagent
Content-Type: application/x-www-form-urlencoded
```

## Champs de la requête

| Champ | Format | Rôle |
|---|---|---|
| `op` | `[a-z0-9_]{1,64}` | opération demandée |
| `args` | chaîne JSON, ou vide | arguments de l'opération, 64 Kio maximum |
| `ts` | entier | horodatage Unix de l'émission |
| `nonce` | 16 à 64 caractères hexadécimaux | valeur à usage unique |
| `sig` | 64 caractères hexadécimaux | signature HMAC-SHA256 |

## Calcul de la signature

La base signée est la concaténation des champs **tels qu'ils sont transmis**,
séparés par des sauts de ligne :

```
base = op + "\n" + args + "\n" + ts + "\n" + nonce
sig  = hex( HMAC-SHA256( base, secret_partagé ) )
```

On signe l'octet transmis, jamais une re-sérialisation : c'est ce qui garantit
qu'aucune divergence de canonicalisation JSON entre les deux extrémités ne peut
invalider une requête pourtant légitime.

Exemple en PHP :

```php
$args  = json_encode(['cibles' => ['pages', 'images']], JSON_UNESCAPED_SLASHES);
$ts    = time();
$nonce = bin2hex(random_bytes(16));
$sig   = hash_hmac('sha256', "purger\n$args\n$ts\n$nonce", $secret);
```

## Contrôles effectués par l'agent, dans l'ordre

1. un secret partagé est configuré (sinon `non_appaire`) ;
2. l'adresse IP appelante est dans la liste blanche, si elle est renseignée ;
3. les champs sont présents et bien formés ;
4. `ts` est dans la fenêtre de tolérance (300 s par défaut) ;
5. la signature correspond, comparée en temps constant (`hash_equals`) ;
6. le nonce n'a jamais servi — l'insertion en base fait foi, pas un `SELECT` ;
7. l'opération est activée dans la configuration locale du site.

Tout échec est journalisé côté agent avec l'adresse d'origine.

## Réponse

Succès :

```json
{
  "ok": true,
  "protocole": "1.0",
  "agent": "1.0.0",
  "horloge": 1731000000,
  "duree_ms": 412,
  "data": { "...": "..." }
}
```

Échec :

```json
{
  "ok": false,
  "protocole": "1.0",
  "agent": "1.0.0",
  "horloge": 1731000000,
  "erreur": {
    "code": "signature_invalide",
    "message": "Signature invalide.",
    "detail": {}
  }
}
```

## Codes d'erreur

| Code | HTTP | Signification |
|---|---|---|
| `non_appaire` | 503 | aucun secret configuré sur l'agent |
| `ip_refusee` | 403 | adresse hors de la liste blanche |
| `requete_incomplete` | 400 | champ obligatoire manquant |
| `operation_invalide` | 400 | nom d'opération mal formé |
| `nonce_invalide` | 400 | nonce mal formé |
| `args_trop_longs` | 413 | arguments au-delà de 64 Kio |
| `args_invalides` | 400 | JSON illisible |
| `horloge` | 403 | horodatage hors fenêtre ; la réponse contient `horloge_agent` |
| `signature_invalide` | 403 | signature non conforme |
| `rejeu` | 409 | nonce déjà consommé |
| `operation_desactivee` | 403 | opération refusée par la configuration du site |
| `operation_echouee` | 422 | opération tentée mais en échec ; `detail` porte le diagnostic |
| `exception` | 500 | erreur interne inattendue |

## Opérations

### `ping`

Toujours autorisée dès que l'agent est appairé. Sert à vérifier l'appairage et
à mesurer l'écart d'horloge.

Retour : `spip`, `php`, `capacites`.

### `infos`

Inventaire complet. Arguments facultatifs : `caches` (booléen), `plugins` (booléen).

Retour : `infos` contenant `site`, `spip`, `serveur`, `base`, `plugins`,
`caches`, `capacites`.

Chaque entrée de `plugins` porte : `prefixe`, `nom`, `version`,
`version_disponible`, `maj_disponible`, `etat`, `dossier`, `chemin`,
`distribue`, `source` (`git` / `svp` / `manuel` / `introuvable`), `inscriptible`.

`version_disponible` provient des dépôts SVP locaux quand SVP est installé sur
le site géré ; l'agent n'ouvre aucune connexion sortante pour la calculer.

Le bloc `spip` porte, depuis l'agent 1.0.26, deux champs sur la mise à jour du
noyau que le site connaît **de lui-même** :

| Champ | Sens |
|---|---|
| `maj_disponible` | la version que le génie `mise_a_jour` du core a relevée, ou une chaîne vide |
| `maj_controlee` | la date du dernier relevé réussi, au format SQL |

Ils ne coûtent aucune connexion sortante : le core du site les a déjà écrits dans
ses metas, et c'est cette source qui prévient son webmestre par courriel. L'agent
lit `derniere_maj_notifiee` en premier — un numéro propre, mais qui n'existe que
si une notification est réellement partie —, puis retombe sur `info_maj_spip`,
écrite à chaque passage. Cette seconde meta contient du **HTML** : l'agent en
extrait le numéro de version et ne fait jamais voyager le balisage.

`maj_controlee` est la date de modification du cache que `info_maj_cache()` écrit,
et seulement quand la lecture a abouti : c'est donc la fraîcheur de l'avis, pas
celle de la tentative. Elle importe, parce que **ce génie ne passe que toutes les
soixante-douze heures** : l'avis du site peut avoir trois jours, et la tour ne le
retient qu'à défaut du sien.

`waf_serie` s'ajoute quand le site a le plugin SPIP WAF, et vaut `null` sinon :
`jours` (la profondeur demandée, quatre-vingt-dix au plus), `depuis` (le premier
jour rendu) et `points`, une entrée par journée — `jour` (`AAAA-MM-JJ`),
`requetes`, `ips`, `bans`. La série est **continue** : les journées sans
événement valent zéro plutôt que de manquer, faute de quoi une courbe relierait
deux jours distants comme s'ils se suivaient.

Elle est rendue avec l'inventaire, et non par `waf_resume` : `waf_resume` vide
d'abord la file d'événements du pare-feu, ce qui n'a rien à faire dans une
synchronisation automatique. `waf_serie` ne fait que compter, et ne relève donc
d'aucune autorisation particulière — l'inventaire n'est déjà rendu qu'à un
appelant signé.

### `purger`

Argument : `cibles`, tableau parmi `pages`, `squelettes`, `images`, `css_js`,
`sessions`, ou `tout`.

`tout` couvre pages, squelettes, images et CSS/JS — **pas** les sessions, dont
la purge déconnecterait tous les visiteurs connectés.

Retour : `purge` avec le nombre de fichiers supprimés par cible.

### `sauvegarde_creer`

Arguments : `sans_statistiques` (booléen), `exclure` (tableau de tables),
`decoupee` (booléen), `reprendre` (identifiant d'un export en cours).

Produit un export SQL gzip dans `tmp/dashagent/sauvegardes/`. Retour :
`sauvegarde` avec `identifiant`, `fichier`, `octets`, `sha256`, `date`,
`tables`, `tranches`, `duree_ms`.

**Sans `decoupee`, l'export se fait d'un seul tenant**, comme avant la 1.0.21 de
l'agent : c'est ce qu'attend une tour de contrôle ancienne, à qui une tranche et
un `termine` à faux qu'elle ne sait pas lire feraient croire la sauvegarde
faite.

**Avec `decoupee`**, l'agent exporte pendant `_DASHAGENT_SAUVEGARDE_BUDGET`
secondes (vingt par défaut) et rend la main. La réponse porte alors :

| Clef | Sens |
| --- | --- |
| `termine` | faux tant qu'il reste des tables à exporter |
| `reprendre` | identifiant à renvoyer dans l'appel suivant |
| `avancement` | `tables`, `tables_total`, `table`, `lignes`, `octets`, `tranches` |

Le contrat est celui des chantiers et des actions de parc : tant que `termine`
est faux, on rappelle **la même opération**, en ajoutant `reprendre`.

Le budget n'est pas calé sur ce que PHP tient, mais sur ce qu'un frontal
supporte : le `first_byte_timeout` d'un Varnish vaut soixante secondes par
défaut, et un CDN d'hébergeur ne l'expose nulle part.

Entre deux tranches, l'agent garde un état à côté du fichier partiel
(`sauvegarde-<identifiant>.etat.json`) : table en cours, rang atteint, et
**taille du fichier au dernier point de contrôle**. C'est cette taille qui rend
la reprise sûre — un processus tué en plein milieu d'une tranche laisse des
octets que l'état ne mentionne pas, et ils sont tronqués avant qu'on reprenne.
Sans cela, la reprise écrirait à la suite d'un membre gzip inachevé, ou
rejouerait des lignes déjà exportées : une archive plausible, et fausse.

Chaque tranche ajoute **un membre gzip** au fichier. Un gzip est une suite de
membres (RFC 1952) et `gunzip` les relit à la file ; côté tour de contrôle,
`dashboard_sauvegarde_verifier()` les parcourt un par un.

Un `reprendre` inconnu est refusé. Un appel `decoupee` **sans** `reprendre`
adopte l'export déjà en chantier s'il porte les mêmes options et date de moins
d'une heure : c'est ce qui sauve le travail quand c'est la réponse de la
première tranche qui s'est perdue.

Il n'y a pas de verrou : deux demandes lancées en même temps adoptent le même
export et se marchent dessus. L'issue n'est pas une archive fausse — chaque
tranche ramène le fichier à *son* point de contrôle avant d'écrire, si bien que
l'une des deux se heurte à un fichier plus court que le sien, ou produit un
membre que la vérification au rapatriement rejette. Le coût est un export à
refaire, jamais une sauvegarde qu'on croirait bonne.

### `sauvegarde_lister`, `sauvegarde_supprimer`

Listage et suppression, par `identifiant`.

`sauvegarde_lister` rend aussi `inacheves` : les exports restés en plan
(`.partiel`), sans identifiant puisqu'ils ne se téléchargent ni ne se
restaurent. Ils ne valent rien comme sauvegarde et beaucoup comme indice —
leur présence prouve que PHP a été interrompu en cours d'écriture, ce qui
désigne une limite du site géré plutôt que le cache en frontal. Les agents
d'avant la 1.0.17 n'en rendent pas.

Chaque entrée porte depuis la 1.0.21 un booléen `reprise` : un export découpé
**en cours** n'a pas été tué, il attend sa tranche suivante. Les confondre
enverrait chercher une panne de `max_execution_time` là où il n'y en a pas.

### `sauvegarde_telecharger`

Argument : `identifiant`. **Ne renvoie pas de JSON** mais le fichier lui-même,
en `application/gzip`, avec l'empreinte dans l'en-tête `X-Dashagent-Sha256`.

#### Le forçage, et ce qu'il permet d'affirmer

`age_max = 0` ne veut pas seulement dire « quel que soit l'âge ». Depuis la
1.0.22, l'agent **efface la copie locale du catalogue** — sous
`IMG/distant/xml/`, une par variante d'adresse — et vide `sha_paquets` avant
d'appeler SVP.

Sans quoi le forçage ne force rien : SVP passe par `copie_locale($url, 'modif')`,
qui ne télécharge que si le serveur annonce le fichier plus récent que la copie,
et cette copie est redatée à chaque vérification même sans rapatriement. Une
seule vérification faite pendant qu'un cache en frontal servait l'ancien fichier
suffit à la rendre définitivement « plus récente » que le catalogue publié.

La réponse porte alors une clef `relecture` :

| Clef | Sens |
| --- | --- |
| `constat` | `lu` (sans forçage), `telecharge`, ou `sans_telechargement` |
| `fiable` | faux quand la copie effacée n'est pas revenue |
| `change` | l'empreinte du catalogue a changé |
| `sha` | empreinte après relecture |
| `message` | ce qu'il y a à dire, vide quand tout va bien |

Un catalogue inchangé rend la même empreinte : `change` à faux n'est pas un
échec. Ce qui l'est, c'est `fiable` à faux — la copie a été effacée, SVP a rendu
la main, et rien n'a été rapatrié.

Les agents d'avant la 1.0.22 ne rendent pas cette clef. Son absence vaut « on ne
sait pas » : la tour se tait, comme avant.

### `plugin_maj_preflight`

Argument : `prefixe`. Indique les stratégies utilisables (`git`, `zip`), si le
répertoire est inscriptible, et l'URL d'archive connue des dépôts SVP.

### `plugin_maj`

Arguments : `prefixe` (obligatoire), `url_archive` (https), `sha256`,
`strategie` (`git` ou `zip`).

Sans `url_archive` ni stratégie imposée, l'agent choisit : `git` si le plugin
est un dépôt de travail et que `exec()` est utilisable, sinon l'archive connue
de SVP.

Une archive ZIP est refusée si son `paquet.xml` ne déclare pas le préfixe
attendu : une URL erronée ne peut donc pas écraser un plugin par un autre.

La stratégie `zip` n'écrase pas non plus le dossier existant : elle installe la
nouvelle version dans un dossier portant son numéro, écarte l'ancien sous
`.<nom>.dashagent-AAAAMMJJHHMMSS`, et déclare le nouveau dossier à SPIP. La
réponse porte alors `dossier_avant`, `dossier` et `dossier_relatif` (chemin du
nouveau dossier relatif à `plugins/`), en plus de `version_avant`,
`version_apres`, `version_archive` et `sauvegarde`.

### `depots_actualiser`

Argument : `age_max` (secondes). Fait relire au site géré le catalogue **d'un**
dépôt — le plus ancien d'abord — parmi ceux dont la dernière relecture dépasse
cet âge. Zéro force, dans la limite d'un plancher d'une minute : sans lui, un
dépôt qu'on vient de relire serait aussitôt à relire, et l'appelant boucherait.

Retourne `termine`, `reste`, `actualise` (le dépôt relu) et `depots` — l'état de
chacun : titre, source, nombre de paquets, date et **âge en secondes**. C'est
l'âge qui fait foi, les deux sites pouvant avoir des horloges ou des fuseaux
différents.

Un dépôt sans catalogue déclaré est ignoré : il n'y a rien à relire. L'inventaire
(`infos`) porte le même état sous la clé `depots`.

### `plugin_svp_preflight`

Argument : `prefixe`. Fait calculer à SVP, sur le site géré, ce qu'une mise à
jour impliquerait — **sans rien engager**. Retourne la version installée, la
version cible, la liste des actions prévues (dépendances comprises), et l'état
du verrou de SVP.

En cas de refus, `raison` dit lequel : `svp_absent`, `paquet_inconnu`,
`maj_inconnue`, `dependances`, `verrou`, `dir_auto`. Le tableau de bord s'en
sert pour décider s'il peut se rabattre sur le déploiement d'archive — il ne le
fait que pour `svp_absent` et `paquet_inconnu`.

### `plugin_svp_preparer`

Arguments : `prefixe`, `forcer` (booléen, pour passer outre un verrou). Fige la
file d'actions que SVP jouera. Repart d'une file propre : un reliquat d'une
tentative abandonnée serait joué sans que personne l'ait demandé.

### `plugin_svp_avancer`

Argument : `prefixe`. Joue **une** action de la file et rend la main.
`termine` dit s'il en reste. À la dernière, l'agent actualise l'inventaire de
SVP, purge les caches, recalcule la liste des plugins et retourne
`version_apres` et `dossier`.

### `plugin_svp_liberer`

Argument : `prefixe` (facultatif). Efface la file d'actions de SVP et son
verrou. Sert à débloquer un site où une série a été interrompue.

### `core_maj_preflight`

Sans argument. Retourne le détail des contrôles : extension zip, inscriptibilité
de la racine et de chaque répertoire du core, espace disque, version actuelle.

### `core_maj`

Arguments : `url_archive` (obligatoire, https), `sha256`, `version_attendue`.

L'agent télécharge, vérifie l'empreinte si elle est fournie, vérifie que
l'archive est bien une distribution SPIP et de la version annoncée, puis
remplace `ecrire/`, `prive/`, `squelettes-dist/`, `plugins-dist/` et les
fichiers racine. `config/`, `IMG/`, `local/`, `tmp/`, `plugins/`, `squelettes/`,
`lib/`, `extensions/` et `sites/` ne sont **jamais** touchés.

Chaque entrée est mise de côté par `rename()` avant d'être remplacée ; si une
étape échoue, tout ce qui a déjà été déplacé est restauré. Les copies de
sécurité, suffixées `.dashagent-AAAAMMJJHHMMSS`, sont conservées sept jours puis
supprimées par la tâche d'entretien.

`sha256` est **facultatif pour l'agent, et fourni depuis la tour 1.0.40** : elle
le prend dans l'annuaire officiel des versions, qui le publie à côté de l'adresse
de l'archive. Avant cela, le paramètre existait, l'agent le vérifiait, et rien ne
le renseignait jamais — un contrôle écrit dès le premier jour et qui n'avait
jamais servi une fois. Il reste facultatif pour qu'un miroir privé sans annuaire
demeure utilisable ; le journal du parc dit laquelle des deux situations s'est
produite.

### `base_maj_preflight`

Sans argument. Retourne l'état du schéma : `version` (la branche de SPIP que le
site exécute *en ce moment*), `version_base` (ce que contient la base, meta
`version_installee`), `version_base_attendue` (ce que réclament les fichiers,
`spip_version_base`), `maj_requise` et `base_plus_recente`.

`version` mérite une explication : après un remplacement de fichiers, PHP peut
encore exécuter le code d'avant. Comparer cette valeur à celle qu'on vient de
déployer est le seul moyen fiable de savoir si le nouveau noyau est réellement
en service — migrer le schéma avant cela ne ferait rien, en silence.

### `base_maj`

Argument facultatif : `budget`, en secondes (5 à 120, 25 par défaut).

Joue une **tranche** de la migration du schéma. SPIP mène ses migrations par
paliers et écrit la meta `version_installee` après chacun ; l'agent lui impose
le budget reçu et capture ce qu'elle écrit. La réponse porte :

| Champ | Sens |
|---|---|
| `termine` | la migration est achevée ; sinon, rappeler `base_maj` |
| `interrompu` | la tranche a rendu la main avant la fin de son travail |
| `progresse` | au moins un palier a été franchi pendant cette tranche |
| `journal` | les paliers joués, en clair |

Rappeler tant que `termine` est faux : chaque appel reprend là où le précédent
s'est arrêté. Une tranche qui n'avance pas et qui n'est pas `termine` signale un
blocage, pas une lenteur.

Cette opération relève de la même autorisation que `core_maj` (`op_core_maj`) :
remplacer les fichiers sans migrer le schéma laisse le site à moitié à jour.

### `serveur_resume`, `serveur_phpinfo`, `serveur_tables`

Sans argument. Respectivement : l'état de PHP et de la base ; le `phpinfo()` du
site en HTML, réduit au corps et expurgé ; l'inventaire des tables avec leur
cardinalité et, quand le moteur le dit, leur poids.

### `serveur_table`

Arguments : `table` (obligatoire), `debut`, `lot` (200 au plus), `tri`, `sens`
(`asc` ou `desc`), `filtre_colonne`, `filtre_valeur`.

Le nom de la table et celui des colonnes sont retrouvés dans le schéma que rend
le moteur ; ce qui ne s'y trouve pas est écarté sans erreur pour le tri, et
refusé pour le filtre. La valeur du filtre passe par `sql_quote()`. Aucune
portion de requête ne provient donc telle quelle de l'appelant.

La réponse porte `colonnes` — chacune avec un drapeau `masquee` — et `lignes`,
dont les colonnes sensibles sont déjà remplacées. Filtrer sur une colonne
masquée est refusé.

### `serveur_fichier`

Argument : `fichier`, parmi `htaccess`, `mes_options`, `mes_fonctions`. Une
liste fermée, pas un chemin : autrement l'opération deviendrait une lecture
arbitraire du disque, `config/connect.php` compris.

Le contenu est rendu expurgé : la valeur de toute affectation dont le nom
désigne un identifiant est remplacée, le nom restant lisible.

Ces cinq opérations relèvent de l'autorisation `op_serveur`, refusée par défaut.
Voir `docs/securite.md` pour ce qu'elles exposent et ce qui reste malgré tout
visible.

### `waf_resume`

Autorisation : `op_waf`. Lit le tableau de bord du plugin SPIP WAF du site géré.

Arguments : `debut`, `lot` (page du journal, 100 au plus), `type`, `ip`
(filtres), `jours` (profondeur du résumé des motifs, 7 par défaut), `bans`
(nombre de bannissements rendus).

Retourne `present`, `version`, `vue_ensemble` (requêtes et adresses bloquées,
part des règles et des listes noires), `bans`, `attaques` (motifs de la période
avec requêtes et adresses distinctes), `journal` (`debut`, `lot`, `total`,
`evenements`) et `reglages`.

Aucune de ces valeurs n'est du HTML : les URI et les en-têtes d'agent enregistrés
par le pare-feu contiennent volontiers du balisage, ils sont abrégés et rendus
tels quels, à charge pour le client de les afficher comme du texte. Un site sans
le plugin répond `ok: false` avec `raison: waf_absent`.

L'opération commence par vider la file d'événements que le plugin tient en
fichiers — sa propre page le fait aussi —, dans la limite de cinq secondes.

### `loader_etat`

Autorisation : `op_loader`. Décrit le `spip_loader.php` présent à la racine du
site : `present`, `octets`, `modifie` (la date du fichier), `version` et
`date_version` (celles que le script annonce), `racine_ecrit`, `fichier_ecrit`.

Le script distribué est un **stub de PHAR** : un en-tête PHP lisible, suivi de
l'archive en binaire. Ce sont ses constantes de classe qui le datent —
`const VERSION`, `const FULL_VERSION`, `const DATE` — et seul cet en-tête est
lu. Les formes antérieures, variable ou constante globale, restent reconnues.

`date_version` vaut mieux que `modifie` pour juger de l'âge : la seconde ne dit
que le jour du dépôt. Un script publié il y a deux ans, mis en place hier,
paraîtrait neuf.

### `loader_maj`

Autorisation : `op_loader`. Télécharge un `spip_loader.php` et le dépose à la
racine.

Argument : `url` — https obligatoire, `https://get.spip.net/spip_loader.php` à
défaut. Le contenu est contrôlé avant écriture : du PHP, moins d'un mégaoctet,
portant les marques d'un spip_loader ; sinon l'opération échoue sans rien
toucher. Le fichier en place est renommé `.spip_loader.php.dashagent-<date>`
avant d'être remplacé, et remis si l'écriture échoue.

Retourne `octets`, `sha256`, `version_avant`, `version_apres`, `sauvegarde` et
l'`etat` d'après.

### `loader_retirer`

Autorisation : `op_loader` — le même droit que le dépôt : c'est le même fichier,
et ne plus l'avoir à la racine est plus sûr que l'y avoir. **Supprime** le
`spip_loader.php`, qui se redépose d'un clic. Un site qui n'en a pas répond `ok`
sans rien faire.

Le `spip_loader_config.php` éventuel n'est pas touché : il ne contient que des
identifiants d'auteurs, il ne s'exécute pas tout seul, et il sert aussi à SPIP
Check.

### `check_etat`

Autorisation : `op_check`. Décrit le `spip_check.php` présent à la racine du
site : `present`, `octets`, `modifie`, `version`, `edition` (`complete` ou
`lite`), `racine_ecrit`, `fichier_ecrit`, et `config` — le nom du fichier
d'autorisation trouvé, s'il y en a un.

La version se lit **dans la queue du fichier**, à l'inverse du spip_loader : les
bibliothèques embarquées occupent tout le début du livrable, et le code de
l'outil vient après. En 2.4.0, `spipCheckToolVersion()` est au 854 687ᵉ octet
sur 953 235. Seuls les 256 derniers kilo-octets sont relus.

`config` compte à l'usage : sans fichier d'autorisation, SPIP Check n'ouvre son
interface qu'à l'auteur nº 1 **du site géré**. Le savoir avant de déposer évite
un clic pour rien.

### `check_maj`

Autorisation : `op_check`. Télécharge un `spip_check.php` et le dépose à la
racine.

Argument : `url` — https obligatoire, pris dans la configuration du tableau de
bord ; l'agent n'a pas de valeur de repli et refuse si rien n'est transmis. Le
défaut côté parc désigne le livrable publié sur la forge communautaire, sur une
**branche** : chaque dépôt sert donc ce qui y a été poussé. Pointer une release
figerait la version déposée.

Argument facultatif `sha256` : l'empreinte à laquelle le fichier téléchargé doit
répondre. Vide, rien n'est vérifié — c'est le réglage qui convient à une adresse
de branche, dont le contenu change à chaque poussée amont. Renseignée, l'agent
refuse tout fichier qui n'y répond pas **et rend l'empreinte qu'il a reçue**
(`sha256_obtenu`), sans quoi mettre l'épingle à jour demanderait d'aller
télécharger le livrable à la main pour le hacher. Le https atteste du transport,
jamais du contenu : l'épingle est le seul moyen de déployer sur tout un parc un
mégaoctet de code qu'on a relu une fois.

Le contenu est contrôlé avant écriture : du PHP, moins de huit méga-octets, et
portant la fonction que son gabarit engendre — un spip_loader n'y passe pas,
bien qu'il soit du PHP et qu'il parle de SPIP. Le fichier en place est renommé
`.spip_check.php.dashagent-<date>` avant d'être remplacé, et remis si l'écriture
échoue.

Retourne `octets`, `sha256`, `version_avant`, `version_apres`, `edition`,
`sauvegarde` et l'`etat` d'après.

### `check_retirer`

Autorisation : `op_check`. **Supprime** le `spip_check.php` de la racine — la
seule opération de l'agent qui efface un fichier plutôt que de l'écarter sous un
nom caché. Il n'a jamais eu vocation à rester, il se retélécharge d'un clic, et
un nom commençant par un point est ce qu'un contrôle d'intégrité signale comme
dissimulation. Un site qui n'en a pas répond `ok` sans rien faire.

Le dépôt d'une nouvelle version ne laisse rien non plus : l'ancienne est écartée
le temps d'écrire, puis effacée.

Le fichier de configuration éventuel n'est pas touché : il ne contient que des
identifiants d'auteurs, il ne s'exécute pas tout seul, et il sert aussi au
spip_loader.

Ces trois opérations relèvent de `op_check`, refusée par défaut, et `op_loader`
ne les ouvre pas. L'agent ne **lance** pas le contrôle et n'en lit pas les
résultats : SPIP Check s'autorise sur une session d'administrateur du site géré,
là où l'agent s'authentifie par signature. Contourner cette autorisation pour le
piloter à distance reviendrait à poser une porte dérobée sur tout le parc.

### Authentification HTTP du serveur

Indépendante du protocole, et antérieure à lui. Quand le site géré est gardé par
un `htpasswd`, le serveur répond **401 avant que PHP ne s'exécute** : la
signature n'est jamais examinée. Le client joint alors un en-tête
`Authorization: Basic` aux requêtes qu'il adresse à `url_agent`.

Ces identifiants ouvrent la porte ; ils n'authentifient rien. Un appelant qui
les connaîtrait sans le secret partagé franchirait le 401 pour se faire refuser
par `dashagent_verifier_signature()`.

Côté tour de contrôle, ils se règlent sur la fiche du site
(`auth_user`, `auth_pass`) ; le mot de passe est chiffré comme le secret
partagé. L'agent, lui, n'en sait rien et n'a rien à en savoir.

## Écrire un autre client

Rien n'oblige à passer par le plugin dashboard. Un script suffit :

```php
$secret = '…';
$args   = json_encode(['cibles' => ['tout']], JSON_UNESCAPED_SLASHES);
$ts     = time();
$nonce  = bin2hex(random_bytes(16));

$champs = [
    'op'    => 'purger',
    'args'  => $args,
    'ts'    => $ts,
    'nonce' => $nonce,
    'sig'   => hash_hmac('sha256', "purger\n$args\n$ts\n$nonce", $secret),
];

$ch = curl_init('https://exemple.org/spip.php?action=dashagent');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($champs),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
]);
echo curl_exec($ch);
```
