# `autoriser_exception()` est sans effet sur les types préfixés par `_`

**Composant** : `ecrire/inc/autoriser.php`
**Version** : constaté sur SPIP 4.4.23 (PHP 8.3)
**Gravité** : fonctionnelle — une exception d'autorisation posée par du code est
silencieusement ignorée.

## Résumé

`autoriser_exception('ajouter', '_plugins', '*')` ne lève rien :
`autoriser('ajouter', '_plugins')` continue de retourner `false`.

Le type est normalisé **deux fois** : une fois à l'écriture de l'exception, une
seconde fois à sa relecture. La clé consultée n'est donc jamais celle qui a été
écrite.

Le fond du problème tient à ce que `autoriser_type()` **détruit d'abord
l'information qui empêcherait la seconde passe**. Le `_` initial est le seul
marqueur qui distingue un nom de page d'un type d'objet — le docblock de la
fonction le dit : *« Si `_` en premier caractère, c'est un nom de page. Sinon,
c'est un type d'objet éditorial. »* Or elle commence par le supprimer :

```
autoriser_type('_plugins') === 'plugins'   // le marqueur a disparu
autoriser_type('plugins')  === 'plugin'    // relu comme un objet, dépluralisé
```

Le défaut est systématique pour tout nom de page se terminant par `s` : **14 des
20 noms de page employés par SPIP et ses plugins-dist** sont dans ce cas —
`_articles`, `_auteurs`, `_avancees`, `_depots`, `_documents`, `_interactions`,
`_mots`, `_plugins`, `_preferences`, `_revisions`, `_rubriques`, `_sites`,
`_statistiques`, `_urls`. Restent indemnes ceux déjà au singulier : `_contenu`,
`_langage`, `_langue`, `_multilinguisme`, `_controlersyndication`, `_bigup`.

**Il n'est toutefois observable que là où du code pose une exception** sur un tel
nom de page. Le cœur de SPIP n'en pose que sur des types d'objet au singulier
(`auteur`, `document`), pour lesquels `autoriser_type()` est idempotente : c'est
ce qui explique que le défaut ait survécu. Le cas démontré ci-dessous est
`_plugins`, via SVP.

## Reproduction

Dans n'importe quel contexte SPIP chargé :

```php
include_spip('inc/autoriser');

foreach (['_plugins', '_documents', '_rubriques', '_contenu'] as $type) {
    autoriser_exception('ajouter', $type, '*');
    printf("%-14s -> %s\n", $type, var_export(autoriser('ajouter', $type), true));
}
```

Sur SPIP 4.4.23 :

```
_plugins       -> false     ← attendu : true
_documents     -> false     ← attendu : true
_rubriques     -> false     ← attendu : true
_contenu       -> true
```

## Analyse

`autoriser_type()` (`ecrire/inc/autoriser.php`) n'est pas idempotente : elle
laisse intact un type commençant par `_` puis lui retire ses `_`, mais applique
`objet_type()` à tout autre type. Après le premier passage, le nom de page a
perdu son `_` et sera donc traité au second comme un type d'objet.

```php
function autoriser_type(?string $type = ''): string {
    if ($type && $type[0] !== '_') {
        $type = objet_type($type, false);
    }
    return str_replace('_', '', (string) $type);
}
```

Donc :

```
autoriser_type('_plugins') === 'plugins'
autoriser_type('plugins')  === 'plugin'     // objet_type() singularise
```

Noter que `objet_type($type, false)` dépluralise **sans vérifier qu'un tel objet
existe** : avec `$serveur === false`, elle retourne directement le résultat de
`preg_replace(',^spip_|^id_|s$,', '', $table_objet)`. C'est ainsi que
`_statistiques` devient `statistique`, alors qu'aucun objet éditorial de ce nom
n'est déclaré.

À l'écriture, `autoriser_exception()` range l'exception sous `plugins` :

```php
$type = autoriser_type($type);                       // '_plugins' -> 'plugins'
$GLOBALS['autoriser_exception'][$faire][$type][$id]
    = $autorisation[$faire][$type][$id] = true;
```

À la relecture, `autoriser_dist()` normalise d'abord son propre `$type`, puis
repasse ce type **déjà normalisé** à `autoriser_exception()`, qui le normalise
une seconde fois :

```php
$type = autoriser_type($type);                       // '_plugins' -> 'plugins'

if (
    (isset($GLOBALS['autoriser_exception'][$faire][$type][$id])
        and autoriser_exception($faire, $type, $id, 'verifier'))   // 'plugins' -> 'plugin'
    or (isset($GLOBALS['autoriser_exception'][$faire][$type]['*'])
        and autoriser_exception($faire, $type, '*', 'verifier'))
) {
```

Le `isset()` sur la globale réussit — elle est bien indexée sous `plugins` — mais
la vérification sur la statique échoue, puisqu'elle cherche `plugin`. Le `and`
est donc faux, et l'exception n'est jamais appliquée.

La statique n'est pas redondante : elle garantit que la globale a bien été
positionnée par du code et non par une URL. Le correctif doit la conserver.

## Conséquences observées

Le cas qui nous a amenés ici : **SVP ne peut plus télécharger de paquet hors de
l'espace privé**. `action/teleporter.php` s'ouvre sur

```php
if (!autoriser('ajouter', '_plugins')) {
    return _T('svp:erreur_teleporter_chargement_source_impossible', ['source' => $source]);
}
```

et tout appelant qui lève l'exception comme le veut l'usage — `spip-cli` le fait
dans `src/Command/PluginsSvpTelecharger.php`, SVP lui-même ailleurs — se voit
opposer un laconique « Chargement impossible de la source », sans trace dans
`tmp/log/teleport.log` puisque cette branche ne journalise pas.

Le défaut passe inaperçu dans le cœur de SPIP parce que tous ses appels à
`autoriser_exception()` portent sur des types au singulier (`auteur`,
`document`), pour lesquels `autoriser_type()` est idempotente.

## Correctif proposé

Conserver dans `autoriser_dist()` le type tel que l'appelant l'a écrit, et le
transmettre à la vérification — de sorte que la clé relue soit normalisée
exactement comme elle a été écrite.

```diff
--- a/ecrire/inc/autoriser.php
+++ b/ecrire/inc/autoriser.php
@@
+	$type_appele = $type;
 	$type = autoriser_type($type);
 
 	// Si une exception a ete decretee plus haut dans le code, l'appliquer
 	if (
-		(isset($GLOBALS['autoriser_exception'][$faire][$type][$id]) and autoriser_exception($faire, $type, $id, 'verifier'))
-		or (isset($GLOBALS['autoriser_exception'][$faire][$type]['*']) and autoriser_exception($faire, $type, '*', 'verifier'))
+		(isset($GLOBALS['autoriser_exception'][$faire][$type][$id]) and autoriser_exception($faire, $type_appele, $id, 'verifier'))
+		or (isset($GLOBALS['autoriser_exception'][$faire][$type]['*']) and autoriser_exception($faire, $type_appele, '*', 'verifier'))
 	) {
```

Vérifié sur SPIP 4.4.23 : les quatre cas de la reproduction retournent `true`,
et le téléchargement d'un paquet par SVP aboutit.

### Variante

Ne normaliser dans `autoriser_exception()` qu'à l'écriture, le mode `verifier`
n'étant appelé que depuis `autoriser_dist()` avec un type déjà normalisé :

```php
if ($autoriser !== 'verifier') {
    $type = autoriser_type($type);
}
```

Plus court, mais cela fige un contrat implicite sur un paramètre public. Le
correctif ci-dessus nous paraît préférable.

## Contournement en attendant

Déclarer les deux orthographes :

```php
autoriser_exception('ajouter', '_plugins', '*');
autoriser_exception('ajouter', autoriser_type('plugins'), '*');
```

## Piste connexe

`action/teleporter.php` (SVP) rend le même message d'erreur pour un refus
d'autorisation et pour un téléchargement qui échoue, sans journaliser le premier
cas. Distinguer les deux ferait gagner du temps au diagnostic.
