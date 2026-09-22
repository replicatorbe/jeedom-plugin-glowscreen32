<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}

/* class_exists : une page de configuration ne doit jamais tomber en erreur
 * fatale. Un plugin dont la classe ne se charge pas laisserait sinon une
 * fenêtre blanche, sans même le moyen de le désactiver. */
$gsApi = class_exists('glowscreen32')
	? glowscreen32::apiInfo()
	: array('url' => '', 'apikey' => '', 'header' => 'X-GLOWSCREEN32-APIKEY');
$gsScreens = class_exists('glowscreen32') ? glowscreen32::overview() : array();
$gsOta = class_exists('glowscreen32')
	? glowscreen32::otaState()
	: array('enabled' => false, 'firmware' => null, 'dir' => '', 'writable' => false, 'screens' => array());
$gsFirmware = $gsOta['firmware'];
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-plug"></i> {{Point d'entrée des écrans}}</legend>
		<div class="form-group">
			<label class="col-md-3 control-label">{{URL}}</label>
			<div class="col-md-9">
				<span class="form-control-static" style="word-break:break-all;"><code><?php echo $gsApi['url']; ?></code></span>
				<span class="help-block" style="margin:0;">{{C'est l'adresse que le firmware appelle. Elle ne se règle pas ici : elle découle de l'accès interne configuré dans Réglages → Système → Configuration → Réseaux.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-3 control-label">{{Clé API}}</label>
			<div class="col-md-9">
				<span class="form-control-static" style="word-break:break-all;"><code><?php echo $gsApi['apikey']; ?></code></span>
				<span class="help-block" style="margin:0;">{{La clé API du plugin, la même pour tout le parc — le firmware est identique sur toutes les cartes, chacune se reconnaissant à sa seule adresse MAC. Elle se change dans Réglages → Système → Configuration → onglet API, ligne GlowScreen32 ; il faut alors la reporter dans le firmware de chaque carte, qui n'a aucun moyen de la redemander.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-3 control-label">{{En-tête}}</label>
			<div class="col-md-9">
				<span class="form-control-static"><code><?php echo $gsApi['header']; ?>: &lt;clé&gt;</code></span>
				<span class="help-block" style="margin:0;">{{C'est la façon normale de présenter la clé. Le repli <code>?apikey=</code> en paramètre d'URL reste accepté pour essayer le point d'entrée depuis un navigateur, mais il inscrit la clé en clair dans les journaux d'Apache à chaque appel — et une carte appelle toutes les trente secondes.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-tablet-alt"></i> {{Écrans déclarés}}</legend>
		<?php if (count($gsScreens) == 0) { ?>
			<div class="alert alert-info" style="margin:0;">{{Aucun écran déclaré. Une carte flashée mais pas encore déclarée affiche sa propre adresse MAC : il suffit de la recopier dans un nouvel équipement du plugin pour l'enrôler.}}</div>
		<?php } else { ?>
			<div class="table-responsive">
				<table class="table table-bordered table-condensed">
					<thead>
						<tr>
							<th>{{Écran}}</th>
							<th style="width:170px;">{{Adresse MAC}}</th>
							<th style="width:230px;">{{Dernier contact}}</th>
							<th style="width:120px;">{{Firmware}}</th>
							<th style="width:150px;">{{Verrou OTA}}</th>
							<th style="width:80px;">{{Boutons}}</th>
							<th style="width:80px;">{{Version}}</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($gsScreens as $gsScreen) { ?>
							<tr<?php echo ($gsScreen['enable'] == 1) ? '' : ' class="disableCard"'; ?>>
								<td>
									<?php echo $gsScreen['name']; ?>
									<?php echo ($gsScreen['enable'] == 1) ? '' : ' <span class="label label-default">{{désactivé}}</span>'; ?>
								</td>
								<td><code><?php echo ($gsScreen['mac'] != '') ? $gsScreen['mac'] : '—'; ?></code></td>
								<td>
									<?php if ($gsScreen['human'] == '') { ?>
										<span class="label label-default">{{jamais vu}}</span>
									<?php } else { ?>
										<?php echo $gsScreen['human']; ?>
										<?php if (!$gsScreen['online']) { ?>
											<span class="label label-warning">{{hors ligne}}</span>
										<?php } ?>
									<?php } ?>
								</td>
								<td>
									<?php if ($gsScreen['fw'] == '') { ?>
										<span class="label label-default">{{inconnu}}</span>
									<?php } else { ?>
										<code><?php echo $gsScreen['fw']; ?></code>
									<?php } ?>
								</td>
								<td>
									<?php if ($gsScreen['otaOpen']) { ?>
										<span class="label label-success">{{ouvert}}</span>
									<?php } elseif (!$gsScreen['ota']) { ?>
										<span class="label label-default">{{fermé (écran)}}</span>
									<?php } else { ?>
										<span class="label label-default">{{fermé (global)}}</span>
									<?php } ?>
								</td>
								<td><?php echo $gsScreen['buttons']; ?></td>
								<td><?php echo $gsScreen['version']; ?></td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
			<span class="help-block" style="margin:0;">{{Le dernier contact est horodaté à chaque appel reçu de la carte, « layout » comme « ping », et arrondi à la minute : une carte interroge toutes les trente secondes, et horodater chaque appel ferait une écriture en base pour une information dont personne ne lit la seconde. « Hors ligne » s'affiche au-delà de trois intervalles de rafraîchissement sans nouvelle. Un écran sans adresse MAC n'est joignable par aucune carte.}}</span>
		<?php } ?>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-microchip"></i> {{Mise à jour par le réseau}}</legend>
		<div class="form-group">
			<label class="col-md-3 control-label">{{Verrou global}}</label>
			<div class="col-md-9">
				<span class="form-control-static">
					<?php if ($gsOta['enabled']) { ?>
						<span class="label label-success">{{ouvert}}</span>
					<?php } else { ?>
						<span class="label label-default">{{fermé}}</span>
					<?php } ?>
				</span>
				<span class="help-block" style="margin:0;">{{Il se règle sur la page du plugin, dans le cadre « Firmware », à côté du dépôt du binaire et du tableau du parc — c'est là qu'on pilote un déploiement, et un interrupteur d'arrêt d'urgence doit être sous la main de celui qui regarde les écrans revenir en ligne. Il est fermé par défaut.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-3 control-label">{{Firmware déposé}}</label>
			<div class="col-md-9">
				<span class="form-control-static">
					<?php if ($gsFirmware === null) { ?>
						{{Aucun}}
					<?php } else { ?>
						<b><?php echo $gsFirmware['version']; ?></b> — <?php echo $gsFirmware['human']; ?>
						<?php echo $gsFirmware['exists'] ? '' : ' <span class="label label-danger">{{fichier introuvable}}</span>'; ?>
					<?php } ?>
				</span>
				<span class="help-block" style="margin:0;">{{Un écran ne reçoit une mise à jour que si le verrou global ET son propre verrou sont ouverts. Les deux sont fermés par défaut, et une carte bloquée reçoit exactement la réponse d'une carte à jour : elle n'a aucun moyen de passer outre.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-shield-alt"></i> {{Ce que la clé ouvre}}</legend>
		<div class="alert alert-info" style="margin:0;">
			{{Une carte qui présente la clé ne peut déclencher que les boutons configurés sur l'écran portant son adresse MAC. Elle ne peut ni lire l'installation, ni commander autre chose : un identifiant qui n'est pas au tableau de cet écran-là est refusé. Ce point d'entrée n'a pas vocation à être exposé sur Internet.}}
		</div>
	</fieldset>
</form>
