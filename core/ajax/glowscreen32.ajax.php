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

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

} catch (Throwable $e) {
    // Throwable et non Exception : en PHP 8 une Error (méthode inexistante,
    // erreur de type) n'hérite pas d'Exception et donnerait un HTTP 500 muet.
    ajax::error(displayException($e), $e->getCode());
}
