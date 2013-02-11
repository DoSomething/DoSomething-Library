<?php

class user extends ApiObject {
  /**
   *  @var int $uid;
   *    The user's user ID.
   *
   *  @Api\Table("user")
   *  @Api\Column(name="uid", type="int", required="false", context="true")
   *  @Api\Validate(regex="[0-9]+")
   *  @Api\Contextual()
   */
  protected $uid;

  /**
   *  @var varchar(255) $mail;
   *    The user's email address.
   *
   *  @Api\Table("user")
   *  @Api\Column(name="mail", type="varchar", length="255", required="false")
   *  @Api\Validate(function="valid_email_address")
   *  @Api\OneInGroup("social")
   *  @Api\Contextual()
   */
  protected $mail;

  /**
   *  @var varchar(255) $mobile;
   *    The user's mobile phone number.
   *
   *  @Api\Table("profile")
   *  @Api\Column(name="mobile", real="field_user_mobile", type="varchar", length="255", required="false")
   *  @Api\OneInGroup("social")
   *  @Api\Validate(function="dosomething_general_valid_cell")
   *  @Api\Contextual()
   */
  protected $mobile;

  /**
   *  @var varchar(255) $name;
   *    The user's first name.
   *
   *  @Api\Table("profile")
   *  @Api\Column(name="name", real="field_user_first_name", type="varchar", length="255", required="false")
   *  @Api\Validate(regex="[A-Za-z\'\- ]+")
   */
  protected $name;

  /**
   *  @var varchar(255) $last_name;
   *    The user's last name.
   *
   *  @Api\Table("profile")
   *  @Api\Column(name="last_name", real="field_user_last_name", type="varchar", length="255", required="false")
   *  @Api\Validate(regex="[A-Za-z\'\- ]+")
   */
  protected $last_name;

  public function __construct() { }

  /**
   *  Gets the requested user from context.
   */
  public function _get_user() {
  	if (empty($this->context->uid) && empty($this->context->mail) && empty($this->context->mobile)) {
  	  throw new ApiException('Only uid, email and mobile are context-able fields.');
  	}
  	// If we have a user ID, try loading a user from that.
  	if ($this->context->uid) {
  	  global $user;
  	  // Don't bother loading the user if it's you -- we already have that!
  	  if ($user->uid !== $this->context->uid) {
  	  	$user = user_load($this->context->uid);
 	  }
 	}
 	else {
 	  // Otherwise, if we have an email or cell, we can load their information from that.
 	  $ec = ($this->context->mail ? $this->context->mail : $this->context->mobile);
	  $user = dosomething_general_load_user_by_mail_or_cell($ec);
	}

	// Returns the user object.
	return $user;
  }

  /**
   *  Standard CRUD function: create
   *  Gets user information from the set() method and creates a user and profile for them.
   *
   *  To create a user, you MUST pass EITHER:
   *	* Email
   *    * Cell
   *
   *  If neither are passed, an exception will be thrown!
   *
   *  If no password is supplied, a random password will be created for the user.
   *
   *  @return object
   *	The new user object, including their profile information.
   */
  public function build() {
  	// Start the account class.
    $account = new stdClass;

    // If we don't have a name, default to "Guest user"
    if (!empty($this->name)) {
	  $this->name = t('Guest user');
    }

    $suffix = 0;
    $account->name = $this->name;
    // If we can find users with that exact name, append a number to the end.
    while (user_load_by_name($account->name)) {
       $suffix++;
       $account->name = $this->name . '-' . $suffix;
    }

    // Create a random password for the user.
    $pass = strtoupper(user_password(6));
    require_once('includes/password.inc');
    $hashed_pass = user_hash_password($pass);
    $account->pass = $hashed_pass;

    // Set the email address (or "fake" email for mobile)
    $account->mail = ($this->mail ? $this->mail : $mobile . '@mobile');
    // The user account is activated.
    $account->status = 1;

    // Save the user.
    $account = user_save($account);

    // Load a profile object for the user.
    $profile_values = array('type' => 'main');
    $profile = profile2_create($profile_values);
    $profile->uid = $account->uid;
        
    // If we have the user's phone number, set that.
    if (!empty($this->mobile)) {
	   $profile->field_user_mobile[LANGUAGE_NONE][0]['value'] = $this->mobile;
	}
	// If they have a real first name, set that.
	if ($this->name != 'Guest user') {
	   $profile->field_user_first_name[LANGUAGE_NONE][0]['value'] = $this->name;
	}

	// Try and save the profile and set a message that we did so...
    try {
      profile2_save($profile);
      watchdog('Api', t('A user was successfully created with the email / cell ' . ($this->mobile ? $this->mobile : $this->mail)));
    }
    // ...or throw an exception saying we failed.
    catch (Exception $e) {
      throw new ApiException(t('Sorry, there was a problem creating the account.'));
    }

    // Set the profile object as a sub-object of user.
    $account->profile = $profile;

    // Return the user object.
    return $account;
  }

  /**
   *  Standard CRUD functions: read
   *  Gets user information from context, and returns the user and profile object.
   *
   *  @return object
   *	Returns the user object, including their profile information.
   */
  public function fetch() {
  	// Gets user information.
  	$user = $this->_get_user();

  	// Gets profile information.
	$profile = profile2_load_by_user($user, 'main');

	// Set profile as a sub-object of user.
	$user->profile = $profile;

	// Return the final user object.
	return $user;
  }

  /**
   *  Standard CRUD functions: update
   *  Gets a user account from context and update fields where appropriate.
   */
  public function change() {
  	// Try loading the user.
  	$user = $this->_get_user();

 	// If no user ID, forget it.
  	if (!$user->uid) {
  		return;
  	}

  	// Get the table structure.
  	$t = $this->_get_table_structure();
  	// Get the doc structure.
  	$d = $this->doc();
  	$profile = profile2_load_by_user($user, 'main');

  	$pc = $uc = 0;
  	foreach ($t AS $key => $value) {
  	  if ($value) {
  	  	$table = $d->getContainingTable($key);
  	  	$alias = $d->getAlias($key);
  	  	if ($table == 'profile') {
  	  	  $profile->{$alias}[LANGUAGE_NONE][0]['value'] = $value;
  	  	  $pc++;
  	  	}
  	  	else if ($table == 'user') {
  	  	  $user->{$alias} = $value;
  	  	  $uc++;
  	  	}
  	  }
  	}

  	if ($pc > 0) {
  	  $profile->save();
  	  $user->profile = $profile;
  	}
  	if ($uc > 0) {
  	  user_save($user);
  	}

  	return $user;
  }

  public function delete() {
  	$user = $this->_get_user();

	if (!empty($user->uid)) {
	  user_delete($user->uid);
	}

	return true;
  }
}