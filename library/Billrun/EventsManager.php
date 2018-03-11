<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of EventsManager
 *
 * @author shani
 */
class Billrun_EventsManager {

	const EVENT_TYPE_BALANCE = 'balance';
	const CONDITION_IS = 'is';
	const CONDITION_IN = 'in';
	const CONDITION_IS_NOT = 'is_not';
	const CONDITION_IS_LESS_THAN = 'is_less_than';
	const CONDITION_IS_LESS_THAN_OR_EQUAL = 'is_less_than_or_equal';
	const CONDITION_IS_GREATER_THAN = 'is_greater_than';
	const CONDITION_IS_GREATER_THAN_OR_EQUAL = 'is_greater_than_or_equal';
	const CONDITION_REACHED_CONSTANT = 'reached_constant';
	const CONDITION_REACHED_CONSTANT_RECURRING = 'reached_constant_recurring';
	const CONDITION_HAS_CHANGED = 'has_changed';
	const CONDITION_HAS_CHANGED_TO = 'has_changed_to';
	const CONDITION_HAS_CHANGED_FROM = 'has_changed_from';
	const ENTITY_BEFORE = 'before';
	const ENTITY_AFTER = 'after';

//	$em->triggerEvent("vtiger.entity.beforesave.final", $entityData);
	/**
	 *
	 * @var Billrun_EventsManager
	 */
	protected static $instance;
	protected $eventsSettings;
	protected static $allowedExtraParams = array('aid' => 'aid', 'sid' => 'sid', 'stamp' => 'line_stamp', 'row' => 'row');
	protected $notifyHash;

	/**
	 *
	 * @var Mongodloid_Collection
	 */
	protected static $collection;

	private function __construct($params = array()) {
		$this->eventsSettings = Billrun_Factory::config()->getConfigValue('events', []);
		self::$collection = Billrun_Factory::db()->eventsCollection();
	}

	public static function getInstance($params) {
		if (is_null(self::$instance)) {
			self::$instance = new Billrun_EventsManager($params);
		}
		return self::$instance;
	}

	public function trigger($eventType, $entityBefore, $entityAfter, $additionalEntities = array(), $extraParams = array()) {
		if (empty($this->eventsSettings[$eventType])) {
			return;
		}
		foreach ($this->eventsSettings[$eventType] as $event) {
			foreach ($event['conditions'] as $rawEventSettings) {
				if (isset($rawEventSettings['entity_type']) && $rawEventSettings['entity_type'] !== $eventType) {
					$conditionEntityAfter = $conditionEntityBefore = $additionalEntities[$rawEventSettings['entity_type']];
				} else {
					$conditionEntityAfter = $entityAfter;
					$conditionEntityBefore = $entityBefore;
				}
				if (!$this->isConditionMet($rawEventSettings['type'], $rawEventSettings, $conditionEntityBefore, $conditionEntityAfter)) {
					continue 2;
				}
				$conditionSettings = $rawEventSettings;
			}
			$this->saveEvent($eventType, $event, $entityBefore, $entityAfter, $conditionSettings, $extraParams);
		}
	}

	protected function isConditionMet($condition, $rawEventSettings, $entityBefore, $entityAfter) {
		switch ($condition) {
			case self::CONDITION_IS:
				return $this->arrayMatches($this->getWhichEntity($rawEventSettings, $entityBefore, $entityAfter), $rawEventSettings['path'], '$eq', $rawEventSettings['value']);
			case self::CONDITION_IN:
				return $this->arrayMatches($this->getWhichEntity($rawEventSettings, $entityBefore, $entityAfter), $rawEventSettings['path'], '$in', $rawEventSettings['value']);
			case self::CONDITION_IS_NOT:
				return !$this->arrayMatches($this->getWhichEntity($rawEventSettings, $entityBefore, $entityAfter), $rawEventSettings['path'], '$eq', $rawEventSettings['value']);
			case self::CONDITION_IS_LESS_THAN:
				return $this->arrayMatches($this->getWhichEntity($rawEventSettings, $entityBefore, $entityAfter), $rawEventSettings['path'], '$lt', $rawEventSettings['value']);
			case self::CONDITION_IS_LESS_THAN_OR_EQUAL:
				return $this->arrayMatches($this->getWhichEntity($rawEventSettings, $entityBefore, $entityAfter), $rawEventSettings['path'], '$lte', $rawEventSettings['value']);
			case self::CONDITION_IS_GREATER_THAN:
				return $this->arrayMatches($this->getWhichEntity($rawEventSettings, $entityBefore, $entityAfter), $rawEventSettings['path'], '$gt', $rawEventSettings['value']);
			case self::CONDITION_IS_GREATER_THAN_OR_EQUAL:
				return $this->arrayMatches($this->getWhichEntity($rawEventSettings, $entityBefore, $entityAfter), $rawEventSettings['path'], '$gte', $rawEventSettings['value']);
			case self::CONDITION_HAS_CHANGED:
				return (Billrun_Util::getIn($entityBefore, $rawEventSettings['path'], NULL) != Billrun_Util::getIn($entityAfter, $rawEventSettings['path'], NULL));
			case self::CONDITION_HAS_CHANGED_TO:
				return (Billrun_Util::getIn($entityBefore, $rawEventSettings['path'], NULL) != Billrun_Util::getIn($entityAfter, $rawEventSettings['path'], NULL)) && $this->arrayMatches($entityAfter, $rawEventSettings['path'], '$eq', $rawEventSettings['value']);
			case self::CONDITION_HAS_CHANGED_FROM:
				return (Billrun_Util::getIn($entityBefore, $rawEventSettings['path'], NULL) != Billrun_Util::getIn($entityAfter, $rawEventSettings['path'], NULL)) && $this->arrayMatches($entityBefore, $rawEventSettings['path'], '$eq', $rawEventSettings['value']);
			case self::CONDITION_REACHED_CONSTANT:
				$valueBefore = Billrun_Util::getIn($entityBefore, $rawEventSettings['path'], 0);
				$valueAfter = Billrun_Util::getIn($entityAfter, $rawEventSettings['path'], 0);
				$eventValue = $rawEventSettings['value'];
				if (preg_match('/\d+,\d+/', $eventValue)) {
					$eventValues = explode(',', $eventValue);
				} else {
					$eventValues = array($eventValue);
				}
				foreach ($eventValues as $eventVal) {
					if (($valueBefore < $eventVal && $eventVal <= $valueAfter) || ($valueBefore > $eventVal && $valueAfter <= $eventVal)) {
						return true;
					}
				}
				
				return false;
			case self::CONDITION_REACHED_CONSTANT_RECURRING:
				$rawValueBefore = Billrun_Util::getIn($entityBefore, $rawEventSettings['path'], 0);
				$rawValueAfter = Billrun_Util::getIn($entityAfter, $rawEventSettings['path'], 0);
				$eventValue = $rawEventSettings['value'];
				$valueBefore = ceil($rawValueBefore / $eventValue);
				$valueAfter = ceil($rawValueAfter / $eventValue);
				$triggerEquality = $rawValueAfter / $eventValue;
				$whole = floor($triggerEquality);
				$fraction = $triggerEquality - $whole;
				return ((intval($valueBefore) != intval($valueAfter) && $rawValueAfter > $eventValue) || ($fraction == 0 && $rawValueAfter != 0));
			default:
				return FALSE;
		}
	}
	
	protected function getWhichEntity($rawEventSettings, $entityBefore, $entityAfter) {
		return (isset($rawEventSettings['which']) && ($rawEventSettings['which'] == self::ENTITY_BEFORE) ? $entityBefore : $entityAfter);
	}

	protected function arrayMatches($data, $path, $operator, $value = NULL) {
		if (is_null($value)) {
			$value = Billrun_Util::getIn($data, $path, NULL);
			if (is_null($value)) {
				return FALSE;
			}
		}
		$query = array($path => array($operator => $value));
		return Billrun_Utils_Arrayquery_Query::exists(array($data), $query);
	}

	protected function saveEvent($eventType, $rawEventSettings, $entityBefore, $entityAfter, $conditionSettings, $extraParams = array()) {
		$event = $rawEventSettings;
		$event['event_type'] = $eventType;
		$event['creation_time'] = new MongoDate();
//		$event['value_before'] = $valueBefore;
//		$event['value_after'] = $valueAfter;
		foreach ($extraParams as $key => $value) {
			if (isset(self::$allowedExtraParams[$key])) {
				$event['extra_params'][self::$allowedExtraParams[$key]] = $value;
			}
		}
		$event['before'] = $this->getEntityValueByPath($entityBefore, $conditionSettings['path']);
		$event['after'] =  $this->getEntityValueByPath($entityAfter, $conditionSettings['path']);
		$event['based_on'] = $this->getEventBasedOn($conditionSettings['path']);
		if ($eventType == 'balance' && $this->isConditionOnGroup($conditionSettings['path'])) {
			$pathArray = explode('.', $conditionSettings['path']);
			array_pop($pathArray);
			$path = implode('.', $pathArray) . '.total';
			$event['group_total'] = $this->getEntityValueByPath($entityAfter, $path);
		}
		$event['stamp'] = Billrun_Util::generateArrayStamp($event);
		self::$collection->insert($event);
	}
	
	/**
	 * used for Cron to handle the events exists in the system
	 */
	public function notify() {
		$this->lockNotifyEvent();
		$events = $this->getEvents();
		foreach ($events as $event) {
			try {
				$response = Billrun_Events_Notifier::notify($event->getRawData());
				if ($response === false) {
					Billrun_Factory::log('Error notify event. Event details: ' . print_R($event, 1), Billrun_Log::NOTICE);
					$this->unlockNotifyEvent($event);
					continue;
				}
				$this->addEventResponse($event, $response);
			} catch (Exception $e) {
				$this->unlockNotifyEvent($event);
			}
		}
	}

	/**
	 * get all events that were found in the system and was not already handled
	 * 
	 * @return type
	 */
	protected function getEvents() {
		$query = array(
			'notify_time' => array('$exists' => false),
			'hash' => $this->notifyHash,
		);
		
		return self::$collection->query($query);
	}
	
	/**
	 * add response data to event and update notification time
	 * 
	 * @param array $event
	 * @param array $response
	 * @return mongo update result.
	 */
	protected function addEventResponse($event, $response) {
		$query = array(
			'_id' => $event->getId()->getMongoId(),
		);
		$update = array(
			'$set' => array(
				'notify_time' => new MongoDate(),
				'returned_value' => $response,
			),
		);
		
		return self::$collection->update($query, $update);
	}
	
	/**
	 * lock event before sending it.
	 * 
	 * @param array $event
	 */
	protected function lockNotifyEvent() {
		$this->notifyHash = md5(time() . rand(0, PHP_INT_MAX));
		$notifyOrphanTime = Billrun_Factory::config()->getConfigValue('events.settings.notify.notify_orphan_time', '1 hour');
		$query = array(
			'notify_time' => array('$exists' => false),
			'$or' => array(
				array('start_notify_time' => array('$exists' => false)),
				array('start_notify_time' => array('$lte' => new MongoDate(strtotime('-' . $notifyOrphanTime))))
			)
		);
		self::$collection->update($query, array('$set' => array('hash' => $this->notifyHash, 'start_notify_time' => new MongoDate())), array('multiple' => true));
	}
	
	
	/**
	 * unlock event in case of failue.
	 * 
	 * @param array $event
	 */
	protected function unlockNotifyEvent($event) {
		$query = array(
			'_id' => $event->getId()->getMongoId(),
		);
		$update = array(
			'$unset' => array(
				'hash' => true,
			),
		);
		
		self::$collection->update($query, $update);
	}
	
	/**
	 * get the value in entity by the defined path.
	 * 
	 * @param array $entity
	 * @param string $path
	 * 
	 */
	protected function getEntityValueByPath($entity, $path) {
		$pathArray = explode('.', $path);
		foreach($pathArray as $value) {
			$entity = isset($entity[$value]) ? $entity[$value] : 0;
			if (!$entity) {
				return 0;
			}
		}
		return $entity;
	}
	
		
	/**
	 * is the event usage / monetary based.
	 * 
	 * @param string $path
	 * 
	 */
	protected function getEventBasedOn($path) {
		return (substr_count($path, 'cost') == 0) ? 'usage' : 'monetary';
	}
	
			
	/**
	 * retuns true for conditions on groups.
	 * 
	 * @param string $path
	 * 
	 */
	protected function isConditionOnGroup($path) {
		return (substr_count($path, 'balance.groups') > 0);
	}
	
	
}