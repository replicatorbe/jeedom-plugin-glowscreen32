# GlowScreen32

Pilote des écrans tactiles ESP32 depuis Jeedom.

Un équipement du plugin = **un** écran physique, reconnu à l'adresse MAC de sa
carte. L'écran affiche jusqu'à six boutons ; l'utilisateur décide, dans Jeedom,
de ce que chacun déclenche. La carte récupère sa mise en page au démarrage, puis
signale les appuis.

Le parc est multi-écrans par construction : on crée autant d'équipements que
d'écrans, chacun avec sa MAC et ses boutons. **Le firmware est identique sur
toutes les cartes** — aucune configuration n'y est compilée, une carte découvre
son identité en lisant sa propre adresse MAC au démarrage.

## Configurer un écran

1. **Plugins → Organisation → GlowScreen32 → Ajouter un écran.** Donnez-lui le
   nom de la pièce : il s'affiche dans le bandeau de l'écran.
2. **Adresse MAC.** Avec ou sans séparateurs, en majuscules ou en minuscules :
   `24:6F:28:12:34:56`, `24-6f-28-12-34-56` et `246f28123456` désignent la même
   carte. Le plugin normalise et refuse deux écrans portant la même adresse.
3. **Onglet Boutons.** Six emplacements, dans l'ordre de la grille 3×2 : les
   trois premiers en haut, les trois suivants dessous. Pour chacun, un libellé,
   une couleur, une icône, et ce que l'appui déclenche — une commande d'action
   de votre Jeedom, ou un scénario. L'aperçu à droite montre l'écran tel que la
   carte le dessinera.
4. **Sauvegardez.** Le compteur de version augmente, et la carte redessine à sa
   prochaine vérification.

Un emplacement laissé vide n'est pas envoyé à la carte : il ne laisse pas de
case morte sur l'écran, les boutons suivants remontent.

### L'icône

Texte libre en v1 : le plugin transmet ce que vous écrivez, le firmware dessine
ce qu'il sait dessiner. `bulb`, `movie`, `fan`… La liste appartient au firmware,
et le plugin n'a pas à la tenir à jour à sa place.

### La pastille d'un bouton

Le champ **Pastille allumée si** désigne la commande d'information qui dit si le
bouton doit apparaître allumé. Il est facultatif, et c'est lui qui fait foi.

Laissé vide, le plugin reprend le lien que Jeedom pose lui-même entre une
commande d'action et son état (le champ « valeur » de la commande d'action),
celui dont le cœur se sert déjà pour allumer une tuile de dashboard. Ce n'est
pas une devinette : c'est une déclaration faite ailleurs dans Jeedom, par le
plugin qui a créé la lampe. Une lampe ou une prise ordinaire n'a donc rien à
régler ici.

En revanche, une commande désignée **explicitement** mais devenue introuvable ne
retombe pas sur cette déduction : vous avez dit ce que vous vouliez, afficher
autre chose à la place serait pire que de n'afficher rien.

Seul un sous-type **binaire** donne une pastille. Une consigne de température ou
un volet à 40 % n'a pas d'état binaire : le bouton reste neutre, ce qui vaut
mieux que de l'afficher à l'envers une fois sur deux. Le contrat prévoit `null`
pour exactement ce cas.

## Enrôler une carte neuve

Une carte flashée mais pas encore déclarée reçoit `unknown_device` et **affiche
sa propre adresse MAC en grand**. Il suffit de la recopier dans un nouvel
équipement du plugin : la carte bascule d'elle-même en mode normal dès qu'elle
est reconnue, sans câble série ni redémarrage.

C'est un état normal, et non un incident : le plugin le journalise en `debug`
pour ne pas noyer le journal pendant l'enrôlement.

## Brancher le firmware

Le cadre **Ce qu'il faut donner à la carte**, dans l'onglet Écran, donne les
trois éléments :

| | |
|---|---|
| URL | `http://<votre box>/plugins/glowscreen32/core/php/api.php` |
| En-tête | `X-GLOWSCREEN32-APIKEY: <clé>` |
| Clé | la clé API du plugin, **la même pour tout le parc** |

La clé se retrouve aussi dans **Réglages → Système → Configuration → onglet
API**, ligne *GlowScreen32*. La changer là impose de la reporter dans le
firmware de chaque carte : une carte n'a aucun moyen de la redemander.

Le bouton **Voir ce que la carte reçoit** affiche la réponse `layout` exacte,
telle qu'elle est servie à l'instant. C'est le moyen de vérifier une
configuration sans avoir l'écran sous la main — un bouton dont la commande a été
supprimée disparaît de cette réponse alors que le formulaire, lui, n'a pas
changé d'apparence.

## Le contrat d'API

Le plugin implémente le contrat v1.2 partagé avec le firmware. Trois actions,
toutes en GET, toutes authentifiées.

### `action=layout` — récupérer les boutons

```bash
curl -s -H "X-GLOWSCREEN32-APIKEY: <clé>" \
  "http://<box>/plugins/glowscreen32/core/php/api.php?action=layout&device=246f28123456"
```

```json
{
  "ok": true,
  "device": "246f28123456",
  "name": "Salon",
  "version": 3,
  "poll": 30,
  "buttons": [
    { "id": 12, "label": "Salon", "color": "#2d7ff9", "icon": "bulb", "state": 1 }
  ]
}
```

`version` est **propre à chaque écran** : modifier la configuration du salon ne
force pas la cuisine à recharger. `poll` est l'intervalle conseillé, en
secondes. `state` vaut `0`, `1`, ou `null` quand la notion n'a pas de sens.

### `action=press` — déclencher un bouton

```bash
curl -s -H "X-GLOWSCREEN32-APIKEY: <clé>" \
  "http://<box>/plugins/glowscreen32/core/php/api.php?action=press&device=246f28123456&id=12"
```

```json
{ "ok": true, "id": 12, "state": 0 }
```

`state` est l'état **après** exécution, pour que la carte mette le bouton à jour
sans recharger toute la mise en page.

L'identifiant est cherché parmi les boutons de **cet écran-là**, jamais dans
toute l'installation : une carte ne peut déclencher que ce qu'on lui a confié.

### `action=ping` — vérifier la liaison

```bash
curl -s -H "X-GLOWSCREEN32-APIKEY: <clé>" \
  "http://<box>/plugins/glowscreen32/core/php/api.php?action=ping&device=246f28123456"
```

```json
{ "ok": true, "version": 3, "time": 1758537600, "states": [1, 0, null] }
```

C'est l'appel périodique : il détecte un changement de `version` sans transférer
la mise en page.

`states` est dans le **même ordre que `buttons`** du `layout`, et donne l'état
courant de chacun. C'est ce tableau qui garde les pastilles fraîches : `version`
ne bouge qu'aux changements de *configuration*, si bien qu'une lampe allumée
depuis l'application Jeedom, un interrupteur mural ou un scénario laisserait
sinon la pastille périmée jusqu'au prochain rechargement complet.

Les deux tableaux sont construits à partir du même parcours filtré côté plugin :
ils ne peuvent pas se décaler.

### Erreurs

| `error` | HTTP | Cause |
|---|---|---|
| `bad_apikey` | 401 | clé absente ou invalide |
| `unknown_device` | 404 | aucun écran pour cette MAC, ou écran désactivé |
| `unknown_button` | 404 | identifiant de bouton inconnu pour cet écran |
| `bad_request` | 400 | paramètre manquant, MAC malformée, ou action inconnue |

Un écran **désactivé** répond `unknown_device` : désactiver un équipement dans
Jeedom doit couper ce qu'il commande, pas le laisser déclencher des actions
depuis un mur.

### Les scénarios et le champ `id`

Le contrat définit `id` comme l'identifiant de la commande Jeedom à déclencher,
et ne prévoit rien pour un scénario. Un bouton qui lance un scénario porte donc
l'**opposé** de l'identifiant du scénario : `-7` pour le scénario 7. Un entier
négatif n'entre en collision avec aucun identifiant de commande, et `press` sait
le relire. Le firmware n'a rien de particulier à faire : il renvoie l'entier
qu'on lui a donné.

## Les commandes de l'équipement

Trois informations, écrites au fil des échanges avec la carte. Aucune n'est
nécessaire au dialogue : elles existent pour que l'écran soit un équipement
ordinaire sur le dashboard, et qu'un scénario puisse réagir à un appui.

| Commande | Sens |
|---|---|
| Version de la mise en page | le compteur que la carte surveille |
| Dernier contact | horodaté à chaque appel reçu, `layout` comme `ping` |
| Dernier bouton | le libellé du dernier bouton appuyé |

**Dernier contact** est la façon de savoir si un écran est en ligne : un écran
qui n'apparaît plus depuis plus longtemps que son intervalle de rafraîchissement
ne répond plus. La page de configuration du plugin en fait un tableau, avec la
MAC et la version de chaque écran.

## Journal

`log::add('glowscreen32', …)`, visible dans **Analyse → Journaux →
glowscreen32**. Les appuis y sont en `info`, les clés refusées en `warning`, les
cartes non déclarées en `debug`.

En cas d'enregistrement qui « ne fait rien », c'est `log/http.error` qu'il faut
regarder en premier : une erreur fatale de PHP n'atteint jamais le journal du
plugin.
