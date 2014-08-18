<?php

class ZF1 {

	public function storeDispatcherEnter($context, &$storage) {}

	public function storeDispatcherExit($context, &$storage) {
	    $Zend_Controller_Dispatcher_Standard = $context["this"];
	    $request = $context["functionArgs"][0];
	    
	    $action = $Zend_Controller_Dispatcher_Standard->getActionMethod($request);
	    $className = $this->getControllerName($Zend_Controller_Dispatcher_Standard, $request);
	    $storage['request'] = array (  'action' => $action,
	                                   'controller' => $className,
	                                   'moduleClaaName' => $this->getModuleClassName($Zend_Controller_Dispatcher_Standard, $className));
	}
	
	public function storeFrontDispatchEnter($context, &$storage) {}
	
	public function storeFrontDispatchExit($context, &$storage) {
		$Zend_Controller_Front = $context["this"];
		$plugins = $Zend_Controller_Front->getPlugins();
		 
		foreach ($plugins as $plugin) {
		  $storage['plugin'][get_class($plugin)] = $plugin;
		}
	}
	
    public function storeViewEnter($context, &$storage) {}
    public function storeViewExit($context, &$storage) {
    	$storage['view'] = $context["functionArgs"];
    }
    
    public function storeViewHelperEnter($context, &$storage) {}
    
    public function storeViewHelperExit($context, &$storage) {
    	
    	$name = $context["functionArgs"][0];
    	$args = $context["functionArgs"][1];
    	
    	$Zend_View_Abstract = $context["this"];
    	$helper = $Zend_View_Abstract->getHelper($name);
    	
    	$storage['activated_view_helper'] = array(	'name' => $name,
    												'args' => $args);/*,
    												'helperObject' => $helper);*/
    }
	
	////////////// PRIVATES ///////////////////
    private function getControllerName($Zend_Controller_Dispatcher_Standard, $request) {
        /**
         * Get controller class
         */
        if (!$Zend_Controller_Dispatcher_Standard->isDispatchable($request)) {
        	$controller = $request->getControllerName();
        	if (!$Zend_Controller_Dispatcher_Standard->getParam('useDefaultControllerAlways') && !empty($controller)) {
        		throw new Exception('Invalid controller specified (' . $request->getControllerName() . ')');
        	}
        
        	$className = $Zend_Controller_Dispatcher_Standard->getDefaultControllerClass($request);
        } else {
        	$className = $Zend_Controller_Dispatcher_Standard->getControllerClass($request);
        	if (!$className) {
        		$className = $Zend_Controller_Dispatcher_Standard->getDefaultControllerClass($request);
        	}
        }
        return $className;
    }
    
    private function getModuleClassName($Zend_Controller_Dispatcher_Standard, $className) {
        $moduleClassName = $className;
       
        
        $reflection = new \ReflectionProperty('Zend_Controller_Dispatcher_Standard', '_curModule');
        $reflection->setAccessible(true);
        $_curModule = $reflection->getValue($Zend_Controller_Dispatcher_Standard);
     
        
        $reflection = new \ReflectionProperty('Zend_Controller_Dispatcher_Standard', '_defaultModule');
        $reflection->setAccessible(true);
        $_defaultModule = $reflection->getValue($Zend_Controller_Dispatcher_Standard);
        
        if (($_defaultModule != $_curModule)
        		|| $Zend_Controller_Dispatcher_Standard->getParam('prefixDefaultModule'))
        {
        	$moduleClassName = $Zend_Controller_Dispatcher_Standard->formatClassName($_curModule, $className);
        }
        return $moduleClassName;
    }
}



$zre = new ZRayExtension("ZF1");
$zf1Storage = new ZF1();

// Allocate ZRayExtension for namespace "zf1"
$zre = new \ZRayExtension("zf1");

$zre->traceFunction("Zend_Controller_Dispatcher_Standard::dispatch", array($zf1Storage, 'storeDispatcherEnter'), array($zf1Storage, 'storeDispatcherExit'));
$zre->traceFunction("Zend_Controller_Front::dispatch", array($zf1Storage, 'storeFrontDispatchEnter'), array($zf1Storage, 'storeFrontDispatchExit'));
$zre->traceFunction("Zend_View::_run",  array($zf1Storage, 'storeViewEnter'), array($zf1Storage, 'storeViewExit'));
$zre->traceFunction("Zend_View_Abstract::__call", array($zf1Storage, 'storeViewHelperEnter'), array($zf1Storage, 'storeViewHelperExit'));

//$zre->traceFunction("Zend_Controller_Action_HelperBroker::getHelper", 'enterCallback', 'leaveCallback');
//$zre->traceFunction("Zend_Controller_Router_Rewrite::route", 'enterCallback', 'leaveCallback');
//$zre->traceFunction("Zend_Controller_Plugin_ErrorHandler::_handleError", 'enterCallback', 'leaveCallback');
