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
 * Le contrôleur de la page de configuration. À ne pas confondre avec
 * core/api/glowscreen32.api.php, qui est le point d'entrée des cartes : ici,
 * l'appelant est un administrateur connecté, et rien n'y est accessible sans
 * session.
 */

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /* eqLogic::byId() charge n'importe quel équipement et le rend dans la classe
     * de SON type : sans ce contrôle, un identifiant étranger ferait agir le
     * plugin sur l'équipement d'un autre. */
    $getScreen = function ($_id) {
        $eqLogic = glowscreen32::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'glowscreen32') {
            throw new Exception(__('Écran introuvable', __FILE__));
        }
        return $eqLogic;
    };

    /*
     * Ce que la carte recevra, tel quel. C'est la seule façon de vérifier une
     * configuration sans avoir l'écran sous la main : un bouton dont la
     * commande a été supprimée disparaît de la mise en page sans que le
     * formulaire, lui, ait changé d'apparence.
     */
    if (init('action') == 'preview') {
        $eqLogic = $getScreen(init('id'));
        ajax::success(array(
            'layout' => $eqLogic->layout(),
            'url'    => glowscreen32::apiInfo()['url'],
        ));
    }

    /* ------------------------------------------------------------------ OTA */

    /*
     * L'état complet de l'OTA : le verrou global, le firmware déposé, et le
     * parc avec la version de chaque écran. Un seul appel, pour que la page
     * n'ait jamais à recoller deux moitiés d'information prises à deux
     * instants différents.
     */
    if (init('action') == 'otastate') {
        ajax::success(glowscreen32::otaState());
    }

    /*
     * Le verrou GLOBAL. C'est l'interrupteur qui arrête net la propagation
     * d'un firmware défectueux : il doit pouvoir être coupé en un geste, sans
     * rouvrir chaque équipement, et il est journalisé par enableOta().
     */
    if (init('action') == 'otaglobal') {
        glowscreen32::enableOta(init('enabled') == 1);
        ajax::success(glowscreen32::otaState());
    }

    /*
     * Le dépôt d'un firmware.
     *
     * Tout est vérifié par publishFirmware() — octet magique, taille, version —
     * avant que le fichier n'arrive dans data/firmware/ : ce qui est déposé ici
     * finira écrit dans la flash d'une carte accrochée à un mur.
     *
     * Le dépôt ne déverrouille RIEN : un firmware déposé alors que les verrous
     * sont fermés ne part nulle part, et c'est la manière normale de procéder —
     * on dépose, puis on ouvre un écran témoin.
     */
    if (init('action') == 'firmwareupload') {
        if (!isset($_FILES['firmware'])) {
            throw new Exception(__('Aucun fichier reçu.', __FILE__));
        }
        if ($_FILES['firmware']['error'] != UPLOAD_ERR_OK) {
            /* Le code d'erreur de PHP plutôt qu'un message vague : « le fichier
             * dépasse upload_max_filesize » et « le dossier temporaire est
             * absent » ne se corrigent pas au même endroit. */
            throw new Exception(sprintf(
                __('Le téléversement a échoué (code PHP %s).', __FILE__),
                $_FILES['firmware']['error']));
        }
        glowscreen32::publishFirmware(
            $_FILES['firmware']['tmp_name'],
            $_FILES['firmware']['name'],
            init('version')
        );
        ajax::success(glowscreen32::otaState());
    }

    /* Retirer le firmware déposé : plus aucun écran ne se voit rien proposer,
     * verrous ouverts ou non. */
    if (init('action') == 'firmwareremove') {
        glowscreen32::removeFirmware();
        ajax::success(glowscreen32::otaState());
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

} catch (Throwable $e) {
    // Throwable et non Exception : en PHP 8 une Error (méthode inexistante,
    // erreur de type) n'hérite pas d'Exception et donnerait un HTTP 500 muet.
    ajax::error(displayException($e), $e->getCode());
}
