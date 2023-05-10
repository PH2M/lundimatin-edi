      
<style>
<?php 
	$red = rand (150,250);
	$green = rand (150,250);
	$blue = rand (150,250);
	
	echo "body {background-color: rgb(".$red.",".$green.",".$blue."); font-size: 20px;}";
	if($red+$green+$blue > 600){
		$this->red = 0;
		$this->green = 0;
		$this->blue = 0;
	}else if($red+$green+$blue < 520){
		$this->red = 255;
		$this->green = 255;
		$this->blue = 255;
	}else{
		$this->red = 300-$red;
		$this->green = 300-$green;
		$this->blue = 300-$blue;
	}

	$objectManager = \Magento\Framework\App\ObjectManager::getInstance(); 
	$FormKey = $objectManager->get('Magento\Framework\Data\Form\FormKey'); 
?>
			form {
				margin: 0 auto;
				width: 400px;
			}
			label{
			color: #696969;
			}
			input:focus, textarea:focus {
				border-color: #000;
			}
			input{
				cursor:pointer;
			}
			input[type = "text"]{
				padding-left: 10px;
				margin-top: 10px;
				cursor:grab;
				height: 30px;
				width: 350px;
			}
			input[type=checkbox]{
				box-shadow: inset 2px 2px 2px rgba(255,255,255,.4), 2px 2px 2px rgba(0,0,0,0.5);
				height: 20px;
				width: 20px;
			}
			input[type=checkbox]:checked{
				box-shadow: inset 2px 2px 2px rgba(0,0,0,0.5), 2px 2px 2px rgba(255,255,255,.4);
			}
			input[type=submit]{
				font-size: 20px;
				font-weight: bold;
				margin-left: 110;
				height: 50px;
				width: 200px;
			}
			input[type=submit]:hover{
				box-shadow: 1px 1px 1px rgba(220,230,255,255);
				background-color: #F0F8FF;
			}
			input[type = "checkbox"]:checked + label {
				font-weight: bold;
				color: 90470;
			}
			fieldset {
				padding:0 20px 20px 20px;
				margin-bottom:10px;
				border:2px solid black;
			}
			legend{
				font-weight: bold;
			}
			.fyi{
				margin-left: 30px;
			}
			.lienPratique{
				text-decoration: none;
				font-size: 1.3em; 
			}
		</style>
		
		
		</br>
		<?php include 'afficheur_retour.php'; //affiché après avoir validé le formulaire?> 
		</br></br>
		<form action="../../lmbedi/import" method="post">
			<fieldset>
			<legend> Choix des données à importer</legend>
				<ul>
					<li>
						<input type="checkbox" value="1" name="categs" checked id="categs"/>
						<label for="categs">Catégorie</label>
						<input type="text" name="categs_ids" placeholder="ID Catégorie">
					</li>
					</br>
					<li>
						<input type="checkbox" value="1" name="marques" id="marques"/>
						<label for="marques">Marques</label>
					</li>
					</br>
					<li>
						<input type="checkbox" value="1" name="articles" id="articles"/>
						<label for="articles">Articles</label>
						<input type="text" name="articles_ids" placeholder="ID Articles">
					</li>
					</br>
					<li>
						<input type="checkbox" value="1" name="images" id="images"/>
						<label for="images">Images</label>
						<input type="text" name="images_ids" placeholder="ID Articles dont on veut importer les Images">
					</li>
					</br>
					<li>
						<input type="checkbox" value="1" name="jeux" id="jeux"/>
						<label for="jeux">Jeux d'attributs</label>
						<input type="text" name="jeux_ids" placeholder="ID Articles dont on veut importer les jeux d'attributs">
					</li>
					</br>
					<li>
						<input type="checkbox" value="1" name="commandes" id="commandes"/>
						<label for="commandes">Commandes</label>
						<input type="text" name="commandes_ids" placeholder="ID Commandes">
					</li>
					<input type="hidden" value="start">
					<input type="hidden" name="form_key" value="<?php echo $FormKey->getFormKey() ?>">
				</ul>
			</fieldset>
			</br>
			<input type="submit" value="Exporter vers LMB"/>
			</br></br>
			<fieldset class="fyi">
				<legend> FYI </legend>
				</br>
				<b>Pour importer des éléments spécifique</b>
				<p>inscrire les ids des éléments separées par des virgules dans le champ texte correspondant </p>
				<b>Pour importer tout les éléments</b>
				<p>laissez le champ texte correspondant vide</p>
			</fieldset>
			<a class="lienPratique" href="/lmbedi/debug">Page de debug pour tracer l'import</a>
		</form>