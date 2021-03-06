<?php
namespace ZRay;

use Serializable,
	Traversable,
	Closure,
	Zend\Mvc\MvcEvent,
	Zend\Version\Version,
	Zend\Stdlib\ArrayUtils;

class ZF2 {
	
	private $isConfigSaved = false;
	private $isModulesSaved = false;
	private $isEventSaved = false;
	private $isLatestVersionSaved = false;
	
	private $backtrace = null;
	
	public function storeTriggerExit($context, &$storage) {
		$mvcEvent = $context["functionArgs"][1];
		
		if ($mvcEvent instanceof MvcEvent) {
			$storage['event'][] = array(	'name' => $context["functionArgs"][0],
    										'target' => $this->getEventTarget($mvcEvent),
    										'file'   => $this->getEventTriggerFile(),
    										'line'   => $this->getEventTriggerLine(),
    										'memory' => $this->formatSizeUnits(memory_get_usage(true)),
    										'time' => $this->formatTime($context['durationInclusive']) . 'ms');
		} elseif (class_exists('ZF\MvcAuth\MvcAuthEvent') && is_a($mvcEvent, 'ZF\MvcAuth\MvcAuthEvent') && $mvcEvent->getIdentity()) {
			//event: authentication, authentication.post authorization authorization.post in Apigility
			$storage['identity_role'][] = array('roleId' => $mvcEvent->getIdentity()->getRoleId());
			$storage['Mvc_Auth_Event'][] = array(	'eventName' => $context["functionArgs"][0],
												'AuthenticationService' => $mvcEvent->getAuthenticationService(),
												'hasAuthenticationResult' => $mvcEvent->hasAuthenticationResult(),
												'AuthorizationService' => $mvcEvent->getAuthorizationService(),
												'Identity' => $mvcEvent->getIdentity(),
												'isAuthorized' => $mvcEvent->isAuthorized());
		}
		
		$this->collectVersionData($storage);
		$this->collectRequest($context["functionArgs"][0], $mvcEvent, $storage);
		$this->collectConfigurations($mvcEvent, $storage);
	}
	
	public function storeHelperExit($context, &$storage) {
	    $helperName = $context["functionArgs"][0]; // plugin  name
	    $storage['helper'][] = array ('name' => $helperName);
	}
	
	public function storeApplicationExit($context, &$storage) {
	    $Zend_Mvc_Application = $context['this'];
	    $response = $Zend_Mvc_Application->getResponse();
	   
	    //Zend\Http\PhpEnvironment\Response
	    $storage['response'][] = array (   'cookie' => $response->getCookie(),
	                                       'status' => $response->getStatusCode(),
	                                       'version' => $response->getVersion(),
	                                       'reasonPhase' => $response->getReasonPhrase(),
	                                       'body' => $response->getBody(),
	                                       'contentSent' => $response->contentSent());
	}
	
	////////////////////////////////////////////////////////////////
	//   PRIVATES
	////////////////////////////////////////////////////////////////
	
	private function collectVersionData(&$storage) {
		if ($this->isLatestVersionSaved){
			return;
		}
		
		$isLatest = Version::isLatest();
		$latest   = Version::getLatest();
		
		$isLatest = ($isLatest) ? 'yes' : 'no';
		$latest = ($latest === null) ? 'N/A' : $latest;
		
		$storage['version'][] = array('version' => Version::VERSION, 'isLatest' => $isLatest,'latest' => $latest);
		$this->isLatestVersionSaved = true;
	}
	
	/**
	 * Returns the line number of the file from which the event was triggered.
	 *
	 * @return integer
	 */
	private function getEventTriggerFile() {
		$trace = debug_backtrace();
		$this->backtrace = array_splice($trace, 2);
		if (isset($this->backtrace[0]) && isset($this->backtrace[0]['file']) && file_exists($this->backtrace[0]['file'])) {
			return basename(dirname($this->backtrace[0]['file'])) . '/' . basename($this->backtrace[0]['file']);
		}
	}
	
	private function getEventTriggerLine() {
		if (!$this->backtrace) {
			$trace = debug_backtrace();
			$this->backtrace = array_splice($trace, 2);
		}
		if (isset($this->backtrace[0]) && isset($this->backtrace[0]['line'])) {
			return $this->backtrace[0]['line'];
		}
	}
	
	/**
	 * Returns either the class name of the target, or the target string
	 *
	 * @return string
	 */
	private function getEventTarget($event) {
		return (is_object($event->getTarget())) ? get_class($event->getTarget()) : (string) $event->getTarget();
	}
	
	private function collectRequest($event, $mvcEvent, &$storage) {
		if (!($mvcEvent instanceof MvcEvent) ||  $event != MvcEvent::EVENT_FINISH) {
			return;
		}
		
		$templates   = array();
		$match       = $mvcEvent->getRouteMatch();
	
		$templates[] = $mvcEvent->getViewModel()->getTemplate();
		if ($mvcEvent->getViewModel()->hasChildren()) {
			foreach ($mvcEvent->getViewModel()->getChildren() as $child) {
				$templates[] = $child->getTemplate();
			}
		}
	
		if (empty($templates)) {
			$templates[] = 'N/A';
		}
	
		$data = array(
				'method'     => $mvcEvent->getRequest()->getMethod(),
				'status'     => $mvcEvent->getResponse()->getStatusCode(),
				'route'      => ($match === null) ? 'N/A' : $match->getMatchedRouteName(),
				'action'     => ($match === null) ? 'N/A' : $match->getParam('action', 'N/A'),
				'controller' => ($match === null) ? 'N/A' : $match->getParam('controller', 'N/A'),
				'templates'  => $templates,
		);
		$storage['request'][] = $data;
	}
	 	
	private function collectConfigurations($mvcEvent, &$storage) {
		if (!($mvcEvent instanceof MvcEvent)) {
			return;
		}
		
		if ($this->isConfigSaved) {
			return;
		}
		
		if (! $application = $mvcEvent->getApplication()) {
			return;
		}
		
		$serviceLocator = $application->getServiceManager();
		
		if ($serviceLocator->has('Config')) {
		    
    		$config = $this->makeArraySerializable($serviceLocator->get('Config'));
    		$this->collectConfig($config, $storage);
		}
		
		if ($serviceLocator->has('ApplicationConfig')) {
		    
    		$applicationConfig = $this->makeArraySerializable($serviceLocator->get('ApplicationConfig'));
    		$this->collectModules($applicationConfig['modules'], $storage);
    		$this->collectApplicationConfig($applicationConfig, $storage);
		}
		
		$this->isConfigSaved = true;
	}

	private function collectConfig($config, &$storage) {
	    foreach ($config as $name => $data) {
	        if (is_string($data)) {
	            $storage['config'][] = array( 'name' => $name, 'data' => $data);
	        } else {
	            $storage['config'][] = array( 'name' => $name, 'data' => var_export($data, true));
	        }
	    }
	}
	
	private function collectApplicationConfig($applicationConfig, &$storage) {
	    $storage['applicationConfig'][] = array('name' => 'modules', 'data' => $applicationConfig['modules']);
	    $storage['applicationConfig'][] = array('name' => 'module_listener_options[config_glob_paths]', 'data' => $applicationConfig['module_listener_options']['config_glob_paths']);
	    $storage['applicationConfig'][] = array('name' => 'module_listener_options[module_paths]', 'data' => $applicationConfig['module_listener_options']['module_paths']);
	    $storage['applicationConfig'][] = array('name' => 'listeners', 'data' => $applicationConfig['listeners']);
	}
	
	private function collectModules ($modulesConfig, &$storage) {
	    foreach ($modulesConfig as $name) {
	        $storage['modules'][] = array ('name' => $name);
	    }
	}
	
	/**
	 * Replaces the un-serializable items in an array with stubs
	 *
	 * @param array|\Traversable $data
	 *
	 * @return array
	 */
	private function makeArraySerializable($data)
	{
	    $serializable = array();
	
	    foreach (ArrayUtils::iteratorToArray($data) as $key => $value) {
	        if ($value instanceof Traversable || is_array($value)) {
	            $serializable[$key] = $this->makeArraySerializable($value);
	
	            continue;
	        }
	
	        if ($value instanceof Closure) {
	            $serializable[$key] = new ClosureStub();
	
	            continue;
	        }
	
	        $serializable[$key] = $value;
	    }
	
	    return $serializable;
	}
	
	private function reorderArray($config) {
	    $reorderedArray = array();
	    foreach ($config as $key => $value) {
	       if (!is_array($value) && !is_object($value)) {
	           unset($config[$key]);
	           $reorderedArray[$key] = $value;
	       }
	    }
	    return $reorderedArray + $config;
	}
	
	private function formatSizeUnits($bytes) {
		
		if ($bytes >= 1073741824) {
			$bytes = number_format ( $bytes / 1073741824, 2 ) . ' GB';
		} elseif ($bytes >= 1048576) {
			$bytes = number_format ( $bytes / 1048576, 2 ) . ' MB';
		} elseif ($bytes >= 1024) {
			$bytes = number_format ( $bytes / 1024, 2 ) . ' KB';
		} elseif ($bytes > 1) {
			$bytes = $bytes . ' bytes';
		} elseif ($bytes == 1) {
			$bytes = $bytes . ' byte';
		} 
        else
        {
            $bytes = '0 bytes';
        }

        return $bytes;
	}
	
	private function formatTime($ms) {
		//$uSec = $input % 1000;
		$input = floor($ms / 1000);
		return $input;
	}
	
}

/**
 * Empty class that represents an {@see \Closure} object
 */
class ClosureStub {
}


$zf2Storage = new ZF2();

// Allocate ZRayExtension for namespace "zf2"
$zre = new \ZRayExtension("zf2");

$zre->traceFunction("Zend\EventManager\EventManager::triggerListeners",  function(){}, array($zf2Storage, 'storeTriggerExit'));
$zre->traceFunction("Zend\View\Renderer\PhpRenderer::plugin",  function(){}, array($zf2Storage, 'storeHelperExit'));
$zre->traceFunction("Zend\Mvc\Application::run",  function(){}, array($zf2Storage, 'storeApplicationExit'));

