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

	/* L'aperçu de l'écran : la grille 3x2 du contrat, à l'échelle, pour qu'on
	   voie ce que la carte affichera avant d'aller le vérifier sur le mur. */
	.glowscreen32Grid {
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		grid-template-rows: repeat(2, 1fr);
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

	/* La MAC sous le nom de la vignette : discrète, mais toujours là. Avec
	   plusieurs écrans, c'est elle qui dit lequel est lequel. */
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

		<?php if (count($eqLogics) > 1) { ?>
			<legend><i class="fas fa-satellite-dish"></i> {{Le parc en un coup d'oeil}}</legend>
			<div class="table-responsive" style="margin:5px;">
				<table class="table table-bordered table-condensed">
					<thead>
						<tr>
							<th>{{Écran}}</th>
							<th style="width:170px;">{{Adresse MAC}}</th>
							<th style="width:90px;">{{Boutons}}</th>
							<th style="width:90px;">{{Version}}</th>
							<th style="width:180px;">{{Dernier contact}}</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach (glowscreen32::overview() as $gsScreen) { ?>
							<tr>
								<td><?php echo $gsScreen['name']; ?><?php echo ($gsScreen['enable'] == 1) ? '' : ' <span class="label label-default">{{désactivé}}</span>'; ?></td>
								<td><code><?php echo ($gsScreen['mac'] != '') ? $gsScreen['mac'] : '—'; ?></code></td>
								<td><?php echo $gsScreen['buttons']; ?></td>
								<td><?php echo $gsScreen['version']; ?></td>
								<td><?php echo ($gsScreen['contact'] != '') ? $gsScreen['contact'] : '{{jamais vu}}'; ?></td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
			<span class="help-block" style="margin:0 5px 10px 5px;">{{Le dernier contact est horodaté à chaque appel reçu, « layout » comme « ping » : un écran qui n'apparaît plus depuis plus longtemps que son intervalle de rafraîchissement est hors ligne.}}</span>
		<?php } ?>
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
						</fieldset>
					</form>
				</div>
			</div>

			<!-- =========================== BOUTONS =========================== -->
			<div role="tabpanel" class="tab-pane" id="buttonstab">
				<br>
				<div class="col-lg-7">
					<div class="alert alert-info" style="margin-bottom:10px;">
						<b>{{Six boutons, dans l'ordre de l'écran.}}</b>
						{{Chacun porte un libellé, une couleur, une icône, et ce que l'appui déclenche : une commande d'action de votre Jeedom, ou un scénario. Un bouton sans cible n'est pas envoyé à la carte — il ne laisse pas de case vide, les boutons suivants remontent.}}
					</div>
					<form class="form-horizontal">
						<div id="div_glowscreen32Buttons"></div>
					</form>
				</div>
				<div class="col-lg-5">
					<fieldset>
						<legend><i class="fas fa-desktop"></i> {{Aperçu}}</legend>
						<div class="glowscreen32Grid" id="div_glowscreen32Preview"></div>
						<span class="help-block">{{La grille 3×2 d'un écran 320×240, telle que la carte la dessinera. L'aperçu suit la saisie ; il ne dit rien de l'état des lampes, que seule la carte connaît.}}</span>
						<a class="btn btn-default btn-sm" id="bt_glowscreen32Preview"><i class="fas fa-code"></i> {{Voir ce que la carte reçoit}}</a>
						<pre id="pre_glowscreen32Payload" style="display:none;margin-top:10px;max-height:300px;overflow:auto;font-size:11px;"></pre>
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
