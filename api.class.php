<?php

/**
 *  DoSomething internal API class
 *
 *  The DoSomething internal API allows developers of DoSomething.org to easily
 *  access and manage information across the site.  The API class centralizes core
 *  functionality into one place for sustainability, portability, and abstractability.
 *
 *	The API is a class with chain-able methods, and can be called in the following manner:
 *
 *  @example Change's a user's mobile number
 *
 *  $api = new Api();
 *  $api->get('user')
 *    ->context('mail', 'so-and-so@test.com')
 *    ->set('mobile', '212-867-5309')
 *    ->change();
 *
 *  The above will find a user with the email so-and-so@test.com and set their phone number
 *  as 212-867-5309.  That's all that's needed!
 *
 *  The API has standard CRUD endpoint methods:
 *    * create()   (create)
 *    * get()      (read)
 *    * update()   (update)
 *    * remove()   (delete)
 *
 *  get(), update() and remove() MUST be accompanied by a context() method to figure out what
 *  to perform the action on.  An ApiException will be thrown in case a context is missing for
 *  those methods.
 *
 *  update() and create() should be be accompanied by at least one set() call.  set()...sets
 *  variables to be created or updated.
 */

class Api {
   // Documentation helper
   public $doc;

   // Stores the current running api
   protected $loaded;

   // The current directory.
   protected $api_dir = __DIR__;

   // The function scope for use in comments (e.g. @Example\Validate())
   protected $reflection_method = 'Api';

   /**
    *  Constructor for the API.
    *
    *  @param string $object
    *	 An optional object to load on class initialization.
    *
    *  @return object
    *	 Returns the API class for the requested object.
    */
   public function __construct($object = '') {
      // Loads the documentation helper.
      if (!$this->doc) {
        require_once $this->api_dir . '/helpers/doc.inc';
        $this->doc = new DocHelper;
      }

      // If we're calling the class with an object, instatiate the get() method.
      if (!empty($object)) {
      	return $this->load($object);
      }
   }

   /**
    *  Loads an API object, builds the column information from it, and
    *  returns the object for use.
    *
    *  @param string $object
    *    The API object to load.
    *
    *  @return object
    *    The requested API object.
    */
   public function load($object) {
      // Load the API object.
      require_once $this->api_dir . '/objects/' . $object . '.api.php';

      // Set the "loaded" parameter to the current object.
      $this->loaded = $object;

      // Get comments and parse them.
      $reflector = new ReflectionClass($object);
      $c = $reflector->getProperties();
      foreach ($c AS $key => $property) {
        $p = $property->getDocComment();
        $this->_build_column_info($property->class, $property->name, $p);
      }

      // Load the object if it doesn't exist already
      if (!$this->$object) {
        $o = new $object();
        // Make sure the doc helper is within the scope of the object.
        $o->doc = $this->doc;
        $o->loaded = $this->loaded;

        $this->$object = $o;
      }

      // Return the object.
      return $this->$object;
   }

   // Loads the doc helper.
   protected function doc() {
   	 return $this->doc;
   }

   /**
    *  Parses property doc comments to get Graph information,
    *  validation and dependencies.
    * 
    *  @param $class
    *    The property's class.
    *
    *  @param $property
    *    The name of the property.
    *
    *  @param
    *    The doc comments for the property.
    */
   public function _build_column_info($class, $property, $doc) {
      if (!empty($doc)) {
         preg_match_all('#\@' . $this->reflection_method . '\\\(?<function>.*?)\((?<args>.*?)\)#', $doc, $docFunctions);
         foreach ($docFunctions['function'] AS $key => $function) {
            $func = strtolower($function);

            if (!empty($func)) {
               $this->doc->{$func}($class, $property, $docFunctions['args'][$key]);
            }
         }
      }
   }

   /**
    *  Adds a public property to the current object.
    *
    *  @param string $property
    *    The name (key) of the property.
    *
    *  @param string $value
    *    The value of the property.
    *
    *  @param boolean $context
    *    Whether or not the parameter is passed in context.
    *
    *  @return nothin'
    */
   public function add_property($property, $value, $context = false) {
      if (is_array($value) && count($value) == 1) {
         $value = reset($value);
      }

      // Check to see if the property exists by itself.
      $without = strtolower($property);
      if (property_exists($this, $without)) {
      	if ($context) {
      	  $this->context->{$without} = $value;
      	}
      	else {
          $this->{$without} = $value;
        }
      }
      // Otherwise, check to see if the property exists with underscores strategically placed next to UpperCase characters.
      else {
         $with = $property;
         // Replace the first character with an underscore character -- because we don't want to lead the property with an underscore!
         $with{0} = strtolower($with{0});
         // Replace uppercase characters with underscores, followed by the lowercase character.
         $with = strtolower(preg_replace('#[A-Z]#', '_\\0', $with));
         
         // Noooow try again...
         if (property_exists($this, $with)) {
           if ($context) {
             $this->context->{$with} = $value;
           }
           else {
             $this->{$with} = $value;
           }
         }
         else {
            throw new ApiException('Could not find property ' . $property);
         }
      }
   }

   /**
    *  Validates all available entities given the PHPDoc comments and passes errors
    *  if they fail.
    */
   protected function _check_entities() {
   	  // Generic error messages for fields.
      $errors = array(
         'int' => 'The following fields need to be numeric: !fields.  ',
         'string' => 'The following fields need to be a string: !fields.  ',
      );

      // For later.
      $missing = $oneof = array();

      // Gets basic table structure and validators.
      $validators = $this->doc->getValidators();
      $table = $this->doc->getTable();
      $groups = $this->doc->getGroups();

      foreach ($table AS $entity => $fields) {
         foreach ($fields AS $field) {
            $error = 0;

            // Make sure we're getting the field from the right place.
            if (isset($this->context->{$field['name']})) {
              $f = $this->context->{$field['name']};
            }
            else {
              $f = $this->{$field['name']};
            }

            // If the field is required and isn't there...error!
            if (isset($field['required']) && $field['required'] == 'true' && empty($f)) {
               $missing[] = $field['name'];
               $error++;
            }

            if (!empty($f)) {
               // If the field has a specific type, and the value is not that type...error!
               switch ($field['type']) {
                  case 'integer':
                     if (intval($f) == 0) {
                        $malformed['int'][] = $field['name'];
                        $error++;
                     }
                  break;
                  case 'string':
                     if (!stval($f)) {
                        $malformed['string'][] = $field['name'];
                        $error++;
                     }
                  break;
               }

	            $v = '';
	            if (isset($validators[$entity])) {
	               $v = $validators[$entity][$field['name']];
	               if (!empty($v)) {
	               	  // If the field has a validator function, run the function against the value.
	               	  // NOTE: the function passed MUST return true or false at the end, or this will fail.
	                  if ($v['function']) {
	                     if (!$v['function']($f)) {
	                        $invalid[] = $field['name'] . ' (given "' . $f . '"; needs to pass "' . $v['function'] . '()" validation)';
	                        $error++;
	                     }
	                  }

	                  // If the field has a regular expression validator, run the value against the regex.
	                  if ($v['regex']) {
	                     if (!preg_match('#^' . $v['regex'] . '$#', $f)) {
	                        $invalid[] = $field['name'] . ' (given "' . $f . '"; needs to match "' . $v['regex'] . '")';
	                        $error++;
	                     }
	                  }
	               }
	            }
	        }

            if ($error == 0) {
               // The group() function allows you to require one field in a group of many.
               foreach ($groups AS $group => $fields) {
               	  // Make sure we understand that fields exist.
                  if (!empty($f) && isset($fields[$entity][$field['name']])) {
                     if (isset($groups[$group][$entity][$field['name']])) {
                        $groups[$group][$entity][$field['name']] = 1;
                     }
                  }
                  // Otherwise, show an error for the field(s) that we're missing.
                  else {
                     if (isset($fields[$entity][$field['name']])) {
                        $oneof[$group][] = $field['name'] . ' (' . $entity . ')';
                     }
                  }
               }
            }
         }
      }

      // Missing fields
      if (!empty($missing)) {
         throw new ApiException("Missing fields: " . implode(', ', $missing));
      }

      // Malformed (e.g. wrong data type) fields
      if (!empty($malformed)) {
         $elist = '';
         foreach ($malformed AS $type => $fields) {
            $elist .= t($errors["$type"], array('!fields' => implode(', ', $fields)));
         }

         throw new ApiException($elist);
      }

      // Invalid (e.g. parsed incorrectly through function or preg)
      if (!empty($invalid)) {
         throw new ApiException("Invalid fields: " . implode(', ', $invalid) . ".");
      }

      // Missing one of a group if fields.
      if (!empty($oneof)) {
         $m = '';
         $ec = 0;

         foreach ($oneof AS $e => $missing) {
            $total = count(dosomething_general_array_vals_multi($groups[$e]));  
            if (count($oneof[$e]) == $total) {
               if ($ec == 0) {
                  $m .= ' one of: ';
               }
               else {
                  $m .= ', and one of: ';
               }

               $m .= implode(', ', $oneof[$e]);
               $ec++;
            }
         }
         
         if ($ec > 0) {
            throw new ApiException('You must have at least' . $m);
         }
      }
   }

   /**
    *  Gets table structure for fields.
    *
    *  @return array
    *	The structure.
    */
   protected function _get_table_structure() {
      $table = $this->doc->getTable();
      $return = array();

      foreach ($table AS $entity => $fields) {
         foreach ($fields AS $field => $info) {
            $return[$field] = $this->{$field};
         }
      }
      
      return $return;
   }
}

/**
 *  Abstracted class for API objects -- please extend this class in your objects.
 *  This sets up the basic functionality for API calls.
 */
abstract class ApiObject extends Api {
   /**
    *  set() parameter for use with create() and update().
    *
    *  @param string $key
    *    The key (e.g. "mobile") to pass into the property.
    *
    *  @param string $val
    *    The value (e.g. "212-867-5309") to pass to the property.
    *
    *  @return object
    *    Returns the current object for method chaining.
    */
   public function set($key, $val) {
   	 $this->add_property($key, $val, false);
   	 return $this;
   }

   /**
    *  context() parameter for use with get(), update() and remove().
    *  This method passes all variables into the "context" sub-object.
    *
    *  @param string $key
    *    The key (e.g. "mobile") to pass into the property.
    *
    *  @param string $val
    *    The value (e.g. "212-867-5309") to pass to the property.
    *
    *  @return object
    *    Returns the current object for method chaining.
    */
   public function context($key, $val) {
   	 $this->add_property($key, $val, true);
   	 return $this;
   }

   /**
    *  Standard CRUD function: create() -- aka "create"
    *  Checks validity of fields and builds the requested object.
    *
    *  @see Api::_check_entities
    *  @see current object build()
    *
    *  @return object
    *    Returns the current object for method chaining.
    */
   public function create() {
   	 $this->_check_entities();

   	 // Create information.
   	 return $this->build();
   }

   /**
    *  Standard CRUD function: get() -- aka "read"
    *  Checks validity of fields and returns the requested data.
    *
    *  @see Api::_check_entities
    *  @see current object fetch()
    *
    *  @return object
    *    Returns the current object for method chaining.
    */
   public function get() {
   	 $this->_check_entities();

   	 // Read information.
   	 return $this->fetch();
   }

   /**
    *  Standard CRUD function: update() -- aka "update"
    *  Checks validity of fields and updates the requested data.
    *
    *  @see Api::_check_entities
    *  @see current object change()
    *
    *  @return object
    *    Returns the current object for method chaining.
    */
   public function update() {
   	 $this->_check_entities();

   	 // Change information.
   	 return $this->change();
   }

   /**
    *  Standard CRUD function: remove() -- aka "delete"
    *  Checks validity of fields and removes the requested data.
    *
    *  @see Api::_check_entities
    *  @see current object fetch()
    *
    *  @return object
    *    Returns the current object for method chaining.
    */
   public function remove() {
   	 $this->_check_entities();

   	 // Delete information.
   	 return $this->delete();
   }

   /**
    *  @method build() Run from "create"
    *  @method fetch() Run from "get"
    *  @method change() Run from "update"
    *  @method delete() Run from "remove"
    */
   abstract public function build();
   abstract public function fetch();
   abstract public function change();
   abstract public function delete();
}

// Api Exception class
class ApiException extends Exception {}

?>