<?php
/*
 * Publie un firmware depuis la ligne de commande, avec EXACTEMENT les contrôles
 * du dépôt par la page du plugin (glowscreen32::publishFirmware) : octet 0xE9,
 * taille, marqueur GLOWSCREEN32-FW:, SHA-256 calculé sur le fichier écrit.
 *
 *   sudo -u www-data php tools/publish-firmware.php /chemin/firmware.bin
 *
 * À lancer sous www-data, pour que le binaire déposé dans data/firmware/ lui
 * appartienne comme s'il était arrivé par la page. Les verrous OTA ne sont PAS
 * touchés : publier n'est pas autoriser.
 *
 * Outil de développement : tools/ n'est pas déployé.
 */
if (php_sapi_name() !== 'cli' || $argc < 2) {
    fwrite(STDERR, "usage : php publish-firmware.php <firmware.bin>\n");
    exit(1);
}
require_once '/var/www/html/core/php/core.inc.php';
require_once '/var/www/html/plugins/glowscreen32/core/class/glowscreen32.class.php';

try {
    $fw = glowscreen32::publishFirmware($argv[1], basename($argv[1]));
    $fw = glowscreen32::firmware();
    printf("publié : %s  version %s  %d octets  sha256 %s\n",
        $fw['file'], $fw['version'], $fw['size'], $fw['sha256']);
    $state = glowscreen32::otaState();
    echo 'verrous : ', json_encode($state, JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'refusé : ' . $e->getMessage() . "\n");
    exit(2);
}
