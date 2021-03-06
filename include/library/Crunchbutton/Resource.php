<?php

class Crunchbutton_Resource extends Cana_Table {
	public function __construct($id = null) {
		parent::__construct();
		$this
			->table('resource')
			->idVar('id_resource')
			->load($id);
	}
	public function www(){
		return Util::uploadWWW() . 'resource/';
	}

	// shouldnt need this in the future once we only allow uploads after the resource is in the db
	public static function toS3($path, $name) {

		$res = new Crunchbutton_Resource;
		$r = $res->localToS3($path, $name);

		return $r;
	}

	public function localToS3($path = null, $name = null) {
		if (!$path) {
			$path = $this->path().$this->file;
		}

		if (!$name) {
			$name = $this->s3Base();
		}

		if (!$path || !$name) {
			return false;
		}

		$upload = new Crunchbutton_Upload([
			'file' => $path,
			'resource' => $name,
			'bucket' => c::config()->s3->buckets->{'resource'}->name
		]);

		$this->file = $name;
		$this->save();

		return $upload->upload();
	}

	// auto rename with the proper format
	public function rename() {
		$r = S3::copyObject(
			c::config()->s3->buckets->{'resource'}->name, $this->file,
			c::config()->s3->buckets->{'resource'}->name, $this->s3Base(),
		S3::ACL_PUBLIC_READ);

		if ($r) {
			$r = S3::deleteObject(c::config()->s3->buckets->{'resource'}->name, $this->file);
		}

		if ($r) {
			$this->file = $this->s3Base();
			$this->save();
		}

		return $r ? true : false;
	}

	public function s3Base($file = null, $name = null) {
		if (!$file) {
			$file = $this->file;
		}
		if (!$name) {
			$name = $this->name;
		}
		$pos = strrpos($file, '.');
		$ext = substr($file, $pos+1);

		$name = strtolower($name);
		$name = preg_replace('/[^a-z0-9]/i','-',$name);
		$name = preg_replace('/\-{2,}/','-', $name);
		$name = trim($name, '-');

		return $this->id_resource.'-'.$name.'.'.$ext;
	}

	public function s3File() {
		return c::config()->s3->buckets->{'resource'}->cache.'/'.$this->file;
	}

	public function download_url(){
		return Util::url() . '/api/resource/download/' . $this->id_resource . '/'.$this->file;
	}

	public function path(){
		$path = Util::uploadPath() . '/resource/';
		if ( !file_exists( $path ) ) {
			@mkdir( $path, 0777, true );
		}
		return $path;
	}

	public function doc_path(){
		return Util::uploadPath() . '/resource/' . $this->file;
	}

	public function url(){
		return $this->www() . $this->file;
	}

	public function date(){
		if( !$this->_date ){
			$this->_date = new DateTime( $this->datetime, new DateTimeZone( c::config()->timezone ) );
		}
		return $this->_date;
	}

	public function communities( $name = false ){
		if( $name ){
			return Crunchbutton_Community::q( 'SELECT community.name, community.id_community FROM resource_community INNER JOIN community ON community.id_community = resource_community.id_community WHERE id_resource = ?', [$this->id_resource]);
		} else {
			return Crunchbutton_Resource_Community::q( 'SELECT * FROM resource_community WHERE id_resource = ?', [$this->id_resource]);
		}
	}

	public static function byCommunity( $id_community, $type = false ){

		$type = ( $type ) ? ' AND ' . $type . ' = true' : '';

		if( $id_community == 'all' ){
			return Crunchbutton_Resource::q( 'SELECT cr.* FROM resource cr WHERE cr.all = true AND active = true ' . $type );
		} else {
			return Crunchbutton_Resource::q( 'SELECT cr.* FROM resource cr INNER JOIN resource_community crc ON cr.id_resource = crc.id_resource AND crc.id_community = ?  AND active = true ' . $type, [$id_community]);
		}
	}

	public function exports(){
		$out = $this->properties();
		$communities = $this->communities();
		if( $communities ){
			$out[ 'communities' ] = [];
			foreach( $communities as $community ){
				$out[ 'communities' ][] = $community->id_community;
			}
		}
		$out[ 'all' ] = intval( $out[ 'all' ] ) == 0 ? false: true;
		$out[ 'page' ] = intval( $out[ 'page' ] ) == 0 ? false: true;
		$out[ 'side' ] = intval( $out[ 'side' ] ) == 0 ? false: true;
		$out[ 'side' ] = intval( $out[ 'side' ] ) == 0 ? false: true;
		$out[ 'order_page' ] = intval( $out[ 'order_page' ] ) == 0 ? false: true;
		$out[ 'active' ] = intval( $out[ 'active' ] ) == 0 ? false: true;
		$out[ 'path' ] = $this->download_url();
		return $out;
	}

	public function admin(){
		if( $this->id_admin ){
			if( !$this->_admin ){
				$this->_admin = Admin::o( $this->id_admin );
			}
			return $this->_admin;
		}
		return false;
	}
}
