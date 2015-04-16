<?php
// vim: set ai ts=4 sw=4 ft=php:
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright 2013 Schmooze Com Inc.
//
namespace FreePBX\modules\Userman\Auth;

class Freepbx extends Auth {
	private $contacts = array();

	/**
	* Get All Users
	*
	* Get a List of all User Manager users and their data
	*
	* @return array
	*/
	public function getAllUsers() {
		return parent::getAllUsers('freepbx');
	}

	/**
	* Get All Groups
	*
	* Get a List of all User Manager users and their data
	*
	* @return array
	*/
	public function getAllGroups() {
		return parent::getAllGroups('freepbx');
	}

	/**
	* Add a user to User Manager
	*
	* This adds a new user to user manager
	*
	* @param string $username The username
	* @param string $password The user Password
	* @param string $default The default user extension, there is an integrity constraint here so there can't be duplicates
	* @param string $description a short description of this account
	* @param array $extraData A hash of extra data to provide about this account (work, email, telephone, etc)
	* @param bool $encrypt Whether to encrypt the password or not. If this is false the system will still assume its hashed as sha1, so this is only useful if importing accounts with previous sha1 passwords
	* @return array
	*/
	public function addUser($username, $password, $default='none', $description=null, $extraData=array(), $encrypt = true) {
		$request = $_REQUEST;
		$display = !empty($request['display']) ? $request['display'] : "";
		$description = !empty($description) ? $description : null;
		if(empty($username) || empty($password)) {
			return array("status" => false, "type" => "danger", "message" => _("Username/Password Can Not Be Blank!"));
		}
		if($this->getUserByUsername($username)) {
			return array("status" => false, "type" => "danger", "message" => sprintf(_("User '%s' Already Exists"),$username));
		}
		$sql = "INSERT INTO ".$this->userTable." (`username`,`auth`,`password`,`description`,`default_extension`) VALUES (:username,'freepbx',:password,:description,:default_extension)";
		$sth = $this->db->prepare($sql);
		$password = ($encrypt) ? sha1($password) : $password;
		try {
			$sth->execute(array(':username' => $username, ':password' => $password, ':description' => $description, ':default_extension' => $default));
		} catch (\Exception $e) {
			return array("status" => false, "type" => "danger", "message" => $e->getMessage());
		}

		$id = $this->db->lastInsertId();
		$this->updateUserData($id,$extraData);
		return array("status" => true, "type" => "success", "message" => _("User Successfully Added"), "id" => $id);
	}

	public function addGroup($groupname, $description=null, $users=array()) {
		$sql = "INSERT INTO ".$this->groupTable." (`groupname`,`description`,`users`) VALUES (:groupname,:description,:users)";
		$sth = $this->db->prepare($sql);
		try {
			$sth->execute(array(':groupname' => $groupname, ':description' => $description, ':users' => json_encode($users)));
		} catch (\Exception $e) {
			return array("status" => false, "type" => "danger", "message" => $e->getMessage());
		}

		$id = $this->db->lastInsertId();
		return array("status" => true, "type" => "success", "message" => _("Group Successfully Added"), "id" => $id);
	}

	/**
	* Update a User in User Manager
	*
	* This Updates a User in User Manager
	*
	* @param int $uid The User ID
	* @param string $username The username
	* @param string $password The user Password
	* @param string $default The default user extension, there is an integrity constraint here so there can't be duplicates
	* @param string $description a short description of this account
	* @param array $extraData A hash of extra data to provide about this account (work, email, telephone, etc)
	* @param string $password The updated password, if null then password isn't updated
	* @return array
	*/
	public function updateUser($uid, $prevUsername, $username, $default='none', $description=null, $extraData=array(), $password=null) {
		$request = $_REQUEST;
		$display = !empty($request['display']) ? $request['display'] : "";
		$description = !empty($description) ? $description : null;
		$user = $this->getUserByUsername($prevUsername);
		if(!$user || empty($user)) {
			return array("status" => false, "type" => "danger", "message" => sprintf(_("User '%s' Does Not Exist"),$user));
		}
		if(isset($password) && (sha1($password) != $user['password'])) {
			$sql = "UPDATE ".$this->userTable." SET `username` = :username, `password` = :password, `description` = :description, `default_extension` = :default_extension WHERE `id` = :uid";
			$sth = $this->db->prepare($sql);
			try {
				$sth->execute(array(':username' => $username, ':uid' => $uid, ':description' => $description, ':password' => sha1($password), ':default_extension' => $default));
			} catch (\Exception $e) {
				return array("status" => false, "type" => "danger", "message" => $e->getMessage());
			}
		} elseif(($prevUsername != $username) || ($user['description'] != $description) || $user['default_extension'] != $default) {
			if(($prevUsername != $username) && $this->getUserByUsername($username)) {
				return array("status" => false, "type" => "danger", "message" => sprintf(_("User '%s' Already Exists"),$username));
			}
			$sql = "UPDATE ".$this->userTable." SET `username` = :username, `description` = :description, `default_extension` = :default_extension WHERE `id` = :uid";
			$sth = $this->db->prepare($sql);
			try {
				$sth->execute(array(':username' => $username, ':uid' => $uid, ':description' => $description, ':default_extension' => $default));
			} catch (\Exception $e) {
				return array("status" => false, "type" => "danger", "message" => $e->getMessage());
			}
		}
		$message = _("Updated User");

		if(!$this->updateUserData($user['id'],$extraData)) {
			return array("status" => false, "type" => "danger", "message" => _("An Unknown error occured while trying to update user data"));
		}
		return array("status" => true, "type" => "success", "message" => $message, "id" => $user['id']);
	}

	/**
	* Update Group
	* @param string $prevGroupname The group's previous name
	* @param string $groupname     The Groupname
	* @param string $description   The group description
	* @param array  $users         Array of users in this Group
	*/
	public function updateGroup($gid, $prevGroupname, $groupname, $description=null, $users=array()) {
		$group = $this->getGroupByUsername($prevGroupname);
		if(!$group || empty($group)) {
			return array("status" => false, "type" => "danger", "message" => sprintf(_("Group '%s' Does Not Exist"),$group));
		}
		$sql = "UPDATE ".$this->groupTable." SET `groupname` = :groupname, `description` = :description, `users` = :users WHERE `id` = :gid";
		$sth = $this->db->prepare($sql);
		try {
			$sth->execute(array(':groupname' => $groupname, ':gid' => $gid, ':description' => $description, ':users' => json_encode($users)));
		} catch (\Exception $e) {
			return array("status" => false, "type" => "danger", "message" => $e->getMessage());
		}
		$message = _("Updated Group");
		return array("status" => true, "type" => "success", "message" => $message, "id" => $gid);
	}

	/**
	* Check Credentials against username with a passworded sha
	* @param {string} $username      The username
	* @param {string} $password_sha1 The sha
	*/
	public function checkCredentials($username, $password) {
		$sql = "SELECT id, password FROM ".$this->userTable." WHERE username = :username";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':username' => $username));
		$result = $sth->fetch(\PDO::FETCH_ASSOC);
		if(!empty($result) && (sha1($password) === $result['password'])) {
			return $result['id'];
		}
		return false;
	}
}
