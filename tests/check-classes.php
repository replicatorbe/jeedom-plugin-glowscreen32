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

if (count($echecs) === 0) {
    echo "OK — aucune propriété sans souligné, aucune méthode interdite.\n";
    exit(0);
}
foreach ($echecs as $echec) {
    echo 'ÉCHEC : ' . $echec . "\n";
}
exit(1);
