<?php
    ob_start();
    
    echo "    <style>";

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
		
		
    <br/>
    <?php include 'afficheur_retour.php'; ?> 
    <br/><br/>
    <form action="../../lmbedi/decrypt" method="post">
        <fieldset>
            <legend> Décryptage d'un message reçu</legend>
            <br/>
            <label>IDs des messages (séparateur ;)</label>
            <input type="text" name="messages_ids" placeholder="IDs Messages">
        </fieldset>
        </br>
        <input type="submit" value="Décrypter" />
    </form>
    