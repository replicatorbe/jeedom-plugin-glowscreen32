<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * Le point d'entrée des écrans ESP32. Il met en oeuvre, à la lettre, le contrat
 * d'API v2.0 (docs/fr_FR/index.md) :
 *
 *   GET ?action=layout&device=246f28123456     → la mise en page
 *   GET ?action=press&device=…&id=0            → jouer le bouton de RANG 0
 *   GET ?action=ping&device=…                  → le compteur de version
 *   GET ?action=firmware&device=…&fw=1.3.0     → y a-t-il une mise à jour ?
 *
 * Depuis la v1.3, « id » est le RANG du bouton dans la mise en page (0 à 5) et
 * non un identifiant de commande Jeedom : la carte ne désigne plus ce qui doit
 * s'exécuter, elle désigne le bouton qu'on a touché. C'est le plugin qui en
 * déduit la commande, et son sens.
 *
 *   X-GLOWSCREEN32-APIKEY: <clé>               → l'authentification normale
 *   X-GLOWSCREEN32-SCHEMA: 2                   → ce que la carte sait lire
 *
 * LA NÉGOCIATION DE SCHÉMA EST LA PIÈCE QUI REND TOUT LE RESTE POSSIBLE.
 *
 * Absent, vide ou « 1 » → le schéma 1 à l'identique, octet pour octet. « 2 » ou
 * plus → le schéma 2. Le plugin ne répond JAMAIS au-dessus de ce qui est
 * annoncé.
 *
 * Sans elle, publier ce plugin rendrait TOUS les écrans inutilisables au même
 * instant : le plugin se déploie en une seconde et d'un seul coup, le parc se
 * met à jour écran par écran, en OTA, sur plusieurs jours — et un écran qui
 * n'affiche plus rien ne peut plus recevoir l'OTA qui le réparerait. Il
 * faudrait décrocher chaque carte et la rebrancher en USB.
 *
 * Corollaire d'ordre de déploiement : LE PLUGIN PART TOUJOURS EN PREMIER, le
 * firmware ensuite.
 *
 * Erreurs : bad_apikey 401, unknown_device 404, unknown_button 404,
 * firmware_unavailable 404, bad_request 400. Le corps est toujours du JSON,
 * succès comme échec.
 *
 * Il ne doit surtout pas y avoir de .htaccess « Deny from all » dans ce dossier :
 * ce fichier est appelé depuis le réseau par des cartes qui ne sont pas des
 * clients authentifiés d'Apache. C'est aussi la raison pour laquelle rien ici ne
 * dépend d'une session.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* L'autochargeur du coeur ne résout la classe du plugin que depuis une page du
 * plugin : ce fichier, lui, est appelé directement par une carte. */
require_once __DIR__ . '/../class/glowscreen32.class.php';

/* Une réponse, et la fin de la requête. Aucun chemin ne doit produire autre
 * chose que du JSON : un firmware qui reçoit une page d'erreur HTML n'a aucun
 * moyen de savoir ce qui lui arrive. */
function glowscreen32ApiSend($_code, $_payload) {
    http_response_code($_code);
    header('Content-Type: application/json; charset=utf-8');
    /* La mise en page change quand l'utilisateur la change : rien ici ne doit
     * être servi depuis un cache intermédiaire. */
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die();
}

/*
 * Un échec, sous la forme unique que le contrat impose. Le détail va au
 * journal, jamais dans la réponse : ce qui sort d'ici est lu par une carte, et
 * pourrait être lu par n'importe qui d'autre sur le réseau.
 *
 * Le niveau est choisi par l'appelant, et c'est important : « unknown_device »
 * est l'état NORMAL d'une carte neuve qui n'a pas encore été déclarée, et qui
 * réinterroge toutes les dix secondes en affichant sa MAC. En faire une erreur
 * remplirait le journal pendant l'enrôlement, c'est-à-dire précisément au
 * moment où l'on a besoin de le lire.
 */
function glowscreen32ApiError($_error, $_code, $_detail = '', $_level = 'info') {
    log::add('glowscreen32', $_level, sprintf(
        __('API : %1$s (HTTP %2$s) depuis %3$s%4$s', __FILE__),
        $_error, $_code,
        isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?',
        ($_detail != '') ? ' — ' . $_detail : ''
    ));
    glowscreen32ApiSend($_code, array('ok' => false, 'error' => $_error));
}

/*
 * Le schéma annoncé par la carte, ramené à 1 ou 2.
 *
 * Un en-tête PERSONNALISÉ n'arrive pas dans $_SERVER sous son nom : Apache le
 * préfixe de HTTP_, met tout en majuscules et remplace les tirets par des
 * soulignés — d'où HTTP_X_GLOWSCREEN32_SCHEMA. Le repli par getallheaders()
 * couvre les configurations où cette réécriture n'a pas lieu ; la fonction
 * n'existe pas sous tous les SAPI, d'où le function_exists().
 *
 * Tout ce qui n'est pas un nombre vaut 1 : une carte qui annonce n'importe quoi
 * reçoit le schéma le plus ancien, c'est-à-dire celui qui a le plus de chances
 * de lui convenir. Et tout ce qui dépasse 2 est RAMENÉ à 2 : on ne répond
 * jamais au-dessus de ce qu'on sait servir.
 */
function glowscreen32ApiSchema() {
    $raw = '';
    if (isset($_SERVER['HTTP_X_GLOWSCREEN32_SCHEMA'])) {
        $raw = $_SERVER['HTTP_X_GLOWSCREEN32_SCHEMA'];
    } elseif (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'X-GLOWSCREEN32-SCHEMA') === 0) {
                $raw = $value;
                break;
            }
        }
    }
    $raw = trim((string) $raw);
    if ($raw === '' || !is_numeric($raw)) {
        return glowscreen32::SCHEMA_LEGACY;
    }
    return (((int) $raw) >= glowscreen32::SCHEMA_CURRENT)
        ? glowscreen32::SCHEMA_CURRENT
        : glowscreen32::SCHEMA_LEGACY;
}

try {
    $schema = glowscreen32ApiSchema();

    /*
     * La clé arrive par en-tête, et non dans la chaîne de requête : celle-ci est
     * journalisée en clair par Apache à chaque appel, et une carte interroge
     * toutes les trente secondes. Le repli par ?apikey= reste accepté — le
     * contrat le tolère, et il rend le point d'entrée essayable depuis un
     * navigateur ou un curl sans en-tête.
     */
    $apikey = isset($_SERVER['HTTP_X_GLOWSCREEN32_APIKEY'])
        ? $_SERVER['HTTP_X_GLOWSCREEN32_APIKEY']
        : init('apikey');

    if (!jeedom::apiAccess($apikey, 'glowscreen32')) {
        /* Les huit premiers caractères suffisent à reconnaître une clé sans
         * l'écrire en clair dans un journal que d'autres peuvent lire. */
        glowscreen32ApiError('bad_apikey', 401,
            ($apikey == '') ? __('aucune clé fournie', __FILE__)
                            : sprintf(__('clé commençant par %.8s…', __FILE__), $apikey),
            'warning');
    }

    $action = init('action');
    if (!in_array($action, array('layout', 'press', 'ping', 'firmware'), true)) {
        glowscreen32ApiError('bad_request', 400,
            ($action == '') ? __('action absente', __FILE__)
                            : __('action inconnue :', __FILE__) . ' ' . $action);
    }

    /*
     * Les quatre actions désignent un écran, et l'adresse est exigée avant
     * toute autre chose. Une MAC malformée est une erreur de paramètre, pas un écran
     * inconnu : le contrat distingue les deux, et c'est la seule façon pour le
     * firmware de savoir s'il doit corriger sa requête ou se faire déclarer.
     */
    $device = init('device');
    if ($device == '') {
        glowscreen32ApiError('bad_request', 400, __('paramètre device absent', __FILE__));
    }
    if (glowscreen32::normalizeMac($device) === '') {
        glowscreen32ApiError('bad_request', 400, __('adresse MAC malformée :', __FILE__) . ' ' . $device);
    }

    $eqLogic = glowscreen32::byMac($device);
    /*
     * Un écran désactivé est traité comme inconnu. C'est le seul code du
     * contrat qui convienne, et c'est le comportement attendu : désactiver un
     * équipement dans Jeedom doit couper ce qu'il commande, pas le laisser
     * déclencher des actions depuis un mur.
     *
     * Niveau debug : une carte non déclarée insiste toutes les dix secondes,
     * c'est le fonctionnement prévu de l'enrôlement et non un incident.
     */
    if (!is_object($eqLogic) || $eqLogic->getIsEnable() != 1) {
        glowscreen32ApiError('unknown_device', 404,
            is_object($eqLogic)
                ? __('écran désactivé :', __FILE__) . ' ' . glowscreen32::prettyMac($device)
                : __('écran non déclaré :', __FILE__) . ' ' . glowscreen32::prettyMac($device),
            'debug');
    }

    /*
     * La version exécutée par la carte, contrat v1.4.
     *
     * Elle est retenue dès qu'elle est présente, quelle que soit l'action : le
     * contrat ne l'exige que pour « firmware », mais une carte qui l'annonce
     * ailleurs renseigne le parc pour rien de plus cher — noteFirmware() n'écrit
     * que si la version a CHANGÉ, et ne fait donc rien la plupart du temps.
     *
     * C'est cette valeur qui s'affiche dans la colonne « Firmware » du tableau
     * du parc, et sans laquelle un déploiement progressif se piloterait à
     * l'aveugle : « quel écran est déjà passé en 1.4.0 ? » n'a pas d'autre
     * source que la carte elle-même.
     */
    $eqLogic->noteFirmware(init('fw'));

    /*
     * « states » a été ajouté au contrat en v1.2, et c'est lui qui fait tout le
     * travail de fraîcheur : sans ce tableau, une lampe allumée depuis
     * l'application ou un interrupteur mural laissait la pastille de l'écran
     * périmée jusqu'au prochain rechargement complet de la mise en page.
     *
     * Il est dans le MÊME ORDRE que les « buttons » du layout servi dans le
     * même schéma — c'est le schéma passé ici qui le garantit des deux côtés.
     */
    if ($action == 'ping') {
        $eqLogic->noteContact();
        glowscreen32ApiSend(200, $eqLogic->ping($schema));
    }

    if ($action == 'layout') {
        $eqLogic->noteContact();
        glowscreen32ApiSend(200, $eqLogic->layout($schema));
    }

    /* --- firmware --------------------------------------------------------- */

    /*
     * La mise à jour par le réseau, contrat v1.4.
     *
     * « fw » est EXIGÉE : elle sert à décider s'il y a quelque chose de plus
     * récent à proposer, et une carte qui ne la donne pas ne demande pas une
     * mise à jour, elle demande qu'on en choisisse une pour elle. Le contrat
     * classe le paramètre manquant en « bad_request », et c'est la seule
     * réponse qui dise au firmware que la faute est chez lui.
     *
     * Toute la décision est prise par otaDecision(), côté serveur, et journalisée
     * là-bas : deux verrous fermés par défaut, dont la carte ne sait rien. Une
     * carte bloquée reçoit exactement la réponse d'une carte à jour.
     */
    if ($action == 'firmware') {
        $fw = init('fw');
        if (trim((string) $fw) === '') {
            glowscreen32ApiError('bad_request', 400, __('paramètre fw absent : la carte doit annoncer la version qu\'elle exécute', __FILE__));
        }
        $eqLogic->noteContact();

        $decision = $eqLogic->otaDecision($fw);
        /* false, et non un tableau : le firmware est annoncé par la
         * configuration mais son fichier a disparu du dépôt. Le contrat réserve
         * « firmware_unavailable » à ce cas-là, et à lui seul. */
        if ($decision === false) {
            glowscreen32ApiError('firmware_unavailable', 404, sprintf(
                __('%s : le binaire annoncé est introuvable dans data/firmware/', __FILE__),
                $eqLogic->getHumanName()), 'error');
        }
        glowscreen32ApiSend(200, $decision);
    }

    /* --- press ------------------------------------------------------------ */

    $id = init('id');
    /*
     * Un paramètre absent ou non numérique est une requête MAL FORMÉE : le
     * firmware a un défaut, et le réessayer trois fois avec backoff n'y changera
     * rien. Un rang bien formé mais hors de la mise en page est autre chose —
     * une carte qui a gardé en cache une mise en page devenue plus courte — et
     * le contrat lui réserve « unknown_button », que press() rend par null.
     *
     * Le rang 0 est le PREMIER bouton, et non plus une valeur illégale comme
     * l'était l'identifiant de commande 0 jusqu'en v1.2.
     */
    if ($id === '' || !is_numeric($id)) {
        glowscreen32ApiError('bad_request', 400, __('paramètre id absent ou invalide', __FILE__));
    }

    /*
     * Le schéma est passé à press(), et ce n'est pas une précaution de style :
     * le rang reçu doit être résolu DANS L'APLATISSEMENT qui a servi le layout
     * à CETTE carte. Une carte de schéma 1 a reçu six boutons renumérotés 0 à
     * 5, sans les boutons « nav » ; son rang 2 ne désigne pas forcément le
     * bouton d'id global 2. Les confondre ferait jouer le bouton d'à côté.
     */
    $result = $eqLogic->press($id, $schema);
    if ($result === null) {
        glowscreen32ApiError('unknown_button', 404, sprintf(
            __('%1$s : aucun bouton de rang %2$s dans la mise en page', __FILE__),
            $eqLogic->getHumanName(), (int) $id));
    }

    /* L'appui est journalisé par press(), qui est le seul à savoir quelle
     * commande il a finalement choisie — l'information qui compte quand un
     * interrupteur part dans le mauvais sens. */
    glowscreen32ApiSend(200, $result);

} catch (Throwable $e) {
    /*
     * Throwable et non Exception : en PHP 8, une Error — méthode inexistante,
     * erreur de type — n'hérite pas d'Exception et produirait un HTTP 500 muet,
     * c'est-à-dire du HTML pour le firmware et rien du tout dans le journal.
     *
     * La cause va au journal du plugin ; la carte, elle, reçoit le code 400
     * générique du contrat, qui ne prévoit pas d'erreur serveur.
     */
    log::add('glowscreen32', 'error', __('API : erreur interne —', __FILE__) . ' ' . $e->getMessage());
    glowscreen32ApiSend(400, array('ok' => false, 'error' => 'bad_request'));
}
