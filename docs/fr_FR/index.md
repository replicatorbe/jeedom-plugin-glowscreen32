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
   une couleur, une icône, et un **mode** — voir ci-dessous. L'aperçu à droite
   montre l'écran tel que la carte le dessinera, avec le rang de chaque bouton.
4. **Sauvegardez.** Le compteur de version augmente, et la carte redessine à sa
   prochaine vérification.

Un emplacement laissé vide n'est pas envoyé à la carte : il ne laisse pas de
case morte sur l'écran, les boutons suivants remontent.

### Les deux modes de bouton

C'est le choix qui décide de ce que fait l'appui.

| Mode | Ce qu'il fait | Ce qu'il faut remplir |
|---|---|---|
| **Action simple** | joue toujours la même chose | une commande d'action, ou un scénario |
| **Interrupteur** | lit l'état, puis joue la commande **inverse** | « Allumer », « Éteindre », et une commande d'état |

Un équipement comme un Shelly expose des commandes *distinctes* : `Allumer`,
`Éteindre`, `Basculer`. Lier un bouton à une seule d'entre elles donne un bouton
qui n'allume **que** — c'était le défaut de la version 1.0, constaté sur le mur :
le premier appui allumait, le second ne faisait rien de visible.

En mode **Interrupteur**, le plugin lit l'état avant d'agir et choisit
lui-même : allumé → « Éteindre », éteint → « Allumer ». Un appui allume, le
suivant éteint.

Un bouton interrupteur **sans commande d'état est refusé à la sauvegarde** :
sans état, rien ne permet de décider du sens, et le bouton ferait exactement ce
que faisait la version précédente.

Le champ **Basculer** est facultatif, et c'est un secours et non le chemin
normal. Le choix explicite d'après l'état reste préférable : une commande
« basculer » désynchronisée — un relais actionné à la main pendant que Jeedom ne
regardait pas — inverse l'état que vous voyez sur l'écran, et l'écart ne se
rattrape jamais. « Allumer quand c'est éteint » converge, lui, quoi qu'il se
soit passé entre-temps. La commande « Basculer » n'est jouée que si l'état
devient illisible.

Le mode **Action simple** reste le bon choix pour tout ce qui n'a pas d'état :
un relais impulsionnel, un portail, un scénario.

### L'icône

Texte libre en v1 : le plugin transmet ce que vous écrivez, le firmware dessine
ce qu'il sait dessiner. `bulb`, `movie`, `fan`… La liste appartient au firmware,
et le plugin n'a pas à la tenir à jour à sa place.

### La pastille d'un bouton

Le champ d'**état** désigne la commande d'information qui dit si le bouton doit
apparaître allumé. Il est **facultatif en mode Action simple** — il s'appelle
alors « Pastille allumée si » — et **obligatoire en mode Interrupteur**, où il
sert en plus à décider du sens de l'appui. C'est lui qui fait foi.

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

Le plugin implémente le contrat v1.3 partagé avec le firmware. Trois actions,
toutes en GET, toutes authentifiées.

> **Changement de v1.3 :** `id` n'est plus l'identifiant d'une commande Jeedom,
> c'est le **rang du bouton** dans la mise en page (0 à 5). La carte le traite
> comme une valeur opaque et le renvoie tel quel. C'est le plugin qui décide
> quelle commande exécuter. Bénéfice de sécurité : une carte ne peut plus
> désigner une commande arbitraire de l'installation, seulement l'un de ses
> propres boutons.

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
    { "id": 0, "label": "Facade",  "color": "#9b59b6", "icon": "bulb", "mode": "toggle", "state": 1 },
    { "id": 1, "label": "Portail", "color": "#2d7ff9", "icon": "gate", "mode": "action", "state": null }
  ]
}
```

`version` est **propre à chaque écran** : modifier la configuration du salon ne
force pas la cuisine à recharger. `poll` est l'intervalle conseillé, en
secondes. `state` vaut `0`, `1`, ou `null` quand la notion n'a pas de sens.
`mode` vaut `"toggle"` ou `"action"`, et `id` est le rang du bouton.

### `action=press` — déclencher un bouton

```bash
curl -s -H "X-GLOWSCREEN32-APIKEY: <clé>" \
  "http://<box>/plugins/glowscreen32/core/php/api.php?action=press&device=246f28123456&id=0"
```

```json
{ "ok": true, "id": 0, "state": 1, "pending": true }
```

`id` est le **rang** reçu dans `layout`, pas un identifiant de commande.

`state` est l'état **attendu** après exécution — en mode interrupteur, l'inverse
de l'état lu juste avant. `pending` dit qu'il n'est pas encore confirmé : sur du
matériel réel, la valeur remonte après un aller-retour avec l'équipement, et au
moment où `press` répond le relais vient de basculer mais Jeedom n'a pas encore
reçu la nouvelle valeur. Le firmware s'en sert pour un retour visuel optimiste
immédiat ; la **source de vérité reste le `states` du `ping` suivant**.

Le rang est cherché dans la mise en page de **cet écran-là**, jamais dans toute
l'installation : une carte ne peut déclencher que ce qu'on lui a confié. Un rang
hors de la mise en page vaut `unknown_button`.

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

### Les scénarios

Un bouton peut lancer un scénario au lieu d'une commande : c'est un bouton en
mode **Action simple** dont la cible est un scénario. Depuis la v1.3 du contrat
cela ne demande plus aucune convention particulière — `id` étant un rang, un
bouton-scénario porte un rang comme les autres, et le firmware n'a rien à savoir
de ce qu'il déclenche. La convention de l'identifiant négatif de la v1.0 n'a
plus de raison d'être et a disparu.

## Les commandes de l'équipement

Trois informations, écrites au fil des échanges avec la carte. Aucune n'est
nécessaire au dialogue : elles existent pour que l'écran soit un équipement
ordinaire sur le dashboard, et qu'un scénario puisse réagir à un appui.

| Commande | Sens |
|---|---|
| Version de la mise en page | le compteur que la carte surveille |
| Dernier contact | horodaté à chaque appel reçu, `layout` comme `ping` |
| Dernier bouton | le libellé du dernier bouton appuyé |

**Dernier contact** est la façon de savoir si un écran est en ligne. Il est
écrit à deux endroits : la commande d'information ci-dessus, et la
**configuration de l'équipement** (`getConfiguration('lastcontact')`), qui est
la source que le plugin consulte lui-même. La version 1.0 ne l'écrivait que dans
la commande, si bien que la configuration restait vide sur un écran qui
dialoguait pourtant parfaitement.

Il est arrondi **à la minute**, et c'est délibéré : une carte interroge toutes
les trente secondes, et horodater chaque appel ferait une écriture en base par
écran et par demi-minute pour une information dont personne ne lit la seconde.
Un appui, lui, est rare et intéressant : il est toujours écrit.

La page du plugin l'affiche en clair — « 22/09/2026 11:20:07 (il y a 2 min) » —
dans l'onglet Écran et dans le tableau du parc, avec une étiquette **hors
ligne** au-delà de trois intervalles de rafraîchissement sans nouvelle.

## Journal

`log::add('glowscreen32', …)`, visible dans **Analyse → Journaux →
glowscreen32**. Les appuis y sont en `info`, les clés refusées en `warning`, les
cartes non déclarées en `debug`.

En cas d'enregistrement qui « ne fait rien », c'est `log/http.error` qu'il faut
regarder en premier : une erreur fatale de PHP n'atteint jamais le journal du
plugin.
