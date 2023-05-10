<?php

namespace LundiMatin\EDI\Model;

use \LundiMatin\EDI\LmbEdi;
use \Magento\Framework\App\ObjectManager;
use \Magento\Framework\Exception\NoSuchEntityException;

class LmbManager {
    
    const IN_PROCESS = "in_process";
    const IN_ERROR = "in_error";
    
    // Si une gestion doit être ajouter (par exemple pour Image), il faut également ajouter les méthodes manageImage et saveImage
    // Une surcharge de classe est possible par exemple via la classe LmbProductManager, qui doit hériter de LmbManager
    const PRODUCT_MANAGER = "Product";
    const CATEGORY_MANAGER = "Category";
    
    const OBJECT_FORMAT = "object";
    const REPOSITORY_FORMAT = "repository";
    
    protected $object_manager;
    protected $manager_type;
    protected $store_id = 0;
    
    protected $repository;
    protected $object;
    protected $priority;
    
    protected static $object_methods = array(
        'setSku',
        'setCategoryIds'
    );
    
    protected static $repository_methods = array(
        
    );
    
    protected static function getInstance($manager_type) {
        $class_name = "LundiMatin\EDI\Model\Lmb".$manager_type."Manager";
        if (class_exists($class_name)) {
            LmbEdi\trace("manager", "création d'un manager spécifique ($manager_type)");
            $manager = new $class_name();
        }
        else {
            LmbEdi\trace("manager", "création d'un manager générique");
            $manager = new LmbManager();
        }
        $manager->manager_type = $manager_type;
        return $manager;
    }
    
    protected function __construct() {
        $this->object_manager = ObjectManager::getInstance();
    }
    
    protected function setManagerStoreId($store_id) {
        $store_manager = $this->object_manager->create('\Magento\Store\Model\StoreManagerInterface');
        $store = $store_manager->getStore($store_id);
        $store_manager->setCurrentStore($store->getCode());
    }
    
    public static function create($manager_type, $manager_format = null) {
        $object = self::getInstance($manager_type);
        $object->manage();
        return $object;
    }
    
    public static function load($manager_type, $id, $store_id = 0, $manager_format = null) {
        $object = self::getInstance($manager_type);
        $object->manage($id, $store_id, $manager_format);
        return $object;
    }
    
    protected function manage($id = null, $store_id = 0, $manager_format = null) {
        $this->store_id = $store_id;
        $this->setManagerStoreId($store_id);
        $method = "manage".$this->manager_type;
        $this->$method($id, $store_id, $manager_format);
        $this->setManagerPriority();
        $this->setStoreId($store_id);
    }
    
    protected function manageProduct($id = null, $store_id = 0, $manager_format = null) {
        $object_only = \lmbedi_config::GET_PARAM('product_only') || $manager_format == self::OBJECT_FORMAT;
        $repository_only = \lmbedi_config::GET_PARAM('product_repository_only') || $manager_format == self::REPOSITORY_FORMAT;
        
        if (!empty($id)) {
            if (empty($object_only)) {
                $this->repository = $this->object_manager->get('\Magento\Catalog\Api\ProductRepositoryInterface')
                        ->getById($id, true, $store_id, true);
            }
            if (empty($repository_only)) {
                $this->object = $this->object_manager->create('Magento\Catalog\Model\Product')
                        ->load($id);
            }
        }
        else {
            $this->object = $this->object_manager->create('Magento\Catalog\Model\Product');
        }
    }
    
    protected function manageCategory($id = null, $store_id = 0, $manager_format = null) {
        $object_only = \lmbedi_config::GET_PARAM('category_only') || $manager_format == self::OBJECT_FORMAT;
        $repository_only = \lmbedi_config::GET_PARAM('category_repository_only') || $manager_format == self::REPOSITORY_FORMAT;
        
        if (!empty($id)) {
                try {
                    $this->repository = $this->object_manager->get('\Magento\Catalog\Api\CategoryRepositoryInterface')
                            ->get($id, $store_id);
                }
                catch (NoSuchEntityException $e) {
                    $this->repository = null;
                }
            if (empty($repository_only)) {
                $this->object = $this->object_manager->create('Magento\Catalog\Model\Category')
                        ->load($id);
            }
        }
        else {
            $this->object = $this->object_manager->get('\Magento\Catalog\Model\CategoryFactory')
                    ->create();
        }
    }
    
    protected function setManagerPriority() {
        $priority = \lmbedi_config::GET_PARAM(strtolower($this->manager_type).'_priority');
        
        if (!empty($priority) && $priority == "repository") {
            $this->priority = $this->repository;
        }
        else {
            $this->priority = $this->object;
        }
    }
    
    public function getManagerElement($manager_format = null) {
        if ($manager_format == self::OBJECT_FORMAT) {
            return $this->object;
        }
        if ($manager_format == self::REPOSITORY_FORMAT) {
            return $this->object;
        }
        
        if (!empty($this->priority)) {
            return $this->priority;
        }
        
        return $this->getManagerAlternative();
    }
    
    protected function getManagerAlternative() {
        if ($this->priority === $this->object) {
            return $this->repository;
        }
        else {
            return $this->object;
        }
    }
    
    public function __call($method, $arguments) {
        LmbEdi\trace("manager", "call $method");
        if (empty($this->repository) && empty($this->object)) {
            throw new \Exception("Erreur de chargement du LmbManager");
        }
        
        $first = $this->priority;
        $second = $this->getManagerAlternative();
        
        if (empty($first) && empty($second)) {
            throw new \Exception("Erreur de priorité du LmbManager");
        }
        
        $retour = self::IN_PROCESS;
        $current_class = get_class($this);
        if (in_array($method, $current_class::$repository_methods)) {
            if (!empty($this->repository)) {
                LmbEdi\trace("manager", "    to repository");
                $callable = array($this->repository, $method);
                $retour = call_user_func_array($callable, $arguments);
            }
            else if (!in_array($method, $current_class::$object_methods)) {
                $retour = self::IN_ERROR;
            }
        }
        if (in_array($method, $current_class::$object_methods)) {
            if (!empty($this->object)) {
                LmbEdi\trace("manager", "    to object");
                $callable = array($this->object, $method);
                $retour = call_user_func_array($callable, $arguments);
            }
            else if ($retour === self::IN_PROCESS) {
                $retour = self::IN_ERROR;
            }
        }
        
        if ($retour === self::IN_PROCESS) {
            if (!empty($first)) {
                LmbEdi\trace("manager", "    to first (".($first === $this->object ? "object" : "repository").")");
                $callable = array($first, $method);
                $retour = call_user_func_array($callable, $arguments);
            }
            else {
                LmbEdi\trace("manager", "    to second (".($second === $this->object ? "object" : "repository").")");
                $callable = array($second, $method);
                $retour = call_user_func_array($callable, $arguments);
            }
        }
        
        if (substr($method, 0, 3) == "get" || substr($method, 0, 4) == "load") {
            if ($retour === self::IN_PROCESS) {
                throw new \Exception("Échec de l'application de la méthode $method");
            }
            else if ($retour === self::IN_ERROR) {
                throw new \Exception("Erreur lors de l'application de la méthode $method");
            }
            else {
                LmbEdi\trace("manager", "return for get or load");
                return $retour;
            }
        }
        
        LmbEdi\trace("manager", "return this");
        return $this;
    }
    
    public function save() {
        $method = "saveManager".$this->manager_type;
        $this->$method();
    }
    
    public function forceManagerFormat($manager_format) {
        if ($manager_format == self::OBJECT_FORMAT) {
            $this->repository = null;
        }
        if ($manager_format == self::REPOSITORY_FORMAT) {
            $this->object = null;
        }
    }
    
    protected function saveManagerProduct() {
        if (!empty($this->repository)) {
            $this->object_manager
                    ->get('\Magento\Catalog\Api\ProductRepositoryInterface')
                    ->save($this->repository);
        }
        
        if (!empty($this->object)) {
            $this->object->save();
        }
    }

    protected function saveManagerCategory() {
        if (!empty($this->repository)) {
            $this->object_manager
                ->get('\Magento\Catalog\Api\CategoryRepositoryInterface')
                ->save($this->repository);
        }
        
        if (!empty($this->object)) {
            $this->object->save();
        }
    }
}
