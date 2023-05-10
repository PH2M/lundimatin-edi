<?php

namespace LundiMatin\EDI\Controller\Import;
use LundiMatin\EDI\Model\LmbAction;
use LundiMatin\EDI\LmbEdi\Spec\Emeteur;


class Index extends LmbAction {

	protected $retour = array();
	
    public function doAction($params) {
		$emetteur = new Emeteur();
		//CATEGORIES
		if(isset($params['categs']) && $params['categs'] == 1) {
			if (!empty($params['categs_ids'])) { //import des catégories
				//pour les catégories sélectionnées
				$categs_ids  = $params['categs_ids'];
				$categs_ids_selected = explode(",", $categs_ids);
				$retour_import = "Import de catégorie selon leurs ID : ".$params['categs_ids'];
				$emetteur->recup_categs($categs_ids_selected);
			} else {
				//pour toutes les catégories
				$retour_import = "Import de toute les catégories";
				$emetteur->recup_categs("");
			}

			if (!empty($retour_import)) {
				$this->retour["result_categ"] = $retour_import;
			}
		}
		
		$retour_import = "";
		//ARTICLES
		if (isset($params['articles_ids'])  && isset($params['articles'])) { //import des articles
			if(empty($params['articles_ids'])){ //pour toutes les articles
				$retour_import = "Import de tout les articles";
				$emetteur->recup_products("");
			}else{ //pour les articles sélectionnés
				$articles_ids  = $params['articles_ids'];
				$articles_ids_selected = explode(",", $articles_ids);
				$retour_import = "Import d'articles selon leurs ID : ".$params['articles_ids'];
				$emetteur->recup_products($articles_ids_selected);
			}
		}
		if (!empty($retour_import)) {
			$this->retour["result_article"] = $retour_import;
		}
		$retour_import = "";
		//IMAGES
		if (isset($params['images_ids'])  && isset($params['images'])) { //import des images
			if(empty($params['images_ids'])){ //pour toutes les images
				$retour_import = "Import de toutes les images";
				$emetteur->recup_images("");
			}else{ //pour les images sélectionnées
				$images_ids  = $params['images_ids'];
				$images_ids_selected = explode(",", $images_ids);
				$retour_import = "Import d'images selon les ID articles : ".$params['images_ids'];
				$emetteur->recup_images($images_ids_selected);
			}
		}
		if (!empty($retour_import)) {
			$this->retour["result_image"] = $retour_import;
		}
		$retour_import = "";
		//MARQUES
		if (isset($params['marques'])) { //import des marques
			$retour_import = "Import de toutes les marques";
			$emetteur->recup_marques();
		}
		if (!empty($retour_import)) {
			$this->retour["result_marque"] = $retour_import;
		}
		$retour_import = "";
        //JEUX D'ATTRIBUTS
        if (isset($params['jeux_ids'])  && isset($params['jeux'])) { //import des jeux d'attribut
            if(empty($params['jeux_ids'])){ //pour toutes les images
                $retour_import = "Import de tout les jeux d'attributs";
                $emetteur->recup_attributSet("");
            }else{ //pour les articles sélectionnées
                $jeux_ids  = $params['jeux_ids'];
                $jeux_ids_selected = explode(",", $jeux_ids);
                $retour_import = "Import de jeux d'attribut selon les ID articles: ".$params['jeux_ids'];
                $emetteur->recup_attributSet($jeux_ids_selected);
            }
        }
        if (!empty($retour_import)) {
            $this->retour["result_jeux"] = $retour_import;
        }

        // Commandes
		if(isset($params['commandes']) && $params['commandes'] == 1) {
			if (!empty($params['commandes_ids'])) { //import des catégories
				//pour les catégories sélectionnées
				$commandes_ids  = $params['commandes_ids'];
				$commandes_ids_selected = explode(",", $commandes_ids);
				$retour_import = "Import de catégorie selon leurs ID : ".$params['commandes_ids'];
				$emetteur->recup_commandes($commandes_ids_selected);
			} else {
				//pour toutes les catégories
				$retour_import = "Import de toute les commandes";
				$emetteur->recup_commandes("");
			}

			if (!empty($retour_import)) {
				$this->retour["result_commandes"] = $retour_import;
			}
		}

        include __DIR__ . '/../../view/frontend/import.php';
    }
}