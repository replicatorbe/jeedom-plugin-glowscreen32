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
 * d'API v1.1 (docs/fr_FR/index.md) :
 *
 *   GET ?action=layout&device=246f28123456     → la mise en page
 *   GET ?action=press&device=…&id=12           → jouer un bouton
 *   GET ?action=ping&device=…                  → le compteur de version
 *
 *   X-GLOWSCREEN32-APIKEY: <clé>               → l'authentification normale
 *
 * Erreurs : bad_apikey 401, unknown_device 404, unknown_button 404,
 * bad_request 400. Le corps est toujours du JSON, succès comme échec.
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

try {
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
    if (!in_array($action, array('layout', 'press', 'ping'), true)) {
        glowscreen32ApiError('bad_request', 400,
            ($action == '') ? __('action absente', __FILE__)
                            : __('action inconnue :', __FILE__) . ' ' . $action);
    }

    /*
     * Les trois actions désignent un écran, et l'adresse est exigée avant toute
     * autre chose. Une MAC malformée est une erreur de paramètre, pas un écran
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

    if ($action == 'ping') {
        $eqLogic->noteContact();
        glowscreen32ApiSend(200, array(
            'ok'      => true,
            'version' => $eqLogic->version(),
            'time'    => time(),
            /* Ajouté au contrat en v1.2. Même ordre que les « buttons » du
             * layout — c'est activeButtons() qui le garantit des deux côtés.
             * Sans ce tableau, une lampe allumée depuis l'application ou un
             * interrupteur mural laissait la pastille de l'écran périmée
             * jusqu'au prochain rechargement complet de la mise en page. */
            'states'  => $eqLogic->states(),
        ));
    }

    if ($action == 'layout') {
        $eqLogic->noteContact();
        glowscreen32ApiSend(200, $eqLogic->layout());
    }

    /* --- press ------------------------------------------------------------ */

    $id = init('id');
    /* « 0 » n'est l'identifiant d'aucune commande, et init() rend une chaîne
     * vide sur un paramètre absent : les deux sont des requêtes mal formées et
     * non des boutons inconnus. */
    if ($id === '' || !is_numeric($id) || (int) $id === 0) {
        glowscreen32ApiError('bad_request', 400, __('paramètre id absent ou invalide', __FILE__));
    }

    $result = $eqLogic->press($id);
    if ($result === null) {
        glowscreen32ApiError('unknown_button', 404, sprintf(
            __('%1$s : aucun bouton d\'identifiant %2$s', __FILE__), $eqLogic->getHumanName(), (int) $id));
    }

    log::add('glowscreen32', 'info', sprintf(
        __('%1$s : appui sur le bouton %2$s.', __FILE__), $eqLogic->getHumanName(), (int) $id));
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
