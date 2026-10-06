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
 * d'API v3.0 (docs/api-contract.md du dépôt firmware, docs/fr_FR/index.md) :
 *
 *   GET ?action=layout&device=246f28123456     → la mise en page
 *   GET ?action=press&device=…&id=0            → jouer le bouton de RANG 0
 *   GET ?action=ping&device=…                  → version, états, bandeau
 *        [&rssi=-64]                           → niveau Wi-Fi (v2.1)
 *        [&up=…&rst=…&heap=…&blk=…&ip=…&ssid=…] → diagnostics (v2.2)
 *        [&wait=25&rev=a41f09c2]               → attente longue (v2.2, schéma ≥ 2)
 *   GET ?action=firmware&device=…&fw=1.3.0     → y a-t-il une mise à jour ?
 *   GET ?action=fwfile&token=<jeton>           → le binaire (v2.2, sans clé)
 *
 * v2.2, en schéma 2 ou 3 : « features » et « rev » sur layout et ping, « cmd »
 * (commande à distance, livrée au plus une fois, seulement si la requête porte
 * « rev ») sur ping. Le schéma 1 est servi octet pour octet comme avant.
 *
 * v3.0, schéma 3 : tuiles « view » (value + tone) dans le layout, « values »
 * dans le ping, « ui.readonly ». Une carte de schéma 2 reçoit le schéma 2 SANS
 * aucune tuile « view », id recalculés ; « press » résout le rang dans
 * l'aplatissement du schéma de la requête. Un « press » sur un écran en
 * lecture seule est refusé AVANT toute résolution : 403 read_only.
 *
 * ⚠ RÈGLE NORMATIVE v2.2 : AUCUN chemin de ce fichier n'enregistre l'eqLogic.
 * Contact, firmware, Wi-Fi, diagnostics, péremption du bandeau : commandes
 * d'information ou cache. Une requête retenue 25 s qui réenregistrerait
 * l'écran tel que chargé à son arrivée écraserait toute configuration
 * enregistrée entre-temps.
 *
 * Depuis la v1.3, « id » est le RANG du bouton dans la mise en page et non un
 * identifiant de commande Jeedom : la carte ne désigne plus ce qui doit
 * s'exécuter, elle désigne le bouton qu'on a touché. C'est le plugin qui en
 * déduit la commande, et son sens.
 *
 *   X-GLOWSCREEN32-APIKEY: <clé>               → l'authentification normale
 *   X-GLOWSCREEN32-SCHEMA: 3                   → ce que la carte sait lire
 *
 * LA NÉGOCIATION DE SCHÉMA EST LA PIÈCE QUI REND TOUT LE RESTE POSSIBLE.
 *
 * Absent, vide ou « 1 » → le schéma 1 à l'identique, octet pour octet. « 2 » →
 * le schéma 2, sans tuile « view ». « 3 » ou plus → le schéma 3. Le plugin ne
 * répond JAMAIS au-dessus de ce qui est annoncé.
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
 * Erreurs : bad_apikey 401, read_only 403 (v3.0), unknown_device 404,
 * unknown_button 404, firmware_unavailable 404, bad_request 400. Le corps est toujours du JSON,
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
    /* v3.1 : JSON_INVALID_UTF8_SUBSTITUTE — un octet invalide venu d'une
     * commande ne doit plus faire rendre « false » (corps vide) à json_encode. */
    $body = json_encode($_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($body === false) {
        $_code = 400;
        $body  = '{"ok":false,"error":"bad_request"}';
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($_code);
    header('Content-Type: application/json; charset=utf-8');
    /* La mise en page change quand l'utilisateur la change : rien ici ne doit
     * être servi depuis un cache intermédiaire. */
    header('Cache-Control: no-store, no-cache, must-revalidate');
    /* v3.1 : Content-Length explicite, jamais de « chunked ». */
    header('Content-Length: ' . strlen($body));
    echo $body;
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
    /* v3.1 : le détail cite des valeurs reçues du réseau — sans caractère de
     * contrôle, sans quoi une requête pourrait écrire de fausses lignes. */
    log::add('glowscreen32', $_level, sprintf(
        __('API : %1$s (HTTP %2$s) depuis %3$s%4$s', __FILE__),
        $_error, $_code,
        isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?',
        ($_detail != '') ? ' — ' . glowscreen32::logSafe($_detail, 300) : ''
    ));
    glowscreen32ApiSend($_code, array('ok' => false, 'error' => $_error));
}

/*
 * Le schéma annoncé par la carte, ramené à 1, 2 ou 3.
 *
 * Un en-tête PERSONNALISÉ n'arrive pas dans $_SERVER sous son nom : Apache le
 * préfixe de HTTP_, met tout en majuscules et remplace les tirets par des
 * soulignés — d'où HTTP_X_GLOWSCREEN32_SCHEMA. Le repli par getallheaders()
 * couvre les configurations où cette réécriture n'a pas lieu ; la fonction
 * n'existe pas sous tous les SAPI, d'où le function_exists().
 *
 * Tout ce qui n'est pas un nombre vaut 1 : une carte qui annonce n'importe quoi
 * reçoit le schéma le plus ancien, c'est-à-dire celui qui a le plus de chances
 * de lui convenir. 2 → schéma 2 (sans tuile « view »), 3 ou plus → schéma 3 :
 * on ne répond jamais au-dessus de ce qu'on sait servir, ni de ce qui est
 * annoncé.
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
    return glowscreen32::normalizeSchema((int) $raw);
}

try {
    /*
     * --- fwfile : le binaire d'une mise à jour, contrat v2.2 ---------------
     *
     * Traité AVANT la clé API, et c'est voulu : le firmware en service
     * télécharge l'URL reçue de « action=firmware » SANS aucun en-tête. Le jeton
     * aléatoire de l'URL tient lieu d'autorisation — émis seulement quand les
     * deux verrous OTA sont ouverts, valable quinze minutes, et pour le seul
     * binaire déposé à ce moment-là (glowscreen32::firmwareForToken).
     *
     * Pourquoi plus de fichier servi par Apache : voir firmwareUrl(). Le
     * .htaccess racine de Jeedom renvoie 403 sur tout .bin rangé sous data/.
     *
     * ⚠️ Content-Length est OBLIGATOIRE : la carte compare la taille annoncée
     * par HTTP à celle de la réponse « firmware » avant d'écrire un octet.
     */
    if (init('action') === 'fwfile') {
        /* v3.1 : jeton lié au sha256 du binaire, verrous OTA relus au
         * téléchargement. Un mauvais jeton est journalisé en debug : n'importe
         * qui sur le réseau peut en fabriquer, ce n'est pas un incident. Un
         * OTA refermé depuis l'émission, lui, l'est en info. */
        $why = '';
        $firmware = glowscreen32::firmwareForToken(init('token'), $why);
        if ($firmware === null) {
            glowscreen32ApiError('firmware_unavailable', 404, ($why === 'locked')
                ? __('OTA refermé (verrou global ou de l\'écran) depuis l\'émission du jeton', __FILE__)
                : __('jeton de téléchargement inconnu, expiré, ou binaire remplacé depuis', __FILE__) . ' (' . $why . ')',
                ($why === 'locked') ? 'info' : 'debug');
        }
        log::add('glowscreen32', 'info', sprintf(
            __('OTA : téléchargement de %1$s (%2$s octets) par %3$s', __FILE__),
            $firmware['file'], filesize($firmware['path']),
            isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?'));
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(200);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($firmware['path']));
        header('Cache-Control: no-store');
        readfile($firmware['path']);
        die();
    }

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

    /* v3.1 : ?apikey[]=… arrive en TABLEAU — refusé net (401), sans le
     * passer au coeur. */
    if (!is_string($apikey) || !jeedom::apiAccess($apikey, 'glowscreen32')) {
        $apikey = is_string($apikey) ? $apikey : '';
        /* Les huit premiers caractères suffisent à reconnaître une clé sans
         * l'écrire en clair dans un journal que d'autres peuvent lire. */
        glowscreen32ApiError('bad_apikey', 401,
            ($apikey == '') ? __('aucune clé fournie', __FILE__)
                            : sprintf(__('clé commençant par %.8s…', __FILE__), $apikey),
            'warning');
    }

    /* v3.1 : un paramètre reçu en TABLEAU (?id[]=…) est une requête mal
     * formée, pas une valeur à convertir. */
    foreach (array('action', 'device', 'id', 'fw', 'rev', 'wait', 'rssi', 'up', 'rst', 'heap', 'blk', 'ip', 'ssid') as $param) {
        if (is_array(init($param))) {
            glowscreen32ApiError('bad_request', 400, sprintf(__('paramètre %s reçu sous forme de tableau', __FILE__), $param));
        }
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
        /*
         * Le niveau Wi-Fi annoncé par la carte — contrat v2.1, OPTIONNEL.
         *
         * Porté par « ping » et par lui seul : c'est le seul appel périodique,
         * donc le seul qui donne une mesure suivie dans le temps sans rien
         * coûter de plus.
         *
         * Une valeur absente, non numérique ou hors bornes est ignorée SANS
         * ERREUR par noteRssi(). Un diagnostic mal formé ne doit jamais coûter
         * sa liaison à un écran — et un plugin qui refuserait le ping pour
         * cela couperait précisément l'écran qu'il cherche à diagnostiquer.
         */
        $eqLogic->noteRssi(init('rssi'));
        /* Contrat v2.2 : les diagnostics, même règle que rssi — ignorés sans
         * erreur s'ils sont absents ou mal formés. */
        $eqLogic->noteDiagnostics(array(
            'up' => init('up'), 'rst' => init('rst'), 'heap' => init('heap'),
            'blk' => init('blk'), 'ip' => init('ip'), 'ssid' => init('ssid'),
        ));
        /* Noté à l'ARRIVÉE, pas à la sortie : une requête retenue 25 s est un
         * contact vieux de 25 s quand elle répond. */
        $eqLogic->noteContact();

        if ($schema >= glowscreen32::SCHEMA_V2) {
            /*
             * L'attente longue — contrat v2.2, schémas 2 ET 3.
             *
             * Réponse IMMÉDIATE si « wait » est absent, nul ou invalide, si
             * « rev » est absente ou n'est plus la courante, ou si une commande
             * attend en file. Sinon la requête est RETENUE jusqu'à ce que
             * « rev » change ou que « wait » secondes s'écoulent, puis la
             * réponse est calculée au moment où elle part.
             *
             * Le jeton de réveil est lu AVANT le calcul de la rev courante : un
             * changement survenu entre les deux est vu au premier tour de la
             * boucle au lieu d'attendre le recalcul périodique.
             */
            $wait = init('wait');
            $wait = (is_numeric($wait) && (int) $wait > 0)
                ? min((int) $wait, glowscreen32::LONGPOLL_MAX) : 0;
            $rev  = trim((string) init('rev'));
            if ($wait > 0 && $rev !== '' && strlen($rev) <= 16) {
                $wake = glowscreen32::readToken('wake', $eqLogic->getId());
                if ($eqLogic->commandCount() == 0 && $rev === $eqLogic->currentRev($schema)) {
                    $eqLogic = glowscreen32::holdPing($eqLogic, $rev, $wait, $wake, $schema);
                    if (!is_object($eqLogic)) {
                        glowscreen32ApiError('unknown_device', 404,
                            __('écran supprimé ou désactivé pendant l\'attente :', __FILE__) . ' ' . glowscreen32::prettyMac($device),
                            'debug');
                    }
                }
            }
        }
        /*
         * Une commande à distance n'est livrée qu'à une carte qui renvoie
         * « rev » — c'est-à-dire une carte v2.2, la seule qui sache lire
         * « cmd ». Une carte 2.0/2.1 en schéma 2 ignorerait le champ : la
         * livraison étant « au plus une fois », la commande serait retirée de la
         * file et perdue sans avoir rien fait. Elle reste donc en file pour
         * cette carte, et y expire au bout de sa durée de vie.
         */
        $deliver = ($schema >= glowscreen32::SCHEMA_V2) && trim((string) init('rev')) !== '';
        /*
         * v3.1 : une requête retenue qui a été LIBÉRÉE par une plus récente ne
         * retire aucune commande — elle répond, mais c'est la suivante, celle
         * que la carte attend vraiment, qui livrera. De même si son jeton n'est
         * plus le dernier au moment de répondre (requête fantôme).
         */
        if (glowscreen32::lastHoldWhy() === 'released' || glowscreen32::holdSuperseded($eqLogic->getId())) {
            $deliver = false;
        }
        glowscreen32ApiSend(200, $eqLogic->ping($schema, $deliver));
    }

    if ($action == 'layout') {
        $eqLogic->noteContact();
        $answer = $eqLogic->layout($schema);
        /* v3.1 : l'empreinte de chaque rang SERVI, par écran et par schéma —
         * press() refusera un rang dont le bouton a changé depuis. */
        $eqLogic->rememberServedLayout($schema);
        glowscreen32ApiSend(200, $answer);
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
        /* v3.1 : une version illisible (rien ne survit au nettoyage) est une
         * requête mal formée, pas une version « vide » à comparer. */
        if (glowscreen32::sanitizeVersion($fw) === '') {
            glowscreen32ApiError('bad_request', 400, __('paramètre fw illisible :', __FILE__) . ' ' . $fw);
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

    /*
     * Écran en LECTURE SEULE — contrat v3.0. Refusé ICI, avant même de lire
     * le rang : aucune résolution de bouton, aucune commande chargée, rien
     * d'exécuté. Quel que soit le schéma : la carte de schéma 3 n'envoie pas
     * de « press » sur un tel écran, et le plugin qui refuse est la GARANTIE
     * — un firmware défectueux ou une clé dérobée ne doivent pas ouvrir le
     * portail depuis l'écran du garage.
     */
    if ($eqLogic->readOnly()) {
        glowscreen32ApiError('read_only', 403, sprintf(
            __('%1$s : appui refusé (rang %2$s), l\'écran est en lecture seule', __FILE__),
            $eqLogic->getHumanName(), glowscreen32::logSafe(init('id'), 12)), 'warning');
    }

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
    /* v3.1 : chiffres seulement — « 1e1 », « 0x2 », « 1.5 » ou « -1 » ne
     * sont pas des rangs. */
    if (!is_string($id) || $id === '' || !ctype_digit($id) || strlen($id) > 4) {
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
    log::add('glowscreen32', 'error', __('API : erreur interne —', __FILE__) . ' ' . glowscreen32::logSafe($e->getMessage(), 500));
    glowscreen32ApiSend(400, array('ok' => false, 'error' => 'bad_request'));
}
