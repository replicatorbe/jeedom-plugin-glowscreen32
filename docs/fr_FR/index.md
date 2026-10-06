# GlowScreen32

Pilote des écrans tactiles ESP32 depuis Jeedom.

Un équipement du plugin = **un** écran physique, reconnu à l'adresse MAC de sa
carte. L'écran affiche jusqu'à trente-deux boutons et tuiles, sur quatre pages ;
l'utilisateur décide, dans Jeedom, de ce que chacun déclenche ou affiche. La carte récupère sa mise en page au démarrage, puis
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

### Les modes de bouton

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

### La tuile « Valeur » — v3.0

Mode **Valeur** : la tuile affiche une commande d'information au lieu d'agir.
Choisissez la commande ; le panneau s'adapte à son type et se **préremplit**
d'après son type générique Jeedom (porte, serrure, alarme, chauffage,
température…).

| Type | Réglages |
|---|---|
| binaire | libellé et sens pour 0 et pour 1, case « Inverser » |
| numérique | unité, décimales, sens sous le premier seuil, trois seuils croissants « ≥ valeur → sens » |
| texte | jusqu'à six lignes « valeur reçue → libellé + sens » ; sinon la valeur brute, neutre |

Le **sens** (neutre, normal, attention, alerte) est une signification, pas une
couleur : vert, orange et rouge sont fixés dans le firmware, identiques sur tout
le parc ; neutre garde la couleur de la tuile.

**Vérifiez l'aperçu.** Il montre la valeur réelle, mise en forme par la même
fonction que l'API. Pour une porte ou une serrure, rien dans Jeedom ne fixe ce
que veut dire « 1 » : un avertissement le rappelle, et « Inverser » corrige.

**Péremption** : la tuile affiche « — » si l'équipement de la commande est
désactivé ou en alerte de communication Jeedom (délai maximal entre deux
communications, réglé sur l'équipement). Une valeur numérique périme aussi au-delà
de 60 min sans collecte ; binaire et texte n'ont pas de seuil par défaut. Le champ
« Périmée après » règle ce seuil par tuile (0 = jamais).

Seule une carte de schéma 3 (firmware 2.3.x et suivants) dessine ces tuiles ; une carte plus
ancienne ne les reçoit pas.

### Lecture seule — v3.0

Case de l'onglet Écran. L'écran affiche mais ne commande rien : la carte de
schéma 3 n'envoie plus d'appui, et **le plugin refuse tout appui venant de cet
écran**, quel que soit son firmware. La navigation reste possible.

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
configuration sans avoir l'écran sous la main. Elle donne aussi la
correspondance id → bouton de chaque schéma.

Un bouton dont la commande a été supprimée ou recréée **garde sa place**, inerte
(pastille vide, appui refusé par `unknown_button`), et le journal le signale une
fois. Le retirer décalerait tous les `id` suivants, et un appui jouerait le
bouton d'à côté (contrat v3.0). Un appui est aussi refusé si le bouton à ce rang
a changé depuis la dernière mise en page servie à la carte.

## Le contrat d'API

Le plugin implémente le contrat v3.0 partagé avec le firmware. Des actions en
GET, toutes authentifiées par la clé API, sauf le téléchargement `fwfile`
qu'autorise un jeton à durée de vie courte.

> **Ajouté en v1.4 :** `action=firmware`, la mise à jour par le réseau, et le
> double verrou qui décide quel écran la reçoit — voir « Mise à jour du
> firmware par le réseau », plus bas.

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

#### `rssi`, optionnel — contrat v2.1

La carte peut joindre à son `ping` le niveau Wi-Fi qu'elle mesure, en dBm :

```bash
curl -s -H "X-GLOWSCREEN32-APIKEY: <clé>" \
  "http://<box>/plugins/glowscreen32/core/php/api.php?action=ping&device=246f28123456&rssi=-64"
```

Il alimente la commande **Niveau Wi-Fi**. Une valeur absente, non numérique ou
hors de l'intervalle **−120 à 0** est ignorée **sans erreur** : un diagnostic mal
formé ne doit jamais coûter sa liaison à un écran — le plugin couperait
justement celui qu'il cherche à diagnostiquer.

Aucun champ de réponse ne change, et **le numéro de schéma reste 2** : un
paramètre de requête qu'un serveur ne connaît pas, il l'ignore. Une carte v2.1
qui l'envoie à un plugin v2.0 est servie exactement comme avant.

#### Cadence — précisée en v2.1

La carte suit `poll` écran allumé, et peut espacer jusqu'à **2 × `poll`** quand
son écran est atténué — personne ne le regarde. Elle émet en revanche un `ping`
**immédiat au réveil**, si bien que les pastilles vues par quelqu'un qui
s'approche sont toujours fraîches.

C'est pourquoi le seuil « hors ligne » se compte sur **3 × 2 × `poll`** : le
calculer sur `poll` seul ferait passer tout le parc hors ligne chaque nuit.

#### Attente longue — contrat v2.2, schéma 2

Les réponses `layout` et `ping` du schéma 2 portent, en fin de réponse :

```json
"features": { "wait": 25, "cmd": true }, "rev": "a41f09c2"
```

Une carte v2.2 relance son `ping` avec `&wait=<s>&rev=<dernière rev reçue>`. Si
`rev` est encore la valeur courante et qu'aucune commande n'attend, le plugin
**retient** la requête jusqu'à ce que quelque chose change — un état de bouton,
le bandeau (y compris sa péremption), `version`, la file de commandes — ou que
`wait` secondes (25 au plus) s'écoulent. La réponse est calculée au moment où
elle part. Sans `wait`, sans `rev`, ou avec une `rev` différente : réponse
immédiate, comme en v2.1. Le schéma 1 n'est jamais retenu.

Pendant la retenue, le plugin relit un petit fichier de réveil
(`/tmp/jeedom/glowscreen32/wake-<id>`) quatre fois par seconde, **sans requête
SQL**. Un *listener* Jeedom posé sur les commandes d'état des boutons et sur
celle du bandeau le fait bouger ; l'enregistrement de l'écran et la mise en file
d'une commande aussi. La `rev` est de toute façon recalculée toutes les cinq
secondes, ce qui attrape la péremption du bandeau. Une nouvelle requête du même
écran **libère** la précédente. Le contact et les diagnostics sont notés à
l'**arrivée** de la requête.

#### Commandes à distance — contrat v2.2

Une réponse `ping` du schéma 2 peut porter **une** commande :

```json
"cmd": { "seq": 17, "do": "message", "text": "On sonne au portail", "duration": 30 }
```

| `do` | Arguments, bornés par le plugin |
|---|---|
| `reboot` | — |
| `identify` | `duration` 1–120 s (10) |
| `message` | `text` ≤ 64 caractères, `duration` 1–600 s (30) |
| `page` | `page` 0–3 |
| `calibrate` | — |
| `ota` | — (toujours soumis aux deux verrous) |
| `wifi` | `ssid` ≤ 32, `pass` ≤ 64 — **page de l'équipement uniquement** |

File de **8** commandes par écran (la plus ancienne est abandonnée et
journalisée), durée de vie **10 minutes**, livraison **au plus une fois** : la
commande est retirée de la file au moment où elle part. `seq` croît par écran et
survit à un redémarrage de Jeedom (il est rangé dans la configuration du
plugin).

#### Diagnostics — contrat v2.2

`&up=86400&rst=poweron&heap=142336&blk=86004&ip=192.168.20.42&ssid=MonReseau` :
durée de fonctionnement, cause du dernier redémarrage (`poweron`, `sw`, `panic`,
`wdt`, `brownout`, `ext`, `other`), tas libre et plus gros bloc (0–400 000
octets), adresse IPv4, réseau (≤ 32 caractères). Même règle que `rssi` : absent,
mal formé ou hors bornes → **ignoré sans erreur**.

#### L'API n'enregistre jamais l'équipement — v2.2

Aucun appel de carte ne réenregistre l'eqLogic : contact, firmware, diagnostics
et péremption du bandeau vont dans des commandes d'information ou dans le cache.
Jusqu'en v2.1, une configuration enregistrée pendant qu'une carte appelait
pouvait être écrasée ; avec un `ping` retenu 25 s, ce serait devenu la règle.

#### Schéma 3 — tuiles « valeur » et lecture seule (v3.0)

| En-tête `X-GLOWSCREEN32-SCHEMA` | Réponse |
|---|---|
| absent, vide, `1` | schéma 1, octet pour octet |
| `2` | schéma 2 **sans aucune tuile « valeur »**, `id` recalculés |
| `3` ou plus | schéma 3 |

Une tuile `view` du `layout` :

```json
{ "id": 4, "slot": 4, "label": "Porte", "color": "#34495e", "icon": "door",
  "mode": "view", "state": null, "value": "Ouverte", "tone": "warn" }
```

Le `ping` du schéma 3 ajoute `values`, de même longueur que `states` : `null`
hors tuile valeur, `{"v": "Ouverte", "t": "warn"}` sinon. `rev` couvre `values`.
`ui.readonly` apparaît en schéma 3 ; un écran en lecture seule voit **tous** ses
`press` refusés (`403 read_only`), quel que soit son schéma. Un `press` sur une
tuile valeur rend `unknown_button`.

⚠ Les `id` d'un même bouton diffèrent d'un schéma à l'autre : un `id` n'a de sens
que dans le schéma où il a été reçu. « Voir ce que la carte reçoit » montre les
trois réponses.

### Erreurs

| `error` | HTTP | Cause |
|---|---|---|
| `bad_apikey` | 401 | clé absente ou invalide |
| `read_only` | 403 | `press` sur un écran en lecture seule (v3.0) |
| `unknown_device` | 404 | aucun écran pour cette MAC, ou écran désactivé |
| `unknown_button` | 404 | identifiant de bouton inconnu pour cet écran |
| `firmware_unavailable` | 404 | une mise à jour est annoncée mais le binaire a disparu du dépôt |
| `bad_request` | 400 | paramètre manquant, MAC malformée, ou action inconnue |

Un écran **désactivé** répond `unknown_device` : désactiver un équipement dans
Jeedom doit couper ce qu'il commande, pas le laisser déclencher des actions
depuis un mur.

### `action=firmware` — y a-t-il une mise à jour ?

```
GET ...core/php/api.php?action=firmware&device=246f28123456&fw=1.3.0
X-GLOWSCREEN32-APIKEY: <clé>
```

`fw` est la version **que la carte exécute**. Elle est obligatoire, et elle sert
à deux choses : décider s'il y a plus récent à proposer, et renseigner la
colonne « Firmware » du tableau du parc.

Rien à faire — ou OTA bloqué :

```json
{ "ok": true, "update": false }
```

Mise à jour disponible :

```json
{
  "ok": true,
  "update": true,
  "version": "1.4.0",
  "url": "http://192.168.1.10/plugins/glowscreen32/core/php/api.php?action=fwfile&token=629fa993…",
  "sha256": "0fbb3369…",
  "size": 1002288
}
```

La carte télécharge l'URL telle quelle — un jeton valable 15 minutes, lié au
binaire déposé (empreinte SHA-256) et refusé si l'un des deux verrous a été
refermé depuis —, vérifie **l'empreinte SHA-256 avant de basculer**,
écrit dans la partition inactive et redémarre dessus.

La version annoncée est retenue à **n'importe quel** appel qui porte `fw`, y
compris un `ping` : le plugin ne l'écrit que lorsqu'elle a changé, si bien que
renseigner le parc ne coûte rien de plus qu'une comparaison de chaînes.

## Mise à jour du firmware par le réseau (OTA)

C'est la seule fonction où Jeedom peut **casser durablement** un écran à
distance : une image défectueuse écrite dans la partition inactive, et il faut
décrocher la carte du mur pour la rebrancher en USB. Tout ce qui suit en
découle.

### Le double verrou

| Verrou | Où | Défaut |
|---|---|---|
| `ota_enabled` | **global au plugin** — page du plugin, cadre « Firmware » | **fermé** |
| `ota_allowed` | **par écran** — onglet « Écran » de l'équipement | **fermé** |

**Les deux** doivent être ouverts pour qu'un écran reçoive `update: true`. Ce
n'est pas une ceinture et des bretelles : les deux verrous ne font pas le même
travail.

- Le verrou **par écran** permet le **déploiement progressif**. On ouvre un seul
  écran témoin, on vérifie qu'il revient en ligne et qu'il fonctionne, puis on
  ouvre les autres. Sans lui, une mauvaise version part partout en même temps,
  et l'on découvre le défaut sur six murs au lieu d'un.
- Le verrou **global** permet d'**arrêter net** la propagation. Un firmware qui
  s'avère défectueux se coupe d'un seul interrupteur : les écrans qui n'ont pas
  encore mis à jour continuent d'interroger et reçoivent `update: false`, sans
  qu'il faille rouvrir chaque équipement un par un.

Une carte bloquée reçoit **exactement la même réponse** qu'une carte à jour, et
c'est voulu : elle n'a aucun moyen de faire la différence, donc aucun moyen de
passer outre. La décision est entièrement côté serveur.

### Déposer un firmware

1. **Page du plugin → cadre « Firmware (mise à jour par le réseau) »**, bouton
   **Parcourir**, puis **Déposer**. Le fichier attendu est le `.bin` produit par
   PlatformIO (`.pio/build/cyd/firmware.bin`).
2. Le plugin **refuse** un fichier qui ne commence pas par l'octet `0xE9` : ce
   n'est alors pas une image d'application ESP32, et la déposer reviendrait à
   promettre à une carte quelque chose qui l'empêcherait de redémarrer.
3. La **version est lue dans le binaire**, derrière le marqueur
   `GLOWSCREEN32-FW:` que le firmware y grave lui-même, jusqu'au premier octet
   nul. C'est la même chaîne que celle que la carte annonce à
   `action=firmware` : comparer les deux a donc un sens, ce qui ne serait pas
   le cas d'un numéro retapé à la main.

   **Un binaire sans ce marqueur est refusé**, même s'il s'agit d'une image
   ESP32 valide. C'est délibéré : sans version, il n'y a rien à comparer, et
   publier quand même reviendrait soit à ne jamais déclencher de mise à jour,
   soit à en déclencher une sur un binaire qui n'est pas le nôtre. L'outil de
   calibration tactile, par exemple, ne porte pas de marqueur — il ne peut donc
   pas partir en OTA, ce qui est exactement ce qu'on veut.

   > **Pourquoi pas le descripteur ESP-IDF de l'image.** Elle en porte bien un
   > (`esp_app_desc_t`, mot magique `0xABCD5432`), avec un champ « version », et
   > la 1.2 le lisait. Sur un vrai binaire du projet il contient
   > « esp-idf: v4.4.7 … » et « arduino-lib-builder » : il vient des
   > bibliothèques Arduino **précompilées** du framework, pas de notre code, et
   > il est donc identique dans tous nos binaires. Une version identique
   > partout, c'est un OTA qui ne se déclenche jamais — et le défaut était
   > silencieux, le dépôt réussissant et la version ayant l'air d'une version.
   > D'où un marqueur à nous.
4. Le **SHA-256 et la taille** sont calculés ici, sur le fichier réellement
   écrit, et jamais repris d'une saisie.
5. Le binaire est rangé dans `data/firmware/` du plugin, sous le nom
   `glowscreen32-<version>.bin`. Déposer une nouvelle version **remplace** la
   précédente : il n'y a jamais qu'un firmware proposé à la fois.

Déposer ne déverrouille rien. C'est la marche à suivre : on dépose, puis on
ouvre le verrou global, puis un seul écran témoin.

### Ce que le déploiement du plugin ne touche pas

`deploy-plugin.sh` fait un `rsync --delete` : tout ce que le dépôt de
développement ne contient pas disparaît de l'installation. Le binaire, lui,
n'arrive jamais par le dépôt — il est déposé par l'utilisateur, dans
l'installation. `.deployignore` exclut donc `data/firmware/*.bin`, et un fichier
exclu n'est pas supprimé par `--delete`. **Un redéploiement n'efface pas le
firmware déposé** ; c'est vérifié par `tests/check-classes.php`, qui refuse un
`.deployignore` sans cette ligne.

L'exclusion ne vise que les binaires, et non le dossier : `data/firmware/.htaccess`
fait partie du plugin et doit continuer d'être déployé.

### Pourquoi un `.htaccess` de plus

`data/.htaccess` porte `Deny from all`. Le firmware, lui, est téléchargé par une
**carte**, pas par un navigateur authentifié : `data/firmware/.htaccess` rouvre
donc les seuls fichiers `.bin`, exactement comme `plugin_info/.htaccess` rouvre
les seules images. Sans cette exception, la carte reçoit un 403 au milieu de sa
mise à jour, et `log/http.error` une ligne « client denied by server
configuration » — la panne qu'a déjà eue l'icône du plugin, au même endroit et
pour la même raison.

### Piloter un déploiement

Le tableau du parc, sur la page du plugin, donne pour chaque écran : le nom, la
MAC, le dernier contact, **la version du firmware que la carte annonce**, et
**l'état des deux verrous**. C'est avec ces deux dernières colonnes qu'on suit
un déploiement progressif : on ouvre un écran, on attend qu'il repasse en ligne
avec sa nouvelle version, puis on ouvre le suivant.

Si quelque chose tourne mal : **fermer le verrou global**. Les écrans restés en
arrière reçoivent `update: false` dès leur appel suivant.

### Le retour arrière, côté carte

Le plugin ne peut pas rattraper une image qui ne démarre pas : c'est au firmware
de le faire. La carte ne doit se déclarer saine
(`esp_ota_mark_app_valid_cancel_rollback()`) qu'**après** avoir vérifié qu'elle
a le Wi-Fi, que l'API répond et que l'écran s'est initialisé. Sinon l'ESP32
rebascule seul sur la partition précédente. Ne jamais marquer le firmware valide
dès `setup()` : ce serait désactiver le filet tout en croyant l'avoir.

### Les scénarios

Un bouton peut lancer un scénario au lieu d'une commande : c'est un bouton en
mode **Action simple** dont la cible est un scénario. Depuis la v1.3 du contrat
cela ne demande plus aucune convention particulière — `id` étant un rang, un
bouton-scénario porte un rang comme les autres, et le firmware n'a rien à savoir
de ce qu'il déclenche. La convention de l'identifiant négatif de la v1.0 n'a
plus de raison d'être et a disparu.

## Les commandes de l'équipement

Des informations écrites au fil des échanges avec la carte, et, depuis la
v2.2, des actions. Aucune information n'est nécessaire au dialogue : elles existent pour que l'écran soit un équipement
ordinaire sur le dashboard, et qu'un scénario puisse réagir à un appui.

| Commande | Sens |
|---|---|
| Version de la mise en page | le compteur que la carte surveille |
| Dernier contact | horodaté à chaque appel reçu, `layout` comme `ping` |
| Dernier bouton | le libellé du dernier bouton appuyé |
| Version du firmware | la version que la carte annonce, écrite seulement quand elle change |
| **En ligne** | binaire, v2.1 — 1 dès qu'un appel arrive, 0 quand le cron constate le silence |
| **Niveau Wi-Fi** | numérique en dBm, v2.1 — le `rssi` annoncé par la carte au `ping` |
| Durée de fonctionnement | v2.2 — secondes depuis le démarrage de la carte (`up`) |
| Cause du redémarrage | v2.2 — `poweron`, `sw`, `panic`, `wdt`, `brownout`, `ext`, `other` |
| Mémoire libre, Plus gros bloc libre | v2.2 — octets, **historisés** ; le second mesure la fragmentation |
| Adresse IP, Réseau Wi-Fi | v2.2 — où est l'écran sur le réseau |
| **Redémarrer, Identifier, Message, Page, Calibrer, Vérifier firmware** | actions, v2.2 — mettent une commande à distance en file ; utilisables depuis un scénario. Message : le champ « titre » porte la durée en secondes (30 si vide) |

Le changement de Wi-Fi n'est **pas** une commande Jeedom : il fait transiter un
mot de passe, et ne part que du cadre « Changer le Wi-Fi de la carte » de
l'onglet Écran, par un administrateur.

### En ligne — v2.1

Jusqu'ici, « l'écran du couloir ne répond plus » n'avait de réponse que dans le
tableau du parc, sur la page du plugin. Aucun scénario ne pouvait réagir à un
panneau mural devenu noir ; il fallait passer devant pour s'en apercevoir.

La commande **En ligne** en fait un fait Jeedom ordinaire : historisable,
affichable, utilisable en déclencheur. Elle passe à 1 dès qu'un appel arrive, et
c'est un **cron d'une minute** qui la remet à 0 — un écran hors ligne est
justement celui qui n'appelle plus, il n'y a personne pour poser le zéro à sa
place. Les deux transitions laissent une ligne de journal, et elles seules.

### Niveau Wi-Fi — v2.1

La qualité de la liaison est la première cause de panne du projet, et la plus
trompeuse : les `ping` passent encore là où un `press` se perd (−88 dBm) et où
un OTA meurt à 2 % (−92/−93 dBm). Tout a donc l'air de fonctionner.

Le firmware affiche déjà le niveau au bandeau sous **−75 dBm**, mais il faut se
tenir devant l'écran pour le lire — sur un panneau mural, au fond d'un couloir,
c'est précisément ce qu'on ne fait pas. Remonté ici, il devient lisible depuis
le dashboard, et permet de **prévenir avant** de lancer un OTA sur une liaison
qui ne le supportera pas.

**Dernier contact** est la façon de savoir si un écran est en ligne. Depuis la
v2.2 il n'est écrit **que** dans cette commande d'information : la v2.1
l'écrivait aussi dans la configuration de l'équipement, ce qui imposait de
réenregistrer l'eqLogic à chaque contact — et écrasait toute configuration
enregistrée pendant ce temps. La valeur v2.1 de la configuration reste lue en
repli, pour un écran qui n'a pas rappelé depuis la mise à jour.

Il est arrondi **à la minute**, et c'est délibéré : une carte interroge toutes
les trente secondes, et horodater chaque appel ferait un événement Jeedom par
écran et par demi-minute pour une information dont personne ne lit la seconde.
Un appui, lui, est rare et intéressant : il est toujours écrit.

La page du plugin l'affiche en clair — « 22/09/2026 11:20:07 (il y a 2 min) » —
dans l'onglet Écran et dans le tableau du parc, avec une étiquette **hors
ligne** au-delà de **trois intervalles maximaux** sans nouvelle, soit
`3 × 2 × poll` depuis la v2.1 : la carte espace ses pings quand son écran est
atténué, et compter sur `poll` seul ferait passer tout le parc hors ligne chaque
nuit.

## Journal

`log::add('glowscreen32', …)`, visible dans **Analyse → Journaux →
glowscreen32**. Les appuis y sont en `info`, les clés refusées en `warning`, les
cartes non déclarées en `debug`.

**Chaque décision d'OTA y laisse une ligne** : la version que la carte annonce,
la réponse donnée, et — quand elle est négative — **le ou les verrous qui ont
bloqué**, nommés l'un et l'autre. C'est la seule chose qui réponde à « pourquoi
cet écran-là ne se met pas à jour ? » : la carte, elle, reçoit la même réponse
que si elle était à jour, et ne peut donc rien en dire. Les changements du
verrou global et les dépôts de firmware y figurent aussi.

Ces lignes sont en `info` : si le niveau de journalisation du plugin est réglé
sur « Error », elles n'apparaissent pas. **Analyse → Journaux → configuration**,
ou l'engrenage du plugin, permet de le régler sur « Info » le temps d'un
déploiement.

En cas d'enregistrement qui « ne fait rien », c'est `log/http.error` qu'il faut
regarder en premier : une erreur fatale de PHP n'atteint jamais le journal du
plugin.
