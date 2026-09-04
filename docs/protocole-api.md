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

### `purger`

Argument : `cibles`, tableau parmi `pages`, `squelettes`, `images`, `css_js`,
`sessions`, ou `tout`.

`tout` couvre pages, squelettes, images et CSS/JS — **pas** les sessions, dont
la purge déconnecterait tous les visiteurs connectés.

Retour : `purge` avec le nombre de fichiers supprimés par cible.

### `sauvegarde_creer`

Arguments : `sans_statistiques` (booléen), `exclure` (tableau de tables).

Produit un export SQL gzip dans `tmp/dashagent/sauvegardes/`. Retour :
`sauvegarde` avec `identifiant`, `fichier`, `octets`, `sha256`, `date`,
`tables`, `duree_ms`.

### `sauvegarde_lister`, `sauvegarde_supprimer`

Listage et suppression, par `identifiant`.

### `sauvegarde_telecharger`

Argument : `identifiant`. **Ne renvoie pas de JSON** mais le fichier lui-même,
en `application/gzip`, avec l'empreinte dans l'en-tête `X-Dashagent-Sha256`.

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
