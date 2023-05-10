<style>
	.retour{
		margin-left: 30;
		color: #A0522D;
		<?php
		echo "color: rgb(".$this->red.",".$this->green.",".$this->blue.");"
		?>
	}
	.decrypt{
		margin-left: 10;
		padding: 2px;
		background-color: #eeeeee;
        font-size: 12px;
        border: 1px solid black;
	}
</style>

<?php
	if (!empty($this->retour)) {
		if(!empty($this->retour["result_categ"])){
			echo("<b class='retour'>".$this->retour["result_categ"]."</b></br>");
		}
		if(!empty($this->retour["result_article"])){
			echo("<b class='retour'>".$this->retour["result_article"]."</b></br>");
		}
		if(!empty($this->retour["result_image"])){
			echo("<b class='retour'>".$this->retour["result_image"]."</b></br>");
		}
		if(!empty($this->retour["result_marque"])){
			echo("<b class='retour'>".$this->retour["result_marque"]."</b>");
		}
        if(!empty($this->retour["result_jeux"])){
            echo("<b class='retour'>".$this->retour["result_jeux"]."</b>");
        }
        if(!empty($this->retour["decryptage"])){
            echo "<div class='decrypt'>";
            echo $this->retour["decryptage"];
            echo "</div>";
        }
	}
	