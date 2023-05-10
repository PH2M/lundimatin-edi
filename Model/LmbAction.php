<?php

namespace LundiMatin\EDI\Model;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use \LundiMatin\EDI\LmbEdi;

abstract class LmbAction extends \Magento\Framework\App\Action\Action implements HttpPostActionInterface, HttpGetActionInterface {
    
    protected $request;
    protected $pageFactory;
	static $context = null;
    
    public function __construct(
            \Magento\Framework\App\Action\Context $context,
            \Magento\Framework\App\Request\Http $request,
            \Magento\Framework\View\Result\PageFactory $pageFactory
    ) {
        parent::__construct($context);
		self::$context = $context;
        $this->request = $request;
        $this->pageFactory = $pageFactory;
        include_once(__DIR__."/../LmbEdi/LmbEdi.php");
    }

    public function execute() {
        $om = \Magento\Framework\App\ObjectManager::getInstance();
        $appState = $om->get('Magento\Framework\App\State');
        $appState->emulateAreaCode(
            \Magento\Framework\App\Area::AREA_ADMINHTML,
            array($this, "executeAction")
        );
    }
    
    public function executeAction() {
        $params = $this->request->getParams();
        $this->doAction($params);
    }
    
	static function getContext(){
			return self::$context;
	}
    
    public abstract function doAction($params);
    
    protected function checkSerial($params) {
        if (empty($params["serial_code"]) || \lmbedi_config::CODE_CONNECTION() != $params["serial_code"]) {
            LmbEdi\trace('call_api', 'ERREUR DE CODE DE CONNEXION !');
            exit("code error");
        }
    }
    
    protected function send($data) {
        $data = array(
            "exit_status" => 0,
            "content" => $data 
        );
        die (json_encode($data));
    }
}
