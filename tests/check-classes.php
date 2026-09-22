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

/* --- Le dernier contact doit aller dans la CONFIGURATION -------------------
 * La v1.0 ne l'écrivait que dans une commande d'information, et
 * getConfiguration('lastcontact') rendait une chaîne vide sur un écran qui
 * dialoguait parfaitement. */
if (strpos($source, "setConfiguration('lastcontact'") === false) {
    $echecs[] = 'noteContact() n\'écrit pas lastcontact dans la configuration : '
              . 'getConfiguration(\'lastcontact\') rendra de nouveau une chaîne vide.';
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

if (count($echecs) === 0) {
    echo "OK — aucune propriété sans souligné, aucune méthode interdite, double verrou d'OTA en place.\n";
    exit(0);
}
foreach ($echecs as $echec) {
    echo 'ÉCHEC : ' . $echec . "\n";
}
exit(1);
