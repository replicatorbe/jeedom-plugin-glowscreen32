<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('glowscreen32');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());

/* Les scénarios sont transmis au JS plutôt que relus par une requête : la liste
 * est courte, elle ne change pas pendant qu'on remplit le formulaire, et six
 * listes déroulantes sont construites d'un coup au moment d'afficher un écran. */
$gsScenarios = array();
foreach (scenario::all() as $gsScenario) {
	$gsScenarios[] = array(
		'id'   => $gsScenario->getId(),
		'name' => $gsScenario->getHumanName(),
	);
}
sendVarToJS('glowscreen32Scenarios', $gsScenarios);
sendVarToJS('glowscreen32Api', glowscreen32::apiInfo());

/* Le vocabulaire d'icônes du contrat v2.0, transmis depuis la CONSTANTE de la
   classe : il est fermé, et la seule façon qu'il ne dérive pas est qu'il n'ait
   qu'une source. Le jour où le firmware en apprend une nouvelle, on l'ajoute
   dans glowscreen32::ICONS et la liste déroulante suit. */
sendVarToJS('glowscreen32Icons', glowscreen32::ICONS);
/* Les alias, transmis eux aussi depuis la classe : la liste déroulante doit
   résoudre « fire » vers « heat » comme le fera le schéma 2, sinon ouvrir le
   formulaire suffirait à faire perdre son icône à un écran en service. */
sendVarToJS('glowscreen32IconAliases', glowscreen32::ICON_ALIASES);
sendVarToJS('glowscreen32Limits', array(
	'buttons'     => glowscreen32::MAX_BUTTONS,
	'pages'       => glowscreen32::MAX_PAGES,
	'perPage'     => glowscreen32::MAX_BUTTONS_PER_PAGE,
	'legacy'      => glowscreen32::LEGACY_MAX_BUTTONS,
	'defaultCols' => glowscreen32::DEFAULT_COLS,
	'defaultRows' => glowscreen32::DEFAULT_ROWS,
));

/* L'état de l'OTA : le verrou global, le firmware déposé, le parc. Une seule
   lecture, réutilisée par le tableau du parc et par le cadre « Firmware ». */
$gsOta = glowscreen32::otaState();
$gsFirmware = $gsOta['firmware'];
?>

<style>
	/* Aucune couleur en dur : la page doit rester lisible sur le thème clair
	   comme sur le thème sombre. Les seules couleurs décidées ici sont celles
	   des boutons de l'écran, et ce sont celles de l'utilisateur. */
	.glowscreen32Button {
		border: 1px solid var(--btnEq-default-color);
		border-radius: var(--border-radius);
		padding: 10px;
		margin-bottom: 10px;
	}

	.glowscreen32ButtonRank {
		font-weight: 600;
		opacity: .7;
	}

	/* L'aperçu de l'écran, à l'échelle, pour qu'on voie ce que la carte
	   affichera avant d'aller le vérifier sur le mur.

	   La grille n'est PLUS figée en 3×2 : elle suit le réglage de l'onglet
	   « Écran », par deux variables CSS que le JS repose à chaque rendu. Une
	   grille d'aperçu qui ne correspond pas à celle de la carte est pire que
	   pas d'aperçu du tout — elle fait placer les boutons au mauvais endroit
	   en toute confiance. */
	.glowscreen32Grid {
		display: grid;
		grid-template-columns: repeat(var(--gs-cols, 3), 1fr);
		grid-template-rows: repeat(var(--gs-rows, 3), 1fr);
		gap: 6px;
		width: 320px;
		height: 240px;
		padding: 6px;
		border: 2px solid var(--btnEq-default-color);
		border-radius: var(--border-radius);
		background: #101010;
	}

	.glowscreen32Tile {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		border-radius: 6px;
		/* Blanc écrit en dur, comme la carte : la tuile porte la couleur choisie
		   par l'utilisateur, qui ne suit aucun thème. Le fond de l'aperçu est
		   noir pour la même raison — c'est celui de l'écran. */
		color: #fff;
		font-size: 12px;
		text-align: center;
		padding: 4px;
		overflow: hidden;
	}

	.glowscreen32TileEmpty {
		border: 1px dashed rgba(255, 255, 255, .25);
		color: rgba(255, 255, 255, .35);
	}

	.glowscreen32TileIcon {
		font-size: 11px;
		opacity: .75;
	}

	/* Le rang du contrat v1.3, en petit sous le libellé : c'est ce que la carte
	   renvoie à « press », et le seul moyen d'essayer un bouton au curl sans
	   compter les emplacements à la main. */
	.glowscreen32TileRank {
		font-size: 9px;
		opacity: .55;
		font-family: monospace;
	}

	/* La MAC sous le nom de la vignette : discrète, mais toujours là. Avec
	   plusieurs écrans, c'est elle qui dit lequel est lequel. */
	/* Une case dont la position sort de la grille choisie : le bouton est
	   conservé, mais il sera déplacé vers la première case libre par le
	   plugin. Le dire ici évite de le découvrir sur le mur. */
	.glowscreen32SlotOut {
		border-color: var(--al-warning-color, #b58900);
	}

	.glowscreen32PageTabs {
		margin-bottom: 10px;
	}

	.glowscreen32CardMac {
		display: block;
		font-size: .8em;
		font-family: monospace;
		opacity: .6;
	}
</style>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter un écran}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>
		<legend><i class="fas fa-list"></i> {{Mes écrans}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucun écran pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Cliquez sur « Ajouter un écran » et donnez-lui un nom, par exemple « Salon ».}}</li>';
			echo '<li>{{Saisissez l\'adresse MAC de la carte ESP32, telle qu\'elle apparaît sur sa console au démarrage.}}</li>';
			echo '<li>{{Remplissez les boutons : un libellé, une couleur, une icône, et ce que l\'appui déclenche.}}</li>';
			echo '<li>{{Enregistrez, puis reportez l\'URL et la clé du cadre « Ce qu\'il faut donner à la carte » dans le firmware.}}</li>';
			echo '</ol>';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			/* L'adresse MAC sur la vignette : avec plusieurs écrans, le nom seul
			   ne dit pas lequel est lequel, et c'est la MAC — pas le nom — qui
			   relie un équipement à une carte posée sur un mur. */
			$mac = glowscreen32::prettyMac($eqLogic->getConfiguration('mac', ''));
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<i class="fas fa-tablet-alt" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="glowscreen32CardMac">' . (($mac != '') ? $mac : '{{adresse MAC à saisir}}') . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>

		<?php if (count($eqLogics) > 0) { ?>
			<legend><i class="fas fa-satellite-dish"></i> {{Le parc en un coup d'oeil}}</legend>
			<div class="table-responsive" style="margin:5px;">
				<table class="table table-bordered table-condensed">
					<thead>
						<tr>
							<th>{{Écran}}</th>
							<th style="width:170px;">{{Adresse MAC}}</th>
							<th style="width:230px;">{{Dernier contact}}</th>
							<th style="width:120px;">{{Firmware}}</th>
							<th style="width:150px;">{{Verrou OTA}}</th>
							<th style="width:130px;">{{Adresse IP}}</th>
							<th style="width:120px;">{{En marche depuis}}</th>
							<th style="width:120px;">{{Dernier redémarrage}}</th>
							<th style="width:70px;">{{Pages}}</th>
							<th style="width:80px;">{{Boutons}}</th>
							<th style="width:80px;">{{Version}}</th>
						</tr>
					</thead>
					<tbody id="tbody_glowscreen32Fleet">
						<?php foreach ($gsOta['screens'] as $gsScreen) { ?>
							<tr data-gs-ota-allowed="<?php echo $gsScreen['ota'] ? 1 : 0; ?>">
								<td><?php echo htmlspecialchars($gsScreen['name'], ENT_QUOTES, 'UTF-8'); ?><?php echo ($gsScreen['enable'] == 1) ? '' : ' <span class="label label-default">{{désactivé}}</span>'; ?></td>
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
										<?php if ($gsFirmware !== null && $gsFirmware['version'] != '' && version_compare($gsFirmware['version'], $gsScreen['fw'], '>')) { ?>
											<span class="label label-info">{{en retard}}</span>
										<?php } ?>
									<?php } ?>
								</td>
								<!-- Les deux verrous, et leur ET logique : c'est ce que la carte
								     recevra, et non ce qui est coché quelque part. -->
								<td class="glowscreen32OtaCell">
									<?php if ($gsScreen['otaOpen']) { ?>
										<span class="label label-success">{{ouvert}}</span>
									<?php } elseif (!$gsScreen['ota']) { ?>
										<span class="label label-default">{{fermé (écran)}}</span>
									<?php } else { ?>
										<span class="label label-default">{{fermé (global)}}</span>
									<?php } ?>
								</td>
								<!-- v2.2 : les diagnostics que la carte joint à son ping. Une
								     cause « panic » ou « wdt » signale un firmware qui plante sans
								     que personne ne le voie. -->
								<td><?php echo ($gsScreen['ip'] != '') ? '<code>' . htmlspecialchars($gsScreen['ip'], ENT_QUOTES, 'UTF-8') . '</code>' : '—'; ?></td>
								<td><?php echo ($gsScreen['uptimeHuman'] != '') ? htmlspecialchars($gsScreen['uptimeHuman'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
								<td>
									<?php if ($gsScreen['rst'] == '') { ?>
										—
									<?php } else { ?>
										<span class="label <?php echo in_array($gsScreen['rst'], array('panic', 'wdt', 'brownout'), true) ? 'label-danger' : 'label-default'; ?>"><?php echo htmlspecialchars($gsScreen['rst'], ENT_QUOTES, 'UTF-8'); ?></span>
									<?php } ?>
								</td>
								<td><?php echo $gsScreen['pages']; ?></td>
								<td><?php echo $gsScreen['buttons']; ?></td>
								<td><?php echo $gsScreen['version']; ?></td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
			<span class="help-block" style="margin:0 5px 10px 5px;">{{Le dernier contact est horodaté à chaque appel reçu, « layout » comme « ping », arrondi à la minute, dans la commande « Dernier contact ». « Hors ligne » s'affiche au-delà de 3 × 2 × poll secondes sans nouvelle (plus une minute de marge) : une carte dont l'écran est atténué a le droit d'espacer ses appels jusqu'à 2 × poll, et un seuil plus court ferait passer tout le parc hors ligne chaque nuit. Adresse IP, durée de fonctionnement et cause du dernier redémarrage sont annoncées par la carte elle-même (firmware v2.2). La colonne « Firmware » est la version que la carte elle-même annonce ; « Verrou OTA » est le résultat des DEUX verrous, celui du plugin et celui de l'écran — c'est avec ces deux colonnes qu'on pilote un déploiement progressif.}}</span>
		<?php } ?>

		<!-- ========================= FIRMWARE / OTA ========================= -->
		<legend><i class="fas fa-microchip"></i> {{Firmware (mise à jour par le réseau)}}</legend>
		<div style="margin:5px;">
			<div class="alert alert-warning">
				<b>{{Deux verrous, tous deux fermés par défaut.}}</b>
				{{L'OTA est la seule fonction où Jeedom peut casser durablement un écran à distance : il faudrait décrocher la carte du mur pour la rebrancher en USB. Il faut donc que le verrou global ci-dessous ET le verrou de l'écran concerné (onglet « Écran » de l'équipement) soient ouverts pour qu'une carte reçoive une mise à jour. Une carte bloquée reçoit exactement la même réponse qu'une carte à jour : elle n'a aucun moyen de faire la différence, donc aucun moyen de passer outre.}}
				<br><br>
				<b>{{La marche à suivre :}}</b>
				{{déposer le binaire, ouvrir le verrou global, puis ouvrir UN SEUL écran témoin. Vérifier qu'il revient en ligne, que sa version a changé dans le tableau ci-dessus, et qu'il fonctionne. Ouvrir ensuite les autres. Si quelque chose tourne mal, fermer le verrou global arrête net la propagation : les écrans qui n'ont pas encore mis à jour continuent d'interroger et reçoivent « pas de mise à jour ».}}
			</div>

			<form class="form-horizontal">
				<div class="form-group">
					<label class="col-sm-3 control-label">{{Verrou global}}</label>
					<div class="col-sm-9">
						<label class="checkbox-inline">
							<input type="checkbox" id="cb_glowscreen32Ota"<?php echo $gsOta['enabled'] ? ' checked' : ''; ?>>
							{{Autoriser les mises à jour par le réseau}}
						</label>
						<span id="span_glowscreen32OtaState" class="label <?php echo $gsOta['enabled'] ? 'label-success' : 'label-default'; ?>" style="margin-left:10px;"><?php echo $gsOta['enabled'] ? '{{ouvert}}' : '{{fermé}}'; ?></span>
						<span class="help-block" style="margin:0;">{{Prend effet immédiatement, sans enregistrement : c'est un interrupteur d'arrêt d'urgence, il ne doit pas dépendre d'un bouton « Sauvegarder ». Chaque changement est écrit dans le journal du plugin.}}</span>
					</div>
				</div>

				<div class="form-group">
					<label class="col-sm-3 control-label">{{Firmware déposé}}</label>
					<div class="col-sm-9">
						<div id="div_glowscreen32Firmware">
							<?php if ($gsFirmware === null) { ?>
								<span class="form-control-static">{{Aucun firmware déposé.}}</span>
							<?php } else { ?>
								<table class="table table-condensed" style="margin:0;max-width:760px;">
									<tr><td style="width:120px;">{{Version}}</td><td><b><?php echo $gsFirmware['version']; ?></b><?php echo $gsFirmware['exists'] ? '' : ' <span class="label label-danger">{{fichier introuvable}}</span>'; ?></td></tr>
									<tr><td>{{Fichier}}</td><td><code><?php echo $gsFirmware['file']; ?></code> — <?php echo glowscreen32::humanSize($gsFirmware['size']); ?> (<?php echo $gsFirmware['size']; ?> {{octets}})</td></tr>
									<tr><td>{{SHA-256}}</td><td><code style="word-break:break-all;font-size:11px;"><?php echo $gsFirmware['sha256']; ?></code></td></tr>
									<tr><td>{{URL}}</td><td><code style="word-break:break-all;font-size:11px;"><?php echo $gsFirmware['url']; ?></code></td></tr>
									<tr><td>{{Déposé le}}</td><td><?php echo $gsFirmware['date']; ?></td></tr>
								</table>
							<?php } ?>
						</div>
						<span class="help-block" style="margin:5px 0 0 0;">{{La carte télécharge cette URL et vérifie l'empreinte SHA-256 AVANT de basculer sur la nouvelle partition. L'empreinte est calculée ici, sur le fichier réellement écrit, et jamais reprise d'une saisie.}}</span>
					</div>
				</div>

				<div class="form-group">
					<label class="col-sm-3 control-label">{{Déposer un firmware}}</label>
					<div class="col-sm-9">
						<input type="file" id="in_glowscreen32FirmwareFile" accept=".bin,application/octet-stream">
						<div style="margin-top:8px;">
							<a class="btn btn-sm btn-success" id="bt_glowscreen32FirmwareUpload"><i class="fas fa-upload"></i> {{Déposer}}</a>
							<a class="btn btn-sm btn-danger" id="bt_glowscreen32FirmwareRemove" style="<?php echo ($gsFirmware === null) ? 'display:none;' : ''; ?>"><i class="fas fa-trash"></i> {{Retirer}}</a>
						</div>
						<span class="help-block" style="margin:5px 0 0 0;">{{Le fichier <code>.bin</code> produit par PlatformIO (<code>.pio/build/cyd/firmware.bin</code>). La version est lue DANS le binaire, dans le descripteur que l'outillage ESP-IDF y écrit : c'est la même que celle que la carte annonce, ce qui est la seule façon de comparer les deux. Un fichier qui ne commence pas par l'octet 0xE9 est refusé — ce n'est pas une image d'application ESP32.}}</span>
						<span class="help-block" style="margin:5px 0 0 0;">{{Le binaire est rangé dans <code><?php echo $gsOta['dir']; ?></code><?php echo $gsOta['writable'] ? '' : ' — <b>ce dossier n\'est pas accessible en écriture par le serveur web</b>'; ?>. Il est exclu du déploiement du plugin : un redéploiement ne l'efface pas.}}</span>
					</div>
				</div>
			</form>
		</div>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-default btn-sm eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Écran}}</span></a></li>
			<li role="presentation"><a href="#buttonstab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-th"></i><span class="hidden-xs"> {{Boutons}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ============================ ÉCRAN ============================ -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Salon}}">
								</div>
								<div class="col-sm-3">
									<span class="help-block" style="margin:0;">{{Affiché en haut de l'écran.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach (jeeObject::buildTree(null, false) as $object) {
											echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Activer}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
								<label class="col-sm-2 control-label">{{Visible}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
								<div class="col-sm-3">
									<span class="help-block" style="margin:0;">{{Un écran désactivé n'est plus reconnu par l'API : la carte reçoit « unknown_device » et ne commande plus rien.}}</span>
								</div>
							</div>
						</fieldset>

						<fieldset>
							<legend><i class="fas fa-microchip"></i> {{Carte}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Adresse MAC}}</label>
								<div class="col-sm-4">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="mac" placeholder="24:6f:28:12:34:56">
								</div>
								<div class="col-sm-5">
									<span class="help-block" style="margin:0;">{{L'adresse MAC de l'ESP32, avec ou sans séparateurs : c'est elle, et elle seule, qui relie cet équipement à une carte. Elle est affichée sur la console série de la carte au démarrage.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Rafraîchissement}}</label>
								<div class="col-sm-2">
									<input type="number" min="5" max="3600" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="poll" placeholder="30">
								</div>
								<div class="col-sm-7">
									<span class="help-block" style="margin:0;">{{Secondes entre deux vérifications de la carte. C'est un conseil donné à la carte, pas une contrainte : elle reste libre de son rythme. Chaque vérification est une requête PHP sur la box, 30 secondes est un bon compromis.}}</span>
								</div>
							</div>
						</fieldset>

						<fieldset>
							<legend><i class="fas fa-th"></i> {{Grille et bandeau}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Grille}}</label>
								<div class="col-sm-3">
									<!-- Pas une .eqLogicAttr : la grille est un couple (colonnes, lignes) rangé
									     dans configuration.grid, et il est recomposé à la main dans saveEqLogic(),
									     exactement comme les boutons. -->
									<select class="form-control" id="sel_glowscreen32Grid">
										<option value="3x2">3 &times; 2 &mdash; 6 {{cases}}</option>
										<option value="3x3">3 &times; 3 &mdash; 9 {{cases}}</option>
										<option value="4x2">4 &times; 2 &mdash; 8 {{cases}}</option>
										<option value="4x3">4 &times; 3 &mdash; 12 {{cases}}</option>
									</select>
								</div>
								<div class="col-sm-6">
									<span class="help-block" style="margin:0;">{{Le nombre de cases par page, sur l'écran 320×240. 3×3 par défaut. Une carte dont le firmware est antérieur à la v2 ne sait dessiner que 3×2, et ne recevra de toute façon que les six premiers boutons. Réduire la grille ne perd aucun bouton : ceux dont la case n'existe plus sont déplacés vers la première case libre, et le journal le dit.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Balayage}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="swipe">
								</div>
								<label class="col-sm-2 control-label">{{Horloge}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="clock">
								</div>
								<div class="col-sm-3">
									<span class="help-block" style="margin:0;">{{Le balayage change de page d'un glissement de doigt ; fermé par défaut, car un balayage involontaire est vite arrivé sur du tactile résistif. L'horloge s'affiche au bandeau, à partir de l'heure du serveur : la carte n'utilise pas de NTP.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Lecture seule}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="readonly">
								</div>
								<div class="col-sm-7">
									<span class="help-block" style="margin:0;">{{L'écran affiche, mais ne commande rien : le plugin refuse tout appui venant de lui (erreur read_only), quel que soit son firmware — même un firmware défectueux ou une clé API dérobée ne peuvent pas ouvrir le portail depuis cet écran. La navigation entre pages reste possible. Pour une entrée, un garage, un couloir.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Bandeau}}</label>
								<div class="col-sm-6">
									<div class="input-group">
										<input class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="info_cmd" placeholder="{{Commande d'information à afficher}}">
										<span class="input-group-btn">
											<a class="btn btn-default roundedRight" id="bt_glowscreen32InfoPick" title="{{Choisir la commande du bandeau}}"><i class="fas fa-list-alt"></i></a>
										</span>
									</div>
								</div>
								<div class="col-sm-3">
									<span class="help-block" style="margin:0;">{{Une température, une humidité, une puissance : la valeur est formatée ICI — arrondie, avec son unité, seize caractères au plus — et la carte ne fait que l'afficher. Elle voyage dans le « ping », elle ne fait donc pas redessiner l'écran à chaque degré.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Valeur périmée}}</label>
								<div class="col-sm-2">
									<input type="number" min="0" max="10080" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="info_max_age" placeholder="60">
								</div>
								<div class="col-sm-7">
									<span class="help-block" style="margin:0;">{{Ignorer la valeur si elle date de plus de ce nombre de MINUTES, et laisser le bandeau vide. 60 par défaut, <b>0 pour ne jamais périmer</b>. Un bandeau vide est honnête ; une température d'hier affichée comme si elle était actuelle ne l'est pas, et c'est pire qu'inutile sur un panneau qu'on consulte d'un coup d'œil en passant. Le seuil dépend du capteur : une station météo se rafraîchit toutes les dix minutes, un compteur d'énergie toutes les secondes. C'est la date de COLLECTE qui est lue, pas celle du dernier changement de valeur — une température stable n'est pas une température périmée.}}</span>
								</div>
							</div>
						</fieldset>

						<fieldset>
							<legend><i class="fas fa-download"></i> {{Mise à jour par le réseau}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Verrou de cet écran}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="ota_allowed">
								</div>
								<div class="col-sm-7">
									<span class="help-block" style="margin:0;">{{Fermé par défaut. Cet écran ne recevra de mise à jour que si ce verrou ET le verrou global du plugin sont ouverts — c'est ce qui permet de n'ouvrir qu'un seul écran témoin, de vérifier qu'il revient en ligne, puis d'ouvrir les autres. Tant qu'il est fermé, la carte reçoit exactement la réponse qu'elle recevrait si elle était à jour.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Firmware de la carte}}</label>
								<div class="col-sm-9">
									<span class="form-control-static" id="span_glowscreen32Firmware">-</span>
									<span class="help-block" style="margin:0;">{{La version que la carte a annoncée lors de son dernier appel. Elle vient de la carte, pas de Jeedom : tant qu'elle n'a pas interrogé un plugin qui sait la lire, elle reste inconnue.}}</span>
								</div>
							</div>
						</fieldset>

						<!-- v2.2 : ce que la carte dit d'elle-même, lu dans les commandes
						     d'information (glowscreen32.ajax.php, action screenstate). -->
						<fieldset>
							<legend><i class="fas fa-stethoscope"></i> {{Diagnostics de la carte}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Réseau}}</label>
								<div class="col-sm-9"><span class="form-control-static" id="span_glowscreen32Net">-</span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Fonctionnement}}</label>
								<div class="col-sm-9"><span class="form-control-static" id="span_glowscreen32Run">-</span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Mémoire}}</label>
								<div class="col-sm-9">
									<span class="form-control-static" id="span_glowscreen32Mem">-</span>
									<span class="help-block" style="margin:0;">{{Annoncés par la carte à chaque appel, à partir du firmware 2.2. Le plus gros bloc libre mesure la fragmentation : sans PSRAM, c'est lui qui finit par faire échouer une allocation.}}</span>
								</div>
							</div>
						</fieldset>

						<fieldset>
							<legend><i class="fas fa-wifi"></i> {{Changer le Wi-Fi de la carte}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Réseau (SSID)}}</label>
								<div class="col-sm-4">
									<!-- PAS des .eqLogicAttr : ces deux champs ne doivent jamais être
									     enregistrés dans la configuration de l'équipement. -->
									<input type="text" class="form-control" id="in_glowscreen32WifiSsid" maxlength="32" autocomplete="off">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Mot de passe}}</label>
								<div class="col-sm-4">
									<input type="password" class="form-control" id="in_glowscreen32WifiPass" maxlength="64" autocomplete="new-password">
								</div>
								<div class="col-sm-5">
									<a class="btn btn-sm btn-warning" id="bt_glowscreen32Wifi"><i class="fas fa-paper-plane"></i> {{Envoyer à la carte}}</a>
								</div>
							</div>
							<div class="form-group">
								<div class="col-sm-offset-3 col-sm-9">
									<span class="help-block" style="margin:0;">{{La carte essaie les nouveaux identifiants et, sans connexion sous 60 secondes, revient aux précédents. La commande est livrée au prochain appel de la carte, au plus une fois ; sans appel sous 10 minutes, elle expire. Le mot de passe n'est ni enregistré dans l'équipement, ni journalisé, ni placé dans le cache de Jeedom : il attend sa livraison dans un fichier lisible du seul serveur web (plugins/glowscreen32/data/secrets, exclu des sauvegardes), effacé dès la livraison ou à l'expiration. Il transite ensuite en clair sur le réseau local, comme la clé API. Mot de passe : vide (réseau ouvert), 8 à 63 caractères, ou une clé de 64 chiffres hexadécimaux. Firmware 2.2 ou plus récent requis.}}</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-plug"></i> {{Ce qu'il faut donner à la carte}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{URL}}</label>
								<div class="col-sm-9">
									<span class="form-control-static" style="word-break:break-all;"><code id="span_glowscreen32ApiUrl">-</code></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Clé API}}</label>
								<div class="col-sm-9">
									<span class="form-control-static" style="word-break:break-all;"><code id="span_glowscreen32ApiKey">-</code></span>
									<span class="help-block" style="margin:0;">{{La même pour tout le parc : le firmware est identique sur toutes les cartes. Réglages → Système → Configuration → onglet API, ligne GlowScreen32.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{En-tête}}</label>
								<div class="col-sm-9">
									<span class="form-control-static"><code>X-GLOWSCREEN32-APIKEY</code></span>
									<span class="help-block" style="margin:0;">{{La clé se présente dans cet en-tête. En paramètre d'URL elle fonctionne aussi, mais Apache l'inscrit alors en clair dans ses journaux à chaque appel.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Appel complet}}</label>
								<div class="col-sm-9">
									<textarea class="form-control" id="ta_glowscreen32ApiSample" rows="4" readonly style="font-family:monospace;font-size:11px;"></textarea>
									<span class="help-block" style="margin:0;">{{À coller dans un terminal pour vérifier la liaison sans la carte.}}</span>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-heartbeat"></i> {{État}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Version de la mise en page}}</label>
								<div class="col-sm-9">
									<span class="form-control-static" id="span_glowscreen32Version">-</span>
									<span class="help-block" style="margin:0;">{{Ce compteur augmente à chaque changement de mise en page. La carte le surveille et ne redessine son écran que lorsqu'il bouge.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Dernier contact}}</label>
								<div class="col-sm-9">
									<span class="form-control-static" id="span_glowscreen32Contact">-</span>
									<span class="help-block" style="margin:0;">{{Le dernier appel reçu de cette carte, « layout » comme « ping », arrondi à la minute. Avec plusieurs écrans, c'est ce champ qui dit lequel ne répond plus. La valeur affichée date de l'ouverture de la page.}}</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- =========================== BOUTONS =========================== -->
			<div role="tabpanel" class="tab-pane" id="buttonstab">
				<br>
				<div class="col-lg-7">
					<div class="alert alert-info" style="margin-bottom:10px;">
						<b>{{Jusqu'à quatre pages, douze boutons par page, trente-deux en tout.}}</b>
						{{Chaque bouton porte un libellé, une couleur, une icône et un mode. En « Action simple », l'appui joue toujours la même commande — c'est ce qu'il faut pour un portail ou un scénario. En « Interrupteur », le plugin lit l'état et joue la commande inverse : un appui allume, le suivant éteint. En « Navigation », le bouton ouvre une autre page : il ne commande rien du tout, et la carte y répond elle-même, sans réseau.}}
						<br><br>
						<b>{{Une carte dont le firmware est antérieur à la v2}}</b>
						{{ne reçoit que les six premiers boutons, boutons de navigation exclus, renumérotés de 0 à 5. Elle reste utilisable en mode dégradé plutôt que de recevoir une structure qu'elle ne comprend pas.}}
					</div>
					<ul class="nav nav-pills glowscreen32PageTabs" id="ul_glowscreen32Pages"></ul>
					<form class="form-horizontal">
						<div id="div_glowscreen32Page"></div>
						<div id="div_glowscreen32Buttons"></div>
					</form>
				</div>
				<div class="col-lg-5">
					<fieldset>
						<legend><i class="fas fa-desktop"></i> {{Aperçu}}</legend>
						<div class="glowscreen32Grid" id="div_glowscreen32Preview"></div>
						<span class="help-block">{{La page en cours d'édition, sur un écran 320×240, telle que la carte la dessinera : la grille suit le réglage de l'onglet « Écran ». L'aperçu suit la saisie ; il ne dit rien de l'état des lampes, que seule la carte connaît. L'identifiant sous chaque tuile est l'id du SCHÉMA 3 (firmware 2.3 et suivants), continu sur tout l'écran, pages comprises — celui que la carte renvoie à « press ». Une carte plus ancienne reçoit d'autres id : en schéma 2, les tuiles « valeur » sont retirées et la numérotation recalculée ; en schéma 1, seuls les six premiers boutons sont envoyés. « Voir ce que la carte reçoit » donne la correspondance de chaque schéma. ⚠ signale une commande sensible (portail, porte, garage, serrure, alarme).}}</span>
						<a class="btn btn-default btn-sm" id="bt_glowscreen32Preview"><i class="fas fa-code"></i> {{Voir ce que la carte reçoit}}</a>
						<pre id="pre_glowscreen32Payload" style="display:none;margin-top:10px;max-height:400px;overflow:auto;font-size:11px;"></pre>
					</fieldset>
				</div>
			</div>

			<!-- ========================== COMMANDES ========================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:300px;">{{Nom}}</th>
								<th style="width:180px;">{{Type}}</th>
								<th style="width:250px;">{{Paramètres}}</th>
								<th>{{Action}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('core', 'plugin.template', 'js'); ?>
<?php include_file('desktop', 'glowscreen32', 'js', 'glowscreen32'); ?>
