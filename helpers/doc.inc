<?php

class DocHelper extends Api {
	private $validators = array();
	private $table = array();
	private $groups = array();
	private $containers = array();
	private $alias = array();

	public function __construct() {}

	private function _parse_vars($doc) {
	   preg_match_all('#([A-Za-z0-9]+)\=(?:\'|")(.*?)(?:(?<!\\\)\'|")(?:\,|\)?)#', $doc, $vars);	
	   $v = array();
	   if (!empty($vars[1]) && !empty($vars[2])) {
          $v = array_combine($vars[1], $vars[2]);
	   }

	   return $v;
	}

	public function getContainingTable($property, $class = '') {
	   return $this->container[$property] ? $this->container[$property] : $class;
	}

	// Returns the alias for a column.
	public function getAlias($column) {
	  return $this->alias[$column];
	}

	protected function table($class, $property, $doc) {
		$d = str_replace(array('"', "'"), '', $doc);
		$this->container[$property] = $d;
	}

	protected function column($class, $property, $notations) {
      $v = $this->_parse_vars($notations);
      $this->table[$class][$v['name']] = $v;

      if (isset($v['required']) && $v['required'] == 'true') {
         $this->required[] = $v['name'];
      }

      if ($v['real']) {
      	$this->container[$v['real']] = $this->getContainingTable($v['name']);
      	$this->alias[$v['name']] = $v['real'];
	  }
	}

	protected function validate($class, $property, $doc) {
	  $validators = $this->_parse_vars($doc);

      if (!empty($validators['function'])) {
      	if (function_exists($validators['function'])) {
      	   $this->validators[$class][$property]['function'] = $validators['function'];
      	}
      }

      if (!empty($validators['regex'])) {
      	$this->validators[$class][$property]['regex'] = $validators['regex'];
      }
	}

	protected function oneingroup($class, $property, $group) {
		$group = str_replace(array('"', "'"), '', $group);
		$this->groups[$group][$class][$property] = 0;
	}

	protected function contextual($class, $property, $group) {
		// Nothing yet...
	}

	protected function getTable() {
	   return $this->table;
	}

	protected function getValidators() {
	   return $this->validators;
	}

	protected function getGroups() {
	   return $this->groups;
	}
}

?>