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
	private $isLatestVersionSaved = false;
	
	private $backtrace = null;
	
	public function storeTriggerExit($context, &$storage) {
		$mvcEvent = $context["functionArgs"][1];
		
		if ($mvcEvent instanceof MvcEvent) {
			$storage['event'] = array(	'name' => $context["functionArgs"][0],
										'target' => $this->getEventTarget($mvcEvent),
										'file'   => $this->getEventTriggerFile(),
										'line'   => $this->getEventTriggerLine(),
										'memory' => $this->formatSizeUnits(memory_get_usage(true)),
										'time' => $this->formatTime($context['durationInclusive']) . ' ms');
			
		} elseif (class_exists('ZF\MvcAuth\MvcAuthEvent') && is_a($mvcEvent, 'ZF\MvcAuth\MvcAuthEvent') && $mvcEvent->getIdentity()) {
			//event: authentication, authentication.post authorization authorization.post in Apigility
			//$storage['identity_role'][] = $mvcEvent->getIdentity()->getRoleId();
			$storage['Mvc_Auth_Event'] = array(	'eventName' => $context["functionArgs"][0],
												'AuthenticationService' => $mvcEvent->getAuthenticationService(),
												'hasAuthenticationResult' => $mvcEvent->hasAuthenticationResult(),
												'AuthorizationService' => $mvcEvent->getAuthorizationService(),
												'Identity' => $mvcEvent->getIdentity(),
												'isAuthorized' => $mvcEvent->isAuthorized());
		}
		
		$this->collectVersionData();
		$this->collectModules($mvcEvent, $storage);
		$this->collectRequest($context["functionArgs"][0], $mvcEvent, $storage);
		$this->collectConfig($mvcEvent, $storage);
	}
	
	
	public function storeHelperExit($context, &$storage) {
	    $helperName = $context["functionArgs"][0]; // plugin  name
	    $storage['helper']['name'] = $helperName;
	}

	////////////////////////////////////////////////////////////////
	//   PRIVATES
	////////////////////////////////////////////////////////////////
	
	private function collectVersionData() {
		if ($this->isLatestVersionSaved){
			return;
		}
		
		$isLatest = Version::isLatest();
		$latest   = Version::getLatest();
		
		$isLatest = ($isLatest) ? 'yes' : 'no';
		$latest = ($latest === null) ? 'N/A' : $latest;
		
		$storage['latestVersion'][] = array(Version::VERSION, $isLatest, $latest);
		$this->isLatestVersionSaved = true;
	}
	
	private function collectModules($mvcEvent, &$storage) {
		if ($this->isModulesSaved) {
			return;
		}
		
		if (!($mvcEvent instanceof MvcEvent)) {
			return;
		}
		
		if (! $application = $mvcEvent->getApplication()) {
			return;
		}
	
		$serviceManager = $application->getServiceManager();
		/* @var $moduleManager \Zend\ModuleManager\ModuleManagerInterface */
		$moduleManager  = $serviceManager->get('ModuleManager');
		$modules = array_keys($moduleManager->getLoadedModules());
		
		$storage['modules'][] = $modules;
		$this->isModulesSaved = true;
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
				'templates'  => $templates,
				'method'     => $mvcEvent->getRequest()->getMethod(),
				'status'     => $mvcEvent->getResponse()->getStatusCode(),
				'route'      => ($match === null) ? 'N/A' : $match->getMatchedRouteName(),
				'action'     => ($match === null) ? 'N/A' : $match->getParam('action', 'N/A'),
				'controller' => ($match === null) ? 'N/A' : $match->getParam('controller', 'N/A'),
		);
		$storage['request'][] = $data;
	}
	 	
	private function collectConfig($mvcEvent, &$storage) {
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
			$storage['config'][] = $this->makeArraySerializable($serviceLocator->get('Config'));
		}
		
		if ($serviceLocator->has('ApplicationConfig')) {
			$storage['applicationConfig'][] = $this->makeArraySerializable($serviceLocator->get('ApplicationConfig'));
		}
		
		$this->isConfigSaved = true;
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

