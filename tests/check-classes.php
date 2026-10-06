<?php
/* Les deux pièges de la section 8 de STRUCTURE-PLUGIN-JEEDOM.md, vérifiés par
 * réflexion sur le source.
 *
 *   php tests/check-classes.php
 *
 * Ces deux fautes ont chacune rendu un plugin inutilisable en production, et
 * aucune des deux ne se voit à la relecture, ni au `php -l`, ni en test hors
 * ligne : elles ne se manifestent que dans un vrai Jeedom, et le symptôme ne
 * désigne jamais la cause. Le contrôle, lui, tient en trente lignes — il n'y a
 * aucune raison de s'en passer.
 *
 * Le fichier n'est pas chargé : il est analysé en texte. Charger la classe
 * exigerait tout le coeur de Jeedom, et le contrôle ne serait plus lançable
 * depuis un poste de développement.
 */

$source = file_get_contents(__DIR__ . '/../core/class/glowscreen32.class.php');
$echecs = array();

/* --- Piège A : une propriété sans souligné devient une colonne de table ----
 * DB::save() traite comme colonne toute propriété dont le nom ne commence pas
 * par « _ ». Symptôme : « Unknown column », et le bouton Ajouter ne fait rien. */
preg_match_all('/^\s*(?:public|protected|private)\s+(?:static\s+)?\$([A-Za-z_][A-Za-z0-9_]*)/m',
    $source, $proprietes);
foreach ($proprietes[1] as $propriete) {
    if ($propriete[0] !== '_') {
        $echecs[] = 'Propriété sans souligné : $' . $propriete
                  . ' — DB::save() la prendra pour une colonne SQL.';
    }
}

/* --- Piège B : une méthode « set » + clé de formulaire est appelée par le coeur
 * utils::a2o() construit « set » . ucfirst(clé) pour chaque clé reçue et
 * l'appelle sur l'objet du plugin. La page envoie toujours une clé « cmd ». */
$interdits = array('setId', 'setName', 'setLogicalId', 'setGeneric_type', 'setObject_id',
                   'setEqType_name', 'setIsVisible', 'setIsEnable', 'setConfiguration',
                   'setTimeout', 'setCategory', 'setDisplay', 'setOrder', 'setComment',
                   'setTags', 'setCmd');
preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $methodes);
foreach ($methodes[1] as $methode) {
    if (in_array($methode, $interdits, true)) {
        $echecs[] = 'Méthode interdite : ' . $methode . '() — utils::a2o() l\'appellera '
                  . 'à l\'enregistrement et tuera la sauvegarde.';
    }
}

/* --- La classe des commandes est obligatoire, même vide -------------------- */
if (strpos($source, 'class glowscreen32Cmd extends cmd') === false) {
    $echecs[] = 'class glowscreen32Cmd extends cmd est absente : la sauvegarde d\'un '
              . 'équipement échouera.';
}

/* --- Aucun .htaccess ne doit fermer le point d'entrée des cartes ----------- */
if (file_exists(__DIR__ . '/../core/php/.htaccess')) {
    $echecs[] = 'core/php/.htaccess existe : il rendrait le point d\'entrée des écrans '
              . 'inaccessible.';
}

/* --- Le .htaccess de plugin_info ne doit pas bloquer l'icône -------------- */
$htaccess = __DIR__ . '/../plugin_info/.htaccess';
if (!file_exists($htaccess)) {
    $echecs[] = 'plugin_info/.htaccess est absent : configuration.php serait servi en clair.';
} elseif (strpos(file_get_contents($htaccess), 'allow from all') === false) {
    $echecs[] = 'plugin_info/.htaccess interdit tout, images comprises : la vignette du '
              . 'plugin ne s\'affichera nulle part et chaque affichage déposera un '
              . '« client denied by server configuration » dans log/http.error.';
}

/* --- Le mode de bouton du contrat v1.3 doit exister ------------------------
 * Sans lui, un bouton lié à la seule commande « Allumer » d'un Shelly allume
 * sans jamais éteindre : c'est le défaut constaté sur le mur en v1.0, et il ne
 * se voit ni au php -l, ni à la relecture d'un formulaire. */
foreach (array('MODE_TOGGLE', 'MODE_ACTION', 'pressToggle', 'checkButtons') as $attendu) {
    if (strpos($source, $attendu) === false) {
        $echecs[] = $attendu . ' est absent : le mode de bouton du contrat v1.3 n\'est '
                  . 'plus mis en oeuvre.';
    }
}

/* --- v2.2 : l'API n'enregistre JAMAIS l'eqLogic -----------------------------
 * Jusqu'en v2.1, noteContact(), noteFirmware() et noteInfoStale() faisaient
 * save(true) : l'eqLogic entier, tel que chargé au DÉBUT de la requête, était
 * réécrit, et une configuration enregistrée entre-temps était écrasée. Avec un
 * ping retenu 25 s, l'écrasement devenait systématique. Le dernier contact vit
 * désormais dans la commande « Dernier contact ». */
foreach (array('noteContact', 'noteFirmware', 'noteInfoStale', 'noteRssi',
               'noteDiagnostics', 'noteOnline', 'noteIncompleteButtons',
               'holdPing', 'ping', 'dequeueCommand', 'viewResult', 'values') as $nom) {
    if (preg_match('/function ' . $nom . '\(.*?\n    \}/s', $source, $methode)) {
        if (preg_match('/->save\(/', $methode[0])) {
            $echecs[] = $nom . '() enregistre un objet : l\'API ne doit jamais appeler save() '
                      . 'sur l\'eqLogic (contrat v2.2).';
        }
    } else {
        $echecs[] = $nom . '() est introuvable.';
    }
}
$api = file_get_contents(__DIR__ . '/../core/php/api.php');
if (preg_match('/->save\(/', $api)) {
    $echecs[] = 'api.php appelle save() : contrat v2.2, l\'API n\'enregistre jamais l\'eqLogic.';
}
foreach (array("'features'", "'rev'", 'holdPing', 'LONGPOLL_MAX', 'pullChange',
               'enqueueCommand', 'CMD_QUEUE_MAX', 'CMD_TTL') as $attendu) {
    if (strpos($source, $attendu) === false) {
        $echecs[] = $attendu . ' est absent : l\'attente longue ou les commandes à distance '
                  . 'du contrat v2.2 ne sont plus mises en oeuvre.';
    }
}

/* --- v3.0 : schéma 3, tuiles « view », lecture seule ------------------------
 * Une carte de schéma 2 prendrait une tuile « view » pour un bouton : elle doit
 * lui être RETIRÉE, et « press » doit résoudre le rang dans l'aplatissement du
 * schéma de la requête. La lecture seule est refusée par le plugin AVANT toute
 * résolution. Et tout ce qui vaut « schéma 2 ou plus » se teste sur SCHEMA_V2. */
foreach (array('SCHEMA_V2', 'MODE_VIEW', 'buttonsFor', 'viewResult', 'viewDefaults',
               'readOnly', "'values'", "'readonly'") as $attendu) {
    if (strpos($source, $attendu) === false) {
        $echecs[] = $attendu . ' est absent : le schéma 3 du contrat v3.0 n\'est plus mis en oeuvre.';
    }
}
if (preg_match('/function buttonsFor.*?\n    \}/s', $source, $methode)) {
    if (strpos($methode[0], 'MODE_VIEW') === false) {
        $echecs[] = 'buttonsFor() ne retire plus les tuiles « view » en schéma 2.';
    }
}
if (preg_match('/function press\(.*?\n    \}/s', $source, $methode)) {
    if (strpos($methode[0], 'buttonsFor(') === false || strpos($methode[0], 'MODE_VIEW') === false) {
        $echecs[] = 'press() ne résout plus le rang dans l\'aplatissement du schéma négocié, ou joue une tuile « view ».';
    }
}
$apiV3 = file_get_contents(__DIR__ . '/../core/php/api.php');
$posRo = strpos($apiV3, "'read_only'");
$posPress = strpos($apiV3, '$eqLogic->press(');
if ($posRo === false || $posPress === false || $posRo > $posPress) {
    $echecs[] = 'api.php ne refuse pas read_only AVANT press() : un écran en lecture seule pourrait déclencher un bouton.';
}
if (preg_match('/>=\s*glowscreen32::SCHEMA_CURRENT/', $apiV3)) {
    $echecs[] = 'api.php teste « >= SCHEMA_CURRENT » : l\'attente longue et les commandes ne seraient plus servies en schéma 2.';
}

/* --- v3.1 : corrections de la revue -----------------------------------------
 * Le plus grave : un bouton non résolu RETIRÉ de l'aplatissement décalait tous
 * les id suivants — le bouton d'à côté. Il doit garder son rang, inerte. */
if (preg_match('/function activeButtons\(.*?\n    \}/s', $source, $methode)) {
    if (strpos($methode[0], "'_inert'") === false) {
        $echecs[] = 'activeButtons() ne marque plus les boutons non résolus « _inert » : ils seraient retirés, et les id décalés.';
    }
    if (preg_match('/if \(!self::buttonResolves\(\$button\)\)\s*\{[^}]*continue;/s', $methode[0])) {
        $echecs[] = 'activeButtons() RETIRE un bouton non résolu : bouton d\'à côté garanti au prochain appui.';
    }
}
if (preg_match('/function press\(.*?\n    \}/s', $source, $methode)) {
    if (strpos($methode[0], "_inert") === false || strpos($methode[0], 'servedKey') === false) {
        $echecs[] = 'press() ne refuse plus un bouton inerte, ou ne compare plus au dernier layout servi.';
    }
}
foreach (array('backupExclude', 'rememberServedLayout', 'forgetMemo', 'purgeSecrets', 'isSensitiveCmd',
               'function copy(', 'checkLengths', 'pageCapacity()') as $attendu) {
    if (strpos($source, $attendu) === false) {
        $echecs[] = $attendu . ' est absent : une correction de la revue v3.1 a disparu.';
    }
}
if (preg_match('/function trimText.*?\n    \}/s', $source, $methode) && strpos($methode[0], 'strip_tags((') !== false) {
    $echecs[] = 'trimText() utilise strip_tags() : « <5 » serait effacé.';
}
if (!preg_match('/const LONGPOLL_RECHECK = 15;/', $source)) {
    $echecs[] = 'LONGPOLL_RECHECK n\'est plus de 15 s : une requête retenue recalculerait trop souvent.';
}
$apiV31 = file_get_contents(__DIR__ . '/../core/php/api.php');
foreach (array('Content-Length', 'JSON_INVALID_UTF8_SUBSTITUTE', '!is_string($apikey)', 'ctype_digit($id)',
               'rememberServedLayout', 'holdSuperseded', 'lastHoldWhy') as $attendu) {
    if (strpos($apiV31, $attendu) === false) {
        $echecs[] = 'api.php : ' . $attendu . ' est absent (revue v3.1).';
    }
}
if (strpos((string) @file_get_contents(__DIR__ . '/../.deployignore'), 'data/secrets/') === false) {
    $echecs[] = '.deployignore n\'exclut pas data/secrets/ : un déploiement effacerait (ou publierait) les mots de passe en attente.';
}

/* --- v3.2 (contrat v3.1) : pages masquables ------------------------------
 * Les pages masquées ne sont pas servies, les visibles sont renumérotées, un
 * « nav » vers une page masquée est retiré, et il reste toujours une page. */
foreach (array('pageMap', 'servedParent', 'servedTitle', 'applyPageVisibility', 'refreshPageCommands',
               "'page_show'", "'page_hide'", "'page_only'", "'page_all'", "'pages_visible'") as $attendu) {
    if (strpos($source, $attendu) === false) {
        $echecs[] = $attendu . ' est absent : les pages masquables du contrat v3.1 ne sont plus mises en oeuvre.';
    }
}
if (preg_match('/function activeButtons\(.*?\n    \}/s', $source, $methode) && strpos($methode[0], 'pageMap()') === false) {
    $echecs[] = 'activeButtons() ne tient plus compte des pages masquées : leurs boutons seraient servis et numérotés.';
}
if (preg_match('/function dequeueCommand\(.*?\n    \}/s', $source, $methode) && strpos($methode[0], 'pageMap()') === false) {
    $echecs[] = 'dequeueCommand() ne traduit plus la position de page en numéro servi.';
}
if (strpos($source, 'Au moins une page doit rester affichée') === false) {
    $echecs[] = 'preSave() ne refuse plus une configuration sans page visible.';
}

/* --- Le double verrou d'OTA du contrat v1.4 --------------------------------
 * C'est le point de sécurité du plugin : deux verrous indépendants, fermés par
 * défaut, tous deux côté serveur. Un refactoring qui en perdrait un laisserait
 * un plugin qui marche, qui passe le php -l, et qui pousse un firmware sur tout
 * le parc à la première occasion. */
foreach (array('ota_enabled', 'ota_allowed', 'otaDecision', 'otaAllowed',
               'otaEnabled', 'publishFirmware', 'noteFirmware') as $attendu) {
    if (strpos($source, $attendu) === false) {
        $echecs[] = $attendu . ' est absent : le double verrou d\'OTA du contrat v1.4 '
                  . 'n\'est plus mis en oeuvre.';
    }
}

/* Les deux verrous doivent être exigés ENSEMBLE. La formulation attendue est
 * celle d'otaDecision() : on additionne les verrous fermés, et l'on refuse dès
 * qu'il y en a un. Vérifier la présence des deux tests dans la même méthode,
 * c'est vérifier que l'un n'a pas été rendu facultatif. */
if (preg_match('/function otaDecision.*?\n    \}/s', $source, $methode)) {
    foreach (array('self::otaEnabled()', '$this->otaAllowed()') as $verrou) {
        if (strpos($methode[0], $verrou) === false) {
            $echecs[] = 'otaDecision() ne consulte pas ' . $verrou . ' : un seul des deux '
                      . 'verrous suffirait alors à pousser un firmware sur un écran.';
        }
    }
    if (strpos($methode[0], 'firmware_unavailable') === false && strpos($source, 'firmware_unavailable') === false) {
        $echecs[] = 'firmware_unavailable n\'apparaît nulle part : le cas « update annoncé, '
                  . 'fichier disparu » du contrat v1.4 n\'est pas traité.';
    }
} else {
    $echecs[] = 'otaDecision() est introuvable : la décision d\'OTA du contrat v1.4 n\'existe plus.';
}

/* --- La version ne doit PLUS venir du descripteur ESP-IDF ------------------
 * Le descripteur normalisé de l'image (mot magique 0xABCD5432, offset 0x20)
 * vient des bibliothèques Arduino PRÉCOMPILÉES du framework, pas de notre code :
 * il annonce invariablement « esp-idf: v4.4.7 … » / « arduino-lib-builder ».
 * Identique dans tous nos binaires, donc un OTA qui ne se déclenche JAMAIS —
 * et le défaut est silencieux : le dépôt réussit, la version a l'air d'une
 * version, et aucun écran ne reçoit rien. La version vient désormais d'un
 * marqueur que le firmware grave lui-même. */
foreach (array('ESP_APP_DESC_MAGIC', 'ESP_APP_DESC_OFFSET', 'ESP_APP_VERSION_OFFSET',
               'ESP_APP_VERSION_LENGTH', 'imageVersion') as $disparu) {
    if (strpos($source, $disparu) !== false) {
        $echecs[] = $disparu . ' est de retour : la version serait de nouveau lue dans le '
                  . 'descripteur ESP-IDF, identique dans tous nos binaires — l\'OTA ne se '
                  . 'déclencherait jamais.';
    }
}
foreach (array('FIRMWARE_MARKER', 'markerVersion') as $attendu) {
    if (strpos($source, $attendu) === false) {
        $echecs[] = $attendu . ' est absent : la version du firmware déposé ne se lit plus '
                  . 'nulle part.';
    }
}
if (strpos($source, "const FIRMWARE_MARKER = 'GLOWSCREEN32-FW:'") === false) {
    $echecs[] = 'Le préfixe du marqueur n\'est plus « GLOWSCREEN32-FW: » : il est gravé dans '
              . 'le binaire par src/fw_version.cpp, les deux côtés doivent dire la même chose.';
}
/* publishFirmware() doit effectivement s'en servir, et refuser à défaut :
 * garder la méthode sans l'appeler laisserait passer n'importe quel binaire. */
if (preg_match('/function publishFirmware.*?\n    \}/s', $source, $methode)) {
    if (strpos($methode[0], 'self::markerVersion(') === false) {
        $echecs[] = 'publishFirmware() n\'appelle pas markerVersion() : la version ne vient '
                  . 'plus du binaire.';
    }
    if (strpos($methode[0], 'ESP_IMAGE_MAGIC') === false) {
        $echecs[] = 'publishFirmware() ne vérifie plus l\'octet 0xE9 : c\'est la seule '
                  . 'garantie que le fichier déposé soit une image ESP32.';
    }
} else {
    $echecs[] = 'publishFirmware() est introuvable : plus rien ne contrôle ce qui est déposé.';
}

/* --- Le firmware déposé doit survivre à un déploiement ---------------------
 * deploy-plugin.sh fait un rsync --delete : sans cette exclusion, le premier
 * redéploiement venu efface un binaire que le dépôt de développement ne
 * contient pas, et les écrans se voient proposer une URL qui rend 404. */
$deployignore = __DIR__ . '/../.deployignore';
if (!file_exists($deployignore)) {
    $echecs[] = '.deployignore est absent : le firmware téléversé sera effacé au prochain '
              . 'déploiement par le rsync --delete de deploy-plugin.sh.';
} elseif (strpos(file_get_contents($deployignore), 'data/firmware/*.bin') === false) {
    $echecs[] = '.deployignore n\'exclut pas data/firmware/*.bin : le firmware téléversé '
              . 'sera effacé au prochain déploiement.';
}

/* --- Le binaire doit être téléchargeable par la carte ---------------------
 * data/.htaccess porte « Deny from all » : sans exception dans
 * data/firmware/.htaccess, la carte reçoit un 403 au milieu de sa mise à jour.
 * C'est exactement la panne qu'avait l'icône du plugin, au même endroit. */
$htFirmware = __DIR__ . '/../data/firmware/.htaccess';
if (!file_exists($htFirmware)) {
    $echecs[] = 'data/firmware/.htaccess est absent : le « Deny from all » de data/ '
              . 'interdira à la carte de télécharger le binaire.';
} else {
    $contenu = file_get_contents($htFirmware);
    if (strpos($contenu, 'allow from all') === false || strpos($contenu, '.bin') === false) {
        $echecs[] = 'data/firmware/.htaccess ne rouvre pas les fichiers .bin : la carte '
                  . 'recevra un 403 au milieu de sa mise à jour.';
    }
}

/* --- Le schéma 2 du contrat v2.0 ------------------------------------------
 * La négociation de schéma est ce qui permet de publier ce plugin sans rendre
 * le parc entier inutilisable au même instant. Un refactoring qui la perdrait
 * laisserait un plugin qui marche parfaitement... devant des cartes qui
 * n'affichent plus rien, et qui ne peuvent donc plus recevoir l'OTA qui les
 * réparerait. */
foreach (array('SCHEMA_LEGACY', 'SCHEMA_CURRENT', 'MODE_NAV', 'MAX_PAGES',
               'MAX_BUTTONS_PER_PAGE', 'LEGACY_MAX_BUTTONS', 'legacyButtons',
               'layoutLegacy', 'layoutV2', 'tzOffset', 'infoText', 'resolveIcon', 'legacyIcon') as $attendu) {
    if (strpos($source, $attendu) === false) {
        $echecs[] = $attendu . ' est absent : le schéma 2 du contrat v2.0 n\'est plus mis '
                  . 'en oeuvre.';
    }
}

$api = file_get_contents(__DIR__ . '/../core/php/api.php');
if (strpos($api, 'HTTP_X_GLOWSCREEN32_SCHEMA') === false) {
    $echecs[] = 'api.php ne lit plus l\'en-tête X-GLOWSCREEN32-SCHEMA : toutes les cartes '
              . 'recevraient le même schéma, et celles qui ne savent pas le lire '
              . 'n\'afficheraient plus rien.';
}
/* press() doit recevoir le schéma. C'est LE point délicat du contrat v2.0 :
 * une carte de schéma 1 a reçu six boutons renumérotés de 0 à 5, sans les
 * boutons « nav ». Résoudre son rang dans la numérotation globale ferait jouer
 * le bouton d'à côté — et sur ce projet, le bouton d'à côté a déjà ouvert un
 * portail. */
if (!preg_match('/press\(\$id,\s*\$schema\)/', $api)) {
    $echecs[] = 'api.php n\'transmet plus le schéma à press() : le rang reçu d\'une carte '
              . 'de schéma 1 serait résolu dans le mauvais aplatissement, et un appui '
              . 'jouerait la commande d\'un autre bouton.';
}

/* --- Le piège de la signature de mise en page -----------------------------
 * buttonSignature() et layoutSignature() énumèrent leurs champs EN DUR. Un
 * champ v2.0 oublié, et « version » ne bouge pas : l'enregistrement réussit, la
 * page montre la nouvelle mise en page, et le mur affiche l'ancienne
 * indéfiniment. Rien ne le signale. */
if (preg_match('/function layoutSignature.*?\n    \}/s', $source, $methode)) {
    foreach (array("'pages'", "'grid'", "'swipe'", "'clock'", "'info'") as $champ) {
        if (strpos($methode[0], $champ) === false) {
            $echecs[] = 'layoutSignature() ne tient pas compte de ' . $champ . ' : le modifier '
                      . 'ne ferait plus bouger « version », et aucun écran du parc ne se '
                      . 'redessinerait.';
        }
    }
} else {
    $echecs[] = 'layoutSignature() est introuvable : plus rien ne fait bouger « version ».';
}
if (preg_match('/function buttonSignature.*?\n    \}/s', $source, $methode)) {
    foreach (array("\$_button['icon']", "\$_button['page']", "\$_button['slot']",
                   "\$_button['nav']") as $champ) {
        if (strpos($methode[0], $champ) === false) {
            $echecs[] = 'buttonSignature() ne tient pas compte de ' . $champ . ' : déplacer '
                      . 'un bouton ou changer son icône ne ferait plus bouger « version ».';
        }
    }
} else {
    $echecs[] = 'buttonSignature() est introuvable.';
}

/* --- Le schéma 1 doit rester IDENTIQUE, octet pour octet ------------------
 * « none » est la représentation interne du vocabulaire fermé de la v2.0 ; en
 * schéma 1, l'absence d'icône s'écrit par une chaîne vide, comme elle l'a
 * toujours fait. Sérialiser « none » à la place ferait écrire la flash d'une
 * carte v1.4 et redessiner tout le parc pour un champ que le firmware v1.4 ne
 * dessine nulle part. */
if (strpos($source, 'function legacyIcon') === false) {
    $echecs[] = 'legacyIcon() est absent : plus rien ne marque l\'endroit où le schéma 1 '
              . 'doit rendre l\'icône telle qu\'elle est stockée.';
}
if (preg_match('/function layoutLegacy.*?\n    \}/s', $source, $methode)) {
    if (strpos($methode[0], 'self::legacyIcon(') === false) {
        $echecs[] = 'layoutLegacy() ne repasse pas l\'icône par legacyIcon() : le schéma 1 '
                  . 'a dérivé.';
    }
    /* LE contrôle : le schéma 1 ne normalise RIEN. Ni minuscules, ni alias, ni
     * « none ». C'est un contrat figé, pas un endroit où appliquer les règles
     * du schéma 2 — et c'est ce qui rend « octet pour octet » vrai par
     * construction, pour tous les écrans, sans exception à retenir. */
    foreach (array('resolveIcon', 'strtolower', 'ICON_NONE', 'ICON_ALIASES') as $interdit) {
        if (strpos($methode[0], $interdit) !== false) {
            $echecs[] = 'layoutLegacy() applique ' . $interdit . ' à la réponse du schéma 1 : '
                      . 'ce schéma est figé, il rend ce qui est stocké, tel quel. Normaliser '
                      . 'ici réécrit un contrat que des cartes en service lisent déjà.';
        }
    }
}
if (preg_match('/function legacyIcon.*?\n    \}/s', $source, $methode)) {
    foreach (array('resolveIcon', 'strtolower', 'ICON_NONE', 'ICON_ALIASES', 'in_array') as $interdit) {
        if (strpos($methode[0], $interdit) !== false) {
            $echecs[] = 'legacyIcon() n\'est plus un passe-plat (' . $interdit . ' y apparaît) : '
                      . 'le schéma 1 ne rend plus la chaîne stockée telle quelle.';
        }
    }
}
if (preg_match('/function buttonSignature.*?\n    \}/s', $source, $methode)) {
    if (strpos($methode[0], 'self::legacyIcon(') === false) {
        $echecs[] = 'buttonSignature() ne repasse pas l\'icône par legacyIcon() : la '
                  . 'signature ne porterait plus sur la valeur STOCKÉE, et une configuration '
                  . 'existante ferait redessiner tout le parc pour rien.';
    }
}
/* Les alias sont la contrepartie du vocabulaire fermé : sans eux, fermer le
 * vocabulaire fait disparaître en silence l'icône d'un écran en service. */
if (strpos($source, 'const ICON_ALIASES') === false) {
    $echecs[] = 'ICON_ALIASES est absent : un nom hors vocabulaire dont l\'intention est '
              . 'claire serait perdu au lieu d\'être résolu.';
}
if (preg_match('/function layoutV2.*?\n    \}/s', $source, $methode)) {
    if (strpos($methode[0], 'self::resolveIcon(') === false) {
        $echecs[] = 'layoutV2() ne résout plus l\'icône : les alias ne serviraient à rien, '
                  . 'et un nom hors vocabulaire partirait tel quel vers un firmware qui ne '
                  . 'sait pas le dessiner.';
    }
}

/* --- La fraîcheur de la valeur du bandeau -------------------------------
 * C'est « collectDate » qu'il faut lire, PAS « valueDate ». Le coeur ne met
 * « valueDate » à jour que quand la valeur CHANGE : une température stable à
 * 18 °C depuis deux heures a une « valueDate » vieille de deux heures tout en
 * étant parfaitement fraîche. S'y fier masquerait des valeurs valides — le
 * défaut exactement symétrique de celui qu'on corrige, et tout aussi
 * silencieux. Constaté sur cette installation : collectDate 1 minute,
 * valueDate 81 minutes, sur la même commande et au même instant. */
if (preg_match('/function cmdAge.*?\n    \}/s', $source, $methode)) {
    if (strpos($methode[0], 'getCollectDate()') === false) {
        $echecs[] = 'cmdAge() ne lit pas getCollectDate() : la fraîcheur du bandeau serait '
                  . 'jugée sur la date du dernier CHANGEMENT de valeur, et une valeur stable '
                  . 'mais fraîche serait déclarée périmée.';
    }
    $collect = strpos($methode[0], 'getCollectDate()');
    $value   = strpos($methode[0], 'getValueDate()');
    if ($value !== false && $collect !== false && $value < $collect) {
        $echecs[] = 'cmdAge() consulte getValueDate() AVANT getCollectDate() : ce n\'est plus '
                  . 'un repli, c\'est la source principale.';
    }
} else {
    $echecs[] = 'cmdAge() est introuvable : plus rien ne périme une valeur de bandeau, et un '
              . 'écran afficherait indéfiniment la température d\'hier.';
}
if (preg_match('/function infoText.*?\n    \}/s', $source, $methode)) {
    foreach (array('infoMaxAge()', 'cmdAge(') as $attendu) {
        if (strpos($methode[0], $attendu) === false) {
            $echecs[] = 'infoText() n\'appelle pas ' . $attendu . ' : la valeur du bandeau '
                      . 'serait servie sans contrôle de fraîcheur.';
        }
    }
} else {
    $echecs[] = 'infoText() est introuvable.';
}
/* Le seuil est de la CONFIGURATION : le modifier doit faire redessiner. L'âge,
 * lui, est de l'ÉTAT et ne doit jamais entrer dans la signature — sinon chaque
 * péremption rechargerait toute la mise en page du parc. */
if (preg_match('/function layoutSignature.*?\n    \}/s', $source, $methode)) {
    if (strpos($methode[0], 'infoMaxAge()') === false) {
        $echecs[] = 'layoutSignature() ne tient pas compte du seuil de péremption du '
                  . 'bandeau : le modifier ne ferait pas bouger « version ».';
    }
    if (strpos($methode[0], 'cmdAge(') !== false || strpos($methode[0], 'infoText()') !== false) {
        $echecs[] = 'layoutSignature() consulte l\'ÂGE ou la VALEUR du bandeau : « version » '
                  . 'bougerait toute seule, et chaque péremption ferait recharger la mise en '
                  . 'page à tout le parc.';
    }
}

/* --- L'aplatissement du schéma 1 ------------------------------------------
 * Les boutons « nav » doivent en être exclus : une carte v1.4 ne connaît que
 * « action » et « toggle », et dessinerait une tuile qui, à l'appui, recevrait
 * unknown_button. */
if (preg_match('/function legacyButtons.*?\n    \}/s', $source, $methode)) {
    if (strpos($methode[0], 'MODE_NAV') === false) {
        $echecs[] = 'legacyButtons() n\'exclut plus les boutons de navigation : une carte '
                  . 'de schéma 1 en dessinerait un, et l\'appui rendrait unknown_button.';
    }
    if (strpos($methode[0], 'LEGACY_MAX_BUTTONS') === false) {
        $echecs[] = 'legacyButtons() ne borne plus l\'aplatissement à six boutons.';
    }
} else {
    $echecs[] = 'legacyButtons() est introuvable : l\'aplatissement du schéma 1 n\'existe plus.';
}

if (count($echecs) === 0) {
    echo "OK — aucune propriété sans souligné, aucune méthode interdite, double verrou d'OTA\n";
    echo "     en place, négociation de schéma et signature de mise en page complètes.\n";
    exit(0);
}
foreach ($echecs as $echec) {
    echo 'ÉCHEC : ' . $echec . "\n";
}
exit(1);
