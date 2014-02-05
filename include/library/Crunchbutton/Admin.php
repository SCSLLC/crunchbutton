<?php

class Crunchbutton_Admin extends Cana_Table {
	public static function login($login) {
		return Crunchbutton_Admin::q('select * from admin where login="'.c::db()->escape($login).'" limit 1')->get(0);
	}
	
	public function publicExports() {
		return [
			'name' => $this->name,
			'id_admin' => $this->id_admin
		];
	}

	public function checkIfThePhoneBelongsToAnAdmin( $phone ){
		$phone = str_replace( '-', '', $phone );
		$phone = str_replace( ' ', '', $phone );
		return Crunchbutton_Admin::q( "SELECT * FROM admin WHERE REPLACE( phone, '-', '' ) = '$phone' OR REPLACE( txt, '-', '' ) = '$phone' OR REPLACE( testphone, '-', '' ) = '$phone'" );
	}
	
	public function timezone() {
		if (!isset($this->_timezone)) {
			$this->_timezone = new DateTimeZone($this->timezone);
		}
		return $this->_timezone;
	}

	public function getAdminsWithNotifications(){
		return Crunchbutton_Admin::q( 'SELECT DISTINCT( a.id_admin ), a.name FROM admin a INNER JOIN admin_notification an ON an.id_admin = a.id_admin ORDER BY a.name ASC' );
	}

	public function activeNotifications(){
		if( !$this->_activeNotifications ){
			if( $this->id_admin ){
				$this->_activeNotifications = Crunchbutton_Admin_Notification::q( "SELECT * FROM admin_notification WHERE id_admin = {$this->id_admin} AND active = 1" );
			}
		}
		return $this->_activeNotifications;
	}

	public function isWorking(){
		$now = new DateTime( 'now', $this->timezone() );
		$now = $now->format( 'YmdHi' );
		$hours = Admin_Hour::q( "SELECT * FROM admin_hour WHERE 
															id_admin = {$this->id_admin} AND
 															DATE_FORMAT( date_start, '%Y%m%d%H%i' ) <= {$now} AND 
  														DATE_FORMAT( date_end, '%Y%m%d%H%i' ) >= {$now} ");
		return ( $hours->count() > 0 );
	}

	public function getNotifications( $oderby = 'active DESC, id_admin_notification DESC' ){
		return Crunchbutton_Admin_Notification::q( "SELECT * FROM admin_notification WHERE id_admin = {$this->id_admin} ORDER BY {$oderby}" );
	}

	public function getAllPermissionsName(){
		return c::db()->get( "SELECT DISTINCT( ap.permission ) FROM admin_permission ap WHERE ap.id_admin = {$this->id_admin} OR ap.id_group IN ( SELECT id_group FROM admin_group WHERE id_admin = {$this->id_admin} )" );
	}

	public function getRestaurantsUserHasCurationPermission(){
		return Crunchbutton_Restaurant::restaurantsUserHasCurationPermission();
	}

	public function getRestaurantsUserHasPermission(){
		return Crunchbutton_Restaurant::restaurantsUserHasPermission();
	}

	public function getRestaurantsUserHasPermissionToSeeTheirMetrics(){
		return Crunchbutton_Chart::restaurantsUserHasPermissionToSeeTheirMetrics();
	}

	public function getRestaurantsUserHasPermissionToSeeTheirOrders(){
		return Crunchbutton_Order::restaurantsUserHasPermissionToSeeTheirOrders();
	}

	public function getRestaurantsUserHasPermissionToSeeTheirTickets(){
		return Crunchbutton_Support::restaurantsUserHasPermissionToSeeTheirTickets();
	}

	public function getPermissionsByGroups(){
		return c::db()->get( "SELECT ap.*, g.name as group_name FROM admin_permission ap
										INNER JOIN admin_group ag ON ap.id_group = ap.id_group and ag.id_admin = {$this->id_admin}
										INNER JOIN `group` g ON g.id_group = ag.id_group ORDER BY group_name, permission ASC" );
	}
	
	public function getPermissionsByUser(){
		return c::db()->get( "SELECT * FROM admin_permission WHERE id_admin = {$this->id_admin}" );
	}

	public function permission() {
		if (!isset($this->_permission)) {
			$this->_permission = new Crunchbutton_Acl_Admin($this);
		}
		return $this->_permission;
	}
	
	public function restaurants() {
		if (!isset($this->_restaurants)) {

			if (c::admin()->permission()->check(['global','restaurants-all'])) {
				$restaurants = Restaurant::q('select * from restaurant order by name');

			} else {
				$restaurants = [];
				foreach ($this->permission()->_userPermission as $key => $perm) {
					$find = '/^RESTAURANT-([0-9]+)$/i';
					if (preg_match($find,$key)) {

						$key = preg_replace($find,'\\1',$key);
						$restaurants[$key] = Restaurant::o($key);
					}
				}

			}

			$this->_restaurants = $restaurants;
		}
		return $this->_restaurants;
	}

	public function communities() {
		if (!isset($this->_communities)) {
			$communities = [];

			$q = '
				SELECT COUNT(*) restaurants, community
				FROM restaurant
				WHERE community IS NOT NULL
				AND community != ""
			';

			if (!c::admin()->permission()->check(['global','restaurants-all']) && count($this->restaurants())) {

				foreach ($this->restaurants() as $restaurant) {
					$qa .= ($qa ? ' OR ' : '').' id_restaurant='.$restaurant->id_restaurant.' ';
				}
				$q.= ' AND ( '.$qa.' ) ';

			} elseif (!c::admin()->permission()->check(['global','restaurants-all'])) {
				$q = null;
			}
			
			if ($q) {
				$q .= ' GROUP BY community';
				$communities = c::db()->get($q);
			}
			
			$this->_communities = $communities;

		}

		return $this->_communities;
	}

	public function loginExists( $login ){
		if( trim( $login ) != '' ){
			return Crunchbutton_Admin::login( $login );
		}
		return false;
	}

	public function groups(){
		if( !$this->_groups ){
			$this->_groups = Crunchbutton_Group::q( "SELECT g.* FROM `group` g INNER JOIN admin_group ag ON ag.id_group = g.id_group AND ag.id_admin = {$this->id_admin} ORDER BY name ASC" );
		}
		return $this->_groups;
	}

	public function removeGroups(){
		Cana::db()->query( "DELETE FROM `admin_group` WHERE id_admin = {$this->id_admin}" );
	}

	public function permissions(){
		if( !$this->_permissions ){
			$this->_permissions = c::db()->get( "SELECT * FROM admin_permission WHERE id_admin = {$this->id_admin}" );	
		}
		return $this->_permissions;
	}

	public function hasPermission( $permission, $useRegex = false ){
		$permissions = $this->permissions();
		foreach( $permissions as $_permission ){
			if( $_permission->permission == $permission && $_permission->allow == 1 ){
				return true;
			}
			if( $useRegex ){
				if( preg_match( $permission, $_permission->permission )  && $_permission->allow == 1 ){
					return true;
				}
			}
		}
		return false;
	}

	public function removePermissions(){
		c::db()->query( "DELETE FROM admin_permission WHERE id_admin = {$this->id_admin}" );
	}

	public function addPermissions( $permissions ){

		if( $permissions && is_array( $permissions ) ){
			foreach( $permissions as $key => $val ){
				if( !$this->hasPermission( $key ) ){
					$_permission = new Crunchbutton_Admin_Permission();
					$_permission->id_admin = $this->id_admin;
					$_permission->permission = trim( $key );
					$_permission->allow = 1;
					$_permission->save();
					// reset the permissions
					$this->_permissions = false;
					$dependencies = $_permission->getDependency( $key );
					if( $dependencies ){
						foreach( $dependencies as $dependency ){
							$this->addPermissions( array( $dependency => 1 ) );
						}
					}

				}
			}
		}
	}


	public function hasGroup( $id_group ){
		$groups = $this->groups();
		foreach( $groups as $group ){
			if( $id_group == $group->id_group ){
				return true;
			}
		}
		return false;
	}

	public function groups2str(){
		$groups = $this->groups();
		$str = '';
		$commas = '';
		foreach( $groups as $group ){
			$str .= $commas . $group->name;
			$commas = ', ';
		}
		return $str;
	}
	
	public function makePass($pass) {
		return sha1(c::crypt()->encrypt($pass));
	}

	public static function find($search = []) {

		$query = 'SELECT `admin`.* FROM `admin` WHERE id_admin IS NOT NULL ';
		
		if ( $search[ 'name' ] ) {
			$query .= " AND name LIKE '%{$search[ 'name' ]}%' ";
		}

		$query .= " ORDER BY name DESC";

		$admins = self::q($query);
		return $admins;
	}

	public function __construct($id = null) {
		parent::__construct();
		$this
			->table('admin')
			->idVar('id_admin')
			->load($id);
	}
}