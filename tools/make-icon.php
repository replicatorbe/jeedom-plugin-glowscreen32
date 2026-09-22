<?php
/* Fabrique l'icône du plugin.
 *
 *   php tools/make-icon.php
 *
 * L'icône est dessinée ici plutôt que déposée en binaire opaque : quelques
 * rectangles, qu'on peut relire et refaire. Le dessin est fait en 1024 puis
 * réduit en 256, ce qui donne les bords lissés que GD ne produit pas sur un
 * remplissage direct.
 *
 * Le sujet : l'écran et ce qu'il porte. Un cadre sombre, et la grille 3x2 du
 * contrat — six tuiles, dont celles du haut sont franches et celles du bas
 * atténuées, comme des boutons dont certains sont allumés. C'est tout ce que le
 * plugin fait, et c'est lisible à 24 pixels, où il ne reste qu'un écran et six
 * taches d'inégale densité.
 */

const TAILLE = 256;
const ECHELLE = 4;

$grand = imagecreatetruecolor(TAILLE * ECHELLE, TAILLE * ECHELLE);
imagesavealpha($grand, true);
imagealphablending($grand, false);
imagefill($grand, 0, 0, imagecolorallocatealpha($grand, 0, 0, 0, 127));
imagealphablending($grand, true);

$cadre  = imagecolorallocate($grand, 0x26, 0x32, 0x38);
$fond   = imagecolorallocate($grand, 0x10, 0x14, 0x16);
$allume = imagecolorallocate($grand, 0x2D, 0x7F, 0xF9);
/* Les tuiles du bas sont le même bleu, presque éteint : elles se lisent comme
 * les mêmes boutons et non comme une seconde couleur, ce qui serait le
 * contresens du dessin. Le mélange est calculé ici — ce bleu posé à 30 % sur le
 * fond de l'écran — plutôt que confié au canal alpha de GD, dont les couches
 * translucides laissent une couture visible là où elles se recouvrent. */
$eteint = imagecolorallocate($grand, 0x19, 0x2C, 0x4B);

$e = function ($_valeur) { return (int) round($_valeur * ECHELLE); };

/* Le cadre de l'écran, et sa dalle. Un écran 320x240 est nettement plus large
 * que haut : l'icône garde ce rapport, c'est ce qui le fait reconnaître comme
 * un écran et non comme une tuile de dashboard. */
imagefilledrectangle($grand, $e(20), $e(48), $e(236), $e(208), $cadre);
imagefilledrectangle($grand, $e(32), $e(60), $e(224), $e(196), $fond);

/* La grille 3x2, aux mêmes proportions que celle du firmware. */
$largeur = 56;
$hauteur = 56;
$gouttiere = 8;
$x0 = 40;
$y0 = 68;

for ($ligne = 0; $ligne < 2; $ligne++) {
    for ($colonne = 0; $colonne < 3; $colonne++) {
        $x = $x0 + $colonne * ($largeur + $gouttiere);
        $y = $y0 + $ligne * ($hauteur + $gouttiere);
        imagefilledrectangle($grand, $e($x), $e($y), $e($x + $largeur), $e($y + $hauteur),
            ($ligne === 0) ? $allume : $eteint);
    }
}

$icone = imagecreatetruecolor(TAILLE, TAILLE);
imagesavealpha($icone, true);
imagealphablending($icone, false);
imagefill($icone, 0, 0, imagecolorallocatealpha($icone, 0, 0, 0, 127));
imagecopyresampled($icone, $grand, 0, 0, 0, 0, TAILLE, TAILLE, TAILLE * ECHELLE, TAILLE * ECHELLE);

$cible = __DIR__ . '/../plugin_info/glowscreen32_icon.png';
imagepng($icone, $cible);
echo "Écrit : $cible\n";
