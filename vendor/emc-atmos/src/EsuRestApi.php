<?php
// Copyright � 2008, EMC Corporation.
// Redistribution and use in source and binary forms, with or without modification, 
// are permitted provided that the following conditions are met:
//
//     + Redistributions of source code must retain the above copyright notice, 
//       this list of conditions and the following disclaimer.
//     + Redistributions in binary form must reproduce the above copyright 
//       notice, this list of conditions and the following disclaimer in the 
//       documentation and/or other materials provided with the distribution.
//     + The name of EMC Corporation may not be used to endorse or promote 
//       products derived from this software without specific prior written 
//       permission.
//
//      THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 
//      "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED 
//      TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR 
//      PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS 
//      BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR 
//      CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF 
//      SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS 
//      INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
//      CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) 
//      ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
//      POSSIBILITY OF SUCH DAMAGE.
 
require_once 'EsuInterface.php';
require_once 'EsuObjects.php';
require_once 'PEAR.php';
require_once "Net/URL2.php";
require_once "HTTP/Request2.php";

/**
 * Implements the REST version of the ESU API.  This class uses the HTTP_Request
 * library to perform object and metadata calls against the ESU server.  All of
 * the methods that communicate with the server are atomic and stateless so
 * the object can be used safely in a multithreaded environment.
 */
class EsuRestApi implements EsuApi {
	private $host;
	private $port;
	private $uid;
	private $secret;
	private $debug = false;
	private $timeout = null;
	private $followRedirects = false;
	private $context = "/rest";
	private $proto;
	private static $ID_EXTRACTOR = "/[0-9a-zA-Z]+/objects/([0-9a-f]{44})";
	
	/**
	 * Creates a new EsuRestApi object.
	 * @param string $host the hostname or IP address of the ESU server
	 * @param integer $port the port on the server to communicate with.  Generally
	 * this is 80 for HTTP and 443 for HTTPS.
	 * @param string $uid the username to use when connecting to the server
	 * @param string $secret the Base64 encoded shared secret to use to sign
	 * requests to the server.
	 */
	public function __construct( $host, $port, $uid, $secret ) {
		$this->secret = base64_decode( $secret );
		$this->host = $host;
		$this->uid = $uid;
		$this->port = $port;
		
		if( $port == 443 ) {
			$this->proto = "https";
		} else {
			$this->proto = "http";
		}
	}
	
	/**
	 * Creates a new object in the cloud.
	 * @param Acl $acl Access control list for the new object. Optional, default
	 * is NULL.
	 * @param MetadataList $metadata Metadata list for the new object.  Optional,
	 * default is NULL.
	 * @param string $data The initial contents of the object.  May be appended
	 * to later.
	 * @param string $mimeType the MIME type of the content.  Optional, 
	 * may be null.  If $data is non-null and $mimeType is null, the MIME
	 * type will default to application/octet-stream.
	 * @param Checksum $checksum if not null, use the Checksum object to compute
     * the checksum for the create object request.  If appending
     * to the object with subsequent requests, use the same
     * checksum object for each request.
	 * @return ObjectId Identifier of the newly created object.
	 * @throws EsuException if the request fails.
	 */
	public function createObject( $acl = NULL, $metadata = NULL, $data = NULL, 
		$mimeType = NULL, $checksum = NULL ) {

		// Create the request
		$resource = $this->context . "/objects";
		$req = $this->buildRequest( $resource, null );
		$response = null;
		
		// build the headers
		$headers = array();
		
		// Figure out the mimetype
		if( $mimeType == null && $data != null ) {
			$mimeType = "application/octet-stream";
		}
		$headers["Content-Type"] = $mimeType;
		$headers["x-emc-uid"] = $this->uid;
		
		// Process metadata
		if( $metadata != null ) {
			$this->processMetadata( $metadata, $headers );
		}

		if ( isset( $headers["x-emc-meta"] ) ) {
		  $this->trace( "meta " . $headers["x-emc-meta"] );
		}

		// Add acl
		if( $acl != null ) {
			$this->processAcl( $acl, $headers );
		}
		
		// Process data
		if( $data != null ) {
			$req->setBody( $data );
		} else {
			$req->setBody( "" );
		}
		
		// Process checksum
		if( $checksum != null ) {
			if( $data != null ) {
				$checksum->update( $data );
			} 
			$headers["x-emc-wschecksum"] = "".$checksum;
		}
		
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Sign request
		$this->signRequest( $req, "POST", $resource, $headers, $data );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}
				
		// The new object ID is returned in the location response header
		$location = $response->getHeader( "location" );
		$pos = array();
		// Parse the value out of the URL
		ereg( EsuRestApi::$ID_EXTRACTOR, $location, $pos );
		$this->trace( "Location: " . $location );
		$this->trace( "regex: " . EsuRestApi::$ID_EXTRACTOR );
		$this->trace( "pos 1 " . $pos[1] );
		
		return new ObjectId( $pos[1] );
	}
	
	/**
	 * Creates a new object in the cloud.
	 * @param ObjectPath $path the path to the file to create.
	 * @param Acl $acl Access control list for the new object. Optional, default
	 * is NULL.
	 * @param MetadataList $metadata Metadata list for the new object.  Optional,
	 * default is NULL.
	 * @param string $data The initial contents of the object.  May be appended
	 * to later. Optional, default is NULL (no content).
	 * @param string $mimeType the MIME type of the content.  Optional, 
	 * may be null.  If $data is non-null and $mimeType is null, the MIME
	 * type will default to application/octet-stream.
	 * @param Checksum $checksum if not null, use the Checksum object to compute
     * the checksum for the create object request.  If appending
     * to the object with subsequent requests, use the same
     * checksum object for each request.
	 * @return ObjectId The ObjectId of the newly created object
	 * @throws EsuException if the request fails.
	 */
	public function createObjectOnPath( $path, $acl = null, $metadata = null, 
		$data = null, $mimeType = null, $checksum = null ) {
			
		// Create the request
		$resource = $this->getResourcePath( $this->context, $path );
		$req = $this->buildRequest( $resource, null );
		
		
		// build the headers
		$headers = array();
		
		// Figure out the mimetype
		if( $mimeType == null && $data != null ) {
			$mimeType = "application/octet-stream";
		}
		$headers["Content-Type"] = $mimeType;
		$headers["x-emc-uid"] = $this->uid;
		
		// Process metadata
		if( $metadata != null ) {
			$this->processMetadata( $metadata, $headers );
		}
		
		if ( isset( $headers["x-emc-meta"] ) ) {
		  $this->trace( "meta " . $headers["x-emc-meta"] );
		}
		
		// Add acl
		if( $acl != null ) {
			$this->processAcl( $acl, $headers );
		}
		
		// Process data
		if( $data != null ) {
			$req->setBody( $data );
		} else {
			$req->setBody( "" );
		}
		
		// Process checksum
		if( $checksum != null ) {
			if( $data != null ) {
				$checksum->update( $data );
			} 
			$headers["x-emc-wschecksum"] = "".$checksum;
		}
		
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Sign request
		$this->signRequest( $req, "POST", $resource, $headers, $data );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

	
		// The new object ID is returned in the location response header
		$location = $response->getHeader( "location" );
		$pos = array();
		// Parse the value out of the URL
		ereg( EsuRestApi::$ID_EXTRACTOR, $location, $pos );
		$this->trace( "Location: " . $location );
		$this->trace( "regex: " . EsuRestApi::$ID_EXTRACTOR );
		$this->trace( "pos 1 " . $pos[1] );
		
		return new ObjectId( $pos[1] );
	}
	
		
	/**
	 * Deletes an object from the cloud.
	 * @param Identifier $id the identifier of the object to delete.
	 */
	public function deleteObject( $id ) {
		$resource = $this->getResourcePath( $this->context, $id );
		$req = $this->buildRequest( $resource, null );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		// Sign request
		$this->signRequest( $req, "DELETE", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

		
	}
	
	/**
	 * Fetches the user metadata for the object.
	 * @param Identifier $id the identifier of the object whose user metadata
	 * to fetch.
	 * @param MetadataTags $tags A list of metadata tags to fetch.  Optional.
	 * Default value is null to fetch all metadata.
	 * @return MetadataList The list of metadata for the object.
	 */
	public function getUserMetadata( $id, $tags = null ) {
		$resource = $this->getResourcePath( $this->context, $id );
		$req = $this->buildRequest( $resource, "metadata/user" );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Add tags if needed
		if( $tags != null ) {
			$this->processTags( $tags, $headers );
		}
		
		// Sign request
		$this->signRequest( $req, "GET", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}
	
		// Parse return headers.  Regular metadata is in x-emc-meta and
		// listable metadata is in x-emc-listable-meta
		$meta = new MetadataList();
		$this->readMetadata( $meta, $response->getHeader( "x-emc-meta" ), false );		
		$this->readMetadata( $meta, $response->getHeader( "x-emc-listable-meta" ), true );
		
		return $meta;
	}
	
	/**
	 * Reads an object's content.
	 * @param Identifier $id the identifier of the object whose content to read.
	 * @param Extent $extent the portion of the object data to read.  Optional.
	 * Default is null to read the entire object.
	 * @param Checksum $checksum if not null, the given checksum object will be used
     * to verify checksums during the read operation.  Note that only erasure coded objects 
     * will return checksums *and* if you're reading the object in chunks, you'll have to 
     * read the data back sequentially to keep the checksum consistent.  If the read operation 
     * does not return a checksum from the server, the checksum operation will be skipped.
	 * @return string the object data read.
	 */
	public function readObject( $id, $extent = null, $checksum = null ) {
		$resource = $this->getResourcePath( $this->context, $id );
		$req = $this->buildRequest( $resource, null );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Add extent if needed
		if( $extent != null && $extent != Extent::$ALL_CONTENT ) {
			// Need to do bcmath because the value might be > 4GB
			$end = bcadd( $extent->getOffset(), $extent->getSize() );
			$end = bcsub( $end, 1 );
			
			$headers["Range"] = "Bytes=" . $extent->getOffset() . "-" . $end; 
		}
		
		// Sign request
		$this->signRequest( $req, "GET", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}
			
		// The requested content is in the response body.
		$body = &$response->getBody();
		if( $checksum ) {
			// Update checksum
			$checksum->setExpectedValue( $response->getHeader( "x-emc-wschecksum" ) );
			$checksum->update( $body );
		}
		
		return $body;
	}
	
	/**
	 * Returns an object's ACL
	 * @param Identifier $id the identifier of the object whose ACL to read
	 * @return Acl the object's ACL
	 */
	public function getAcl( $id ) {
		$resource = $this->getResourcePath( $this->context, $id );
		$req = $this->buildRequest( $resource, "acl" );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Sign request
		$this->signRequest( $req, "GET", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

		// Parse return headers.  User grants are in x-emc-useracl and
		// group grants are in x-emc-groupacl
		$acl = new Acl();
		$this->readAcl( $acl, $response->getHeader( "x-emc-useracl" ), Grantee::USER );		
		$this->readAcl( $acl, $response->getHeader( "x-emc-groupacl" ), Grantee::GROUP );
		
		return $acl;
		
	}
	
	/**
	 * Deletes metadata items from an object.
	 * @param Identifier $id the identifier of the object whose metadata to 
	 * delete.
	 * @param MetadataTags $tags the list of metadata tags to delete.
	 */
	public function deleteUserMetadata( $id, $tags ) {
		$resource = $this->getResourcePath( $this->context, $id );
		$req = $this->buildRequest( $resource, "metadata/user" );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Add tags if needed
		if( $tags != null ) {
			$this->processTags( $tags, $headers );
		} else {
			throw new EsuException( "MetadataTags cannot be null." );
		}
		
		// Sign request
		$this->signRequest( $req, "DELETE", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

	}
	
	/**
	 * Lists the versions of an object.
	 * @param Identifier $id the object whose versions to list.
	 * @return array The list of versions of the object.  If the object does
	 * not have any versions, the array will be empty.
	 */
	public function listVersions( $id ) {
		$versions = array();
		
		$resource = $this->getResourcePath( $this->context, $id );
		$req = $this->buildRequest( $resource, "versions" );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Sign request
		$this->signRequest( $req, "GET", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}
	
		// Parse the returned objects.  They are passed in the response
		// body in an XML format.
		$this->parseVersionList( $response->getBody(), $versions );
		
		return $versions;
	}
	
	/**
	 * Creates a new immutable version of an object.
	 * @param Identifier $id the object to version
	 * @return ObjectId the id of the newly created version
	 */
	public function versionObject( $id ) {
		$resource = $this->getResourcePath( $this->context, $id );
		$req = $this->buildRequest( $resource, "versions" );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Sign request
		$this->signRequest( $req, "POST", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

		// Get the ID of the new version out of the location header.
		$location = $response->getHeader( "location" );
		$pos = array();
		ereg( EsuRestApi::$ID_EXTRACTOR, $location, $pos );
		$this->trace( "Location: " . $location );
		
		return new ObjectId( $pos[1] );
	}
	
	/**
	 * Restores content from a version to the base object (i.e. "promote" an 
     * old version to the current version)
	 * @param ObjectId $id Base object ID (target of the restore)
	 * @param ObjectId $vId Version object ID to restore
	 */
	public function restoreVersion( $id, $vId ) {
		$resource = $this->getResourcePath( $this->context, $id );
		$req = $this->buildRequest( $resource, "versions" );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Add ID of version to promote
		$headers["x-emc-version-oid"] = $vId;
		
		// Sign request
		$this->signRequest( $req, "PUT", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}		
	}
	
	
	/**
	 * Fetches the system metadata for the object.
	 * @param Identifier $id the identifier of the object whose system metadata
	 * to fetch.
	 * @param MetadataTags $tags A list of system metadata tags to fetch.  Optional.
	 * Default value is null to fetch all system metadata.
	 * @return MetadataList The list of system metadata for the object.
	 */
	public function getSystemMetadata( $id, $tags = null ) {
		$resource = $this->getResourcePath( $this->context, $id );
		$req = $this->buildRequest( $resource, "metadata/system" );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Add tags if needed
		if( $tags != null ) {
			$this->processTags( $tags, $headers );
		}
		
		// Sign request
		$this->signRequest( $req, "GET", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

		// Parse return headers
		$meta = new MetadataList();
		$this->readMetadata( $meta, $response->getHeader( "x-emc-meta" ), false );		
		$this->readMetadata( $meta, $response->getHeader( "x-emc-listable-meta" ), true );
		
		return $meta;
		
	}
	
	/**
	 * Lists all objects with the given tag.
	 * @param MetadataTag|string $tag the tag to search for
	 * @return array The list of objects with the given tag.  If no objects
	 * are found the array will be empty.
	 * @throws EsuException if no objects are found (code 1003)
	 */
	public function listObjects( $tag ) {
		// If they pass a MetadataTag object, extract the tag name.
		if( is_a( $tag, "MetadataTag" ) ) {
			$tag = $tag->getName();
		}
		
		// Create request
		$resource = $this->context . "/objects";
		$req = $this->buildRequest( $resource, null );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Add tag
		if( $tag != null ) {
			$headers["x-emc-tags"] = $tag;
		} else {
			throw new EsuException( "tag cannot be null." );
		}
		
		// Sign request
		$this->signRequest( $req, "GET", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

		// Get the list of objects.  They are passed in the response body
		// in an XML format.
		$objects = array();
		$this->parseObjectList( $response->getBody(), $objects );
		return $objects;
		
	}
	
	/**
	 * Lists all objects with the given tag including their metadata
	 * @param MetadataTag|string $tag the tag to search for
	 * @return array The list of ObjectResult with the given tag.  If no objects
	 * are found the array will be empty.
	 * @throws EsuException if no objects are found (code 1003)
	 */
	public function listObjectsWithMetadata( $tag ) {
		// If they pass a MetadataTag object, extract the tag name.
		if( is_a( $tag, "MetadataTag" ) ) {
			$tag = $tag->getName();
		}
		
		// Create request
		$resource = $this->context . "/objects";
		$req = $this->buildRequest( $resource, null );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;

		// Request metadata
		$headers["x-emc-include-meta"] = "1";

		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Add tag
		if( $tag != null ) {
			$headers["x-emc-tags"] = $tag;
		} else {
			throw new EsuException( "tag cannot be null." );
		}
		
		// Sign request
		$this->signRequest( $req, "GET", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

		// Get the list of objects.  They are passed in the response body
		// in an XML format.
		$objects = array();
		$this->parseObjectListWithMetadata( $response->getBody(), $objects );
		return $objects;
	}
	
	
	/**
	 * Returns a list of all tags that are listable for the tenant to which 
	 * the current user belongs 
	 * @param $tag MetadataTag|string optional.  If specified, the list will
	 * be limited to the tags under the specified tag.
	 * @return MetadataTags the list of listable tags.
	 */
	public function getListableTags( $tag = null ) {
		// If they pass a MetadataTag object, extract the tag name.
		if( is_a( $tag, "MetadataTag" ) ) {
			$tag = $tag->getName();
		}
		
		// Create request
		$resource = $this->context . "/objects";
		$req = $this->buildRequest( $resource, "listabletags" );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Add tag
		if( $tag != null ) {
			$headers["x-emc-tags"] = $tag;
		}
		
		// Sign request
		$this->signRequest( $req, "GET", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

		// Get the listable tags out of the x-emc-listable-tags response header
		$this->trace( "listable tags: " . $response->getHeader( "x-emc-listable-tags" ) );
		$tags = new MetadataTags();
		$this->readTags( $tags, $response->getHeader( "x-emc-listable-tags" ), true );
		return $tags;
	}

	/**
	 * Returns the list of user metadata tags assigned to the object.
	 * @param $id Identifier the object whose metadata tags to list
	 * @return MetadataTags the list of user metadata tags assigned to the object
	 */
	public function listUserMetadataTags( $id ) {
		// Create request
		$resource = $this->getResourcePath( $this->context, $id );
		$req = $this->buildRequest( $resource, "metadata/tags" );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Sign request
		$this->signRequest( $req, "GET", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

		// Get the user metadata tags out of x-emc-listable-tags and
		// x-emc-tags
		$this->trace( "listable tags: " . $response->getHeader( "x-emc-listable-tags" ) );
		$this->trace( "tags: " . $response->getHeader( "x-emc-tags" ) );
		$tags = new MetadataTags();
		$this->readTags( $tags, $response->getHeader( "x-emc-listable-tags" ), true );
		$this->readTags( $tags, $response->getHeader( "x-emc-tags" ), false );
		return $tags;
	}

	/**
	 * Executes a query for objects matching the specified XQuery string.
	 * @param string $xquery the XQuery string to execute against the cloud.
	 * @return array the list of objects matching the query.  If no objects
	 * are found, the array will be empty.
	 */
	public function queryObjects( $xquery ) {
		// Create request
		$resource = $this->context . "/objects";
		$req = $this->buildRequest( $resource, null );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Add tag
		if( $xquery != null ) {
			$headers["x-emc-xquery"] = $xquery;
		} else {
			throw new EsuException( "query cannot be null." );
		}
		
		// Sign request
		$this->signRequest( $req, "GET", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

		// Get the list of objects in the search result.  They are passed
		// in the response body in an XML format.
		$objects = array();
		$this->parseObjectList( $response->getBody(), $objects );
		return $objects;
	}

	/**
	 * Updates an object in the cloud.
	 * @param Identifier $id The ID of the object to update
	 * @param Acl $acl Access control list for the new object. Optional, default
	 * is NULL.
	 * @param MetadataList $metadata Metadata list for the new object.  Optional,
	 * default is NULL.
	 * @param Extent $extent The extent of the object to update.  If extent
	 * is null or ALL_CONTENT and $data is not, the entire object will be 
	 * replaced.  If $data is null, $extent must also be null.
	 * @param string $data The data of the object.  May be appended
	 * to later. Optional, default is NULL (no content).  If data is null,
	 * the extent must also be null.
	 * @param string $mimeType the MIME type of the content.  Optional, 
	 * may be null.  If $data is non-null and $mimeType is null, the MIME
	 * type will default to application/octet-stream.
	 * @param Checksum $checksum if not null, use the Checksum object to compute
     * the checksum for the update object request.  If appending
     * to the object with subsequent requests, use the same
     * checksum object for each request.
	 * @throws EsuException if the request fails.
	 */
	public function updateObject( $id, $acl = null, $metadata = null, 
		$extent = null, $data = null, $mimeType = null, $checksum = null ) {
			
		$resource = $this->getResourcePath( $this->context, $id );
		$req = $this->buildRequest( $resource, null );
		
		$headers = array();
		
		if( $mimeType == null && $data != null ) {
			$mimeType = "application/octet-stream";
		}
		$headers["Content-Type"] = $mimeType;
		
		$headers["x-emc-uid"] = $this->uid;
		
		// Process metadata
		if( $metadata != null ) {
			$this->processMetadata( $metadata, $headers );
		}
		
		// Check extent / data requirements
		if( $data == null && $extent != null ) {
			throw new EsuException( "Cannot specify an extent without data" );
		}
		
		// Add extent if needed
		if( $extent != null && $extent != Extent::$ALL_CONTENT ) {
			// Need to do bcmath because the value might be > 4GB
			$end = bcadd( $extent->getOffset(), $extent->getSize() );
			$end = bcsub( $end, 1 );
			
			$headers["Range"] = "Bytes=" . $extent->getOffset() . "-" . $end; 
		}
		
		if ( isset( $headers["x-emc-meta"] ) ) {
		  $this->trace( "meta " . $headers["x-emc-meta"] );
		}
		
		// Add acl
		if( $acl != null ) {
			$this->processAcl( $acl, $headers );
		}
		
		// Process data
		if( $data != null ) {
			$req->setBody( $data );
		} else {
			$req->setBody( "" );
		}
		
		// Process checksum
		if( $checksum != null ) {
			if( $data != null ) {
				$checksum->update( $data );
			} 
			$headers["x-emc-wschecksum"] = "".$checksum;
		}
		
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Sign request
		$this->signRequest( $req, "PUT", $resource, $headers, $data );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

	}
	
	/**
     * Writes the metadata into the object. If the tag does not exist, it is 
     * created and set to the corresponding value. If the tag exists, the 
     * existing value is replaced.
     * @param Identifier $id the identifier of the object to update
     * @param MetadataList $metadata metadata to write to the object.
     */
	public function setUserMetadata( $id, $metadata ) {
		$resource = $this->getResourcePath( $this->context, $id );
		$req = $this->buildRequest( $resource, "metadata/user" );
		
		$headers = array();
		
		$headers["x-emc-uid"] = $this->uid;
		
		// Process metadata
		if( $metadata != null ) {
			$this->processMetadata( $metadata, $headers );
		}
		
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Sign request
		$this->signRequest( $req, "POST", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

	}
	
    /**
     * Sets (overwrites) the ACL on the object.
     * @param Identifier $id the identifier of the object to change the ACL on.
     * @param Acl $acl the new ACL for the object.
     */
	public function setAcl( $id, $acl ) {
		$resource = $this->getResourcePath( $this->context, $id );
		$req = $this->buildRequest( $resource, "acl" );
		
		$headers = array();
		
		$headers["x-emc-uid"] = $this->uid;
		
		// Add acl
		if( $acl != null ) {
			$this->processAcl( $acl, $headers );
		}
		
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Sign request
		$this->signRequest( $req, "POST", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

	}
	
	/**
     * Lists the contents of a directory.
     * @param Identifier $id the identifier of the directory object to list.
     * @return array the directory entries in the directory.
     */
    public function listDirectory( $id ) {
    	$namespace = is_a( $id, "ObjectPath" ) ? true : false; 
    	
    	// fetch the directory's content as a blob
    	$data = $this->readObject( $id );
		$this->trace( $data );
    	$objs = array();
    	
    	// Parse the XML
		$dom = new DOMDocument( );
		$parseOk = false;
		
		try {
			$parseOk = $dom->loadXML( $data );
		} catch( Exception $e ) {
			$this->trace( "Parse error message failed: " . $e );
			// Can't parse body.  Throw HTTP code
			throw new EsuException( 'Request failed: ' . $req->getResponseReason(), 
				$req->getResponseCode() );
			
		}
		if( $parseOk === true ) {
			$entries = $dom->getElementsByTagName( "DirectoryEntry" );
			for( $i=0; $i<$entries->length; $i++ ) {
				$entry = $entries->item($i);
				$de = new DirectoryEntry();
				$gc = $entry->childNodes;
				$name = "";
				$type = "";
				for( $j=0; $j<$gc->length; $j++ ) {
					$tag = $gc->item($j);
					$xval = $tag->nodeValue;
					if (! empty($tag->tagName)) {
						if( strtolower($tag->tagName) == "objectid" ) {
							$de->setId( new ObjectId( $xval ) );
						} else if( $tag->tagName == "Filename" ) {
							$name = $xval;
						} else if( $tag->tagName == "FileType" ) {
							$type = $xval;
						}
					}
				}

				$de->setName( $name );				
				if ($namespace) {
					$name = $id . $name;
					if( "directory" == $type ) {
						$name .= "/";
					}
					$de->setPath( new ObjectPath( $name ) );
				}
				$de->setType( $type );	
				
				$objs[] = $de;
				
			}
			
		}
		return $objs;
    	
    }
    
    /**
     * An Atmos user (UID) can construct a pre-authenticated URL to an 
     * object, which may then be used by anyone to retrieve the 
     * object (e.g., through a browser). This allows an Atmos user 
     * to let a non-Atmos user download a specific object. The 
     * entire object/file is read.
     * @param Identifier $id the object to generate the URL for
     * @param int $expiration the expiration date of the URL (in unix time)
     * @return string a URL that can be used to share the object's content
     */
    public function getShareableUrl( $id, $expiration ) {
   		$resource = $this->getResourcePath( $this->context, $id );
        $uidEnc = urlencode( $this->uid );
            
        $sb = "GET\n";
        $sb .= strtolower( $resource ) . "\n";
        $sb .= $this->uid . "\n";
        $sb .= $expiration;
            
        $signature = $this->sign( $sb );
        $resource .= "?uid=" . $uidEnc . "&expires=" . $expiration .
                "&signature=" . urlencode( $signature );
            
        $url = $this->proto . "://" . $this->host . ":" . $this->port . 
			$resource;
        
        return $url;
    }

    /**
     * Returns the Atmos protocol information 
	 * @param $protocolInfo
     */
	public function getProtocolInformation( &$protocolInfo )
		{
		$protocolInfo['transportProtocol'] = $this->proto;
		$protocolInfo['accessPoint'] = $this->host;
		$protocolInfo['accessPort'] = $this->port;
		$protocolInfo['accessPortProtocol'] = 'TCP/IP';
        $protocolInfo['accessScheme'] = $this->proto . "://" . $this->host . ":" . $this->port; 
        $protocolInfo['userId'] = $this->uid; 
		return;
		}
		
    /**
     * Returns all of an object's metadata and its ACL in
     * one call.
     * @param $id the object's identifier.
     * @return ObjectMetadata the object's metadata
     */
    public function getAllMetadata( $id ) {
		$resource = $this->getResourcePath( $this->context, $id );
		$req = $this->buildRequest( $resource, null );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Sign request
		$this->signRequest( $req, "HEAD", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

		// Parse return headers.  Regular metadata is in x-emc-meta and
		// listable metadata is in x-emc-listable-meta
		$meta = new MetadataList();
		$this->readMetadata( $meta, $response->getHeader( "x-emc-meta" ), false );		
		$this->readMetadata( $meta, $response->getHeader( "x-emc-listable-meta" ), true );
		
		// Parse return headers.  User grants are in x-emc-useracl and
		// group grants are in x-emc-groupacl
		$acl = new Acl();
		$this->readAcl( $acl, $response->getHeader( "x-emc-useracl" ), Grantee::USER );		
		$this->readAcl( $acl, $response->getHeader( "x-emc-groupacl" ), Grantee::GROUP );
		
		return array( $meta, $acl );
    }
	
	
	


	/** 
	 * Turns debug messages on and off.
	 */
	public function setDebug( $state ) {
		$this->debug = $state;
	}
	
	/**
	 * Changes the context root.  By default, the REST services are located
	 * at /rest.  If you've modified the server configuration or are using
	 * a proxy and need to change the context, use this method.
	 */
	public function setContext( $ctx ) {
		$this->context = $ctx;
	}
	
	/**
	 * Changes the protocol used.  By default, the protocol will be http unless
	 * the port number is 443.  If you need https on another port (due to port
	 * mapping, proxies, etc), you can set the protocol to https here.  Note
	 * that http and https are the only supported protocols.
	 */
	public function setProtocol( $prot ) {
		$this->proto = $prot;
	}
	
	/**
	 * Sets the connection timeout in seconds.  If the connection cannot be
	 * established in this time period, the request will fail.
	 */
	public function setTimeout( $timeout ) {
		$this->timeout = $timeout;
	}
	
	/**
	 * Set to automatically follow HTTP redirects
	 */
	public function setFollowRedirects($follow, $maxRedirects = 5) {
		$this->followRedirects = $follow;
	}
	
	/**
     * Renames a file or directory within the namespace.
     * @param ObjectPath $source The file or directory to rename
     * @param ObjectPath $destination The new path for the file or directory
     * @param ObjectPath $force If true, the desination file or 
     * directory will be overwritten.  Directories must be empty to be 
     * overwritten.  Also note that overwrite operations on files are
     * not synchronous; a delay may be required before the object is
     * available at its destination.
     */
    public function rename( $source, $destination, $force ) {
    	$resource = $this->getResourcePath( $this->context, $source );
		$req = $this->buildRequest( $resource, "rename" );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Add the destination path
        $destPath = "".$destination;
        if ($destPath[0] == '/' ) {
        	$destPath = substr( $destPath, 1 );
        }
        
        $headers["x-emc-path"] = $destPath;

        if ($force) {
        	$headers["x-emc-force"] = "true";
        }
		
		// Sign request
		$this->signRequest( $req, "POST", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}
    }
	
    /**
     * Gets information about the web service.  Currently, this only includes
     * the version of Atmos.
     * @return ServiceInformation the service information object.
     */
    public function getServiceInformation() {
		// Create request
		$resource = $this->context . "/service";
		$req = $this->buildRequest( $resource, null );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Sign request
		$this->signRequest( $req, "GET", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}

		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}

		return $this->parseServiceInformation( $response->getBody() );
    }
    
    public function getObjectInfo( $id ) {
   		$resource = $this->getResourcePath( $this->context, $id );
		$req = $this->buildRequest( $resource, "info" );
		$headers = array();
		$headers["x-emc-uid"] = $this->uid;
		// Add date
		$headers["Date"] = gmdate( 'r' );
		
		// Sign request
		$this->signRequest( $req, "GET", $resource, $headers, null );
		
		try {
			$response = $req->send();
		} catch( HTTP_Request2_Exception $e ) {
			throw new EsuException( "Sending request failed: " . $e );
		}
		
		if( $response->getStatus() > 299 ) {
			$this->handleError( $response );
		}
	
		// Parse the returned objects.  They are passed in the response
		// body in an XML format.
		return $this->parseObjectInfo( $response->getBody() );
		
	}
    
    
	
	/////////////////////
	// Private Methods //
	/////////////////////
	
	
	/**
	 * Creates an HTTP Request
	 * @param string $resource the resource to access, e.g. /rest/namespace/file.txt
	 * @param string $query query parameters, may be null, e.g. "versions"
	 */
	private function buildRequest( $resource, $query ) {
		$url = new Net_URL2( null );
		$url->setScheme( $this->proto );
		$url->setHost( $this->host );
		$url->setPort( $this->port );
		
		// URLEncode the resource
		$newurl = "";
		$parts = explode( "/", substr( $resource, 1 ) );
		for( $i=0; $i<count($parts); $i++ ) {
			$newurl .= '/' . rawurlencode($parts[$i]);
		}
		
		$url->setPath( $newurl );
		
		
		if( $query ) {
			$url->setQuery( $query );
		}
		
		$args = array();
		if( $this->timeout ) {
			$args["timeout"] = $this->timeout;			
		}
		$req = &new HTTP_Request2( $url, $args );
		if( $this->followRedirects ) {
			$req->setConfig('follow_redirects', true);
		}
		
		// Some systems fail to verify the SSL certificate of
		// accesspoint.atmosonline.com (e.g. Ubuntu Linux).  Disable certificate
		// verification
		$req->setConfig(array(
			'ssl_verify_peer'       => FALSE,
			'ssl_verify_host'       => FALSE
		));

		return $req;
	}
	
	/**
	 * Parse an ACL object and set the values of the x-emc-useracl and
	 * x-emc-groupacl headers.
	 * @param Acl $acl the ACL to parse
	 * @param array $headers a reference to the HTTP request headers
	 */
	private function processAcl( $acl, &$headers ) {
		$userGrants = "";
		$groupGrants = "";
		
		for( $i=0; $i<$acl->count(); $i++ ) {
			$grant = $acl->getGrant( $i );
			if( $grant->getGrantee()->getType() == Grantee::USER ) {
				if( strlen( $userGrants ) > 0 ) {
					$userGrants .= ",";
				}
				$userGrants .= $grant;
			} else {
				if( strlen( $groupGrants ) > 0 ) {
					$groupGrants .= ",";
				}
				$groupGrants .= $grant;
			}
		}
		
		if( strlen( $userGrants ) > 0 ) {
			$headers["x-emc-useracl"] = $userGrants;
		}
		if( strlen( $groupGrants ) > 0 ) {
			$headers["x-emc-groupacl"] = $groupGrants;
		}
	}
	
	/**
	 * Computes the method signature from the given request, method, resource,
	 * and headers.  After generating the signature, the method and all the
	 * headers will be set on the request.
	 */
	private function signRequest( $req, $method, $resource, $headers ) {
		// Build the string to hash.
		$hashStr = $method . "\n";
		
		// If content type exists, add it.  Otherwise add a blank line.
		if( isset( $headers['Content-Type'] ) ) {
			$hashStr .= $headers['Content-Type'] . "\n";
		} else {
			$hashStr .= "\n";
		}
		
		// If the range header exists, add it.  Otherwise add a blank line.
		if( isset( $headers['Range'] ) ) {
			$hashStr .= $headers['Range'] . "\n";
		} else {
			$hashStr .= "\n";
		}
		
		// Add the current date and the resource.
		$hashStr .= $headers['Date'] . "\n";
		
		//$fullResource = $req->getUrl()->getPath();
		$fullResource = $resource;
		
		if( $req->getUrl()->getQuery() != null ) {
			$fullResource .= "?" . $req->getUrl()->getQuery();
		}
		$hashStr .= strtolower( $fullResource ) . "\n";
			
		// Do the 'x-emc' headers.  The headers must be hashed in alphabetic
		// order and the values must be stripped of whitespace and newlines.
		$keys = array();
		$newheaders = array();
		
		// Extract the keys and values
		foreach( $headers as $key => $value ) {
			if( strpos( $key, 'x-emc' ) === 0 ) {
				$k = strtolower($key);
				$keys[] = $k;
				//$value = str_replace( " ", "", $value );
				$value = str_replace( "\n", "", $value );
				$newheaders[$k] = $value;
			}
		}
		
		// Sort the keys and add the headers to the hash string.
		sort( $keys );
		$first = true;
		foreach( $keys as $k ) {
			if( !$first ) {
				$hashStr .= "\n";
			} else {
				$first = false;
			}
			//$this->trace( "xheader: " . $k . "->" . $newheaders[$k] );
			$hashStr .= $k . ':' . $this->normalizeSpace($newheaders[$k]);
		}
		
		$this->trace( "Hashing: \n" . $hashStr );
		
		$hashOut = $this->sign( $hashStr );

		$this->trace( 'Hash: ' . $hashOut );
		
		// Can set all the headers, etc now.
		reset( $headers );
		foreach( $headers as $key => $value ) { 
			$req->setHeader( $key, $value );
			$this->trace( $key . "->" . $value );
		}
		
		// Set the signature header
		$req->setHeader( 'x-emc-signature', $hashOut );
		
		// Set the method.
		$req->setMethod( $method );
	}
	
	private function normalizeSpace( $s ) {
		$len = strlen( $s );
		while( 1 ) {
			$s = str_replace( "  ", " ", $s );
			if( strlen( $s ) == $len ) {
				return $s;
			}
			$len = strlen( $s );
		}
	}
	
	/**
	 * Generates a signature
	 */
	private function sign( $data ) {
		// Do the hash.
		$hash = hash_init( "sha1", HASH_HMAC, $this->secret );
		hash_update( $hash, $data );
		
		// Encode the hash in Base64.
		$hashOut = base64_encode( hash_final( $hash, true ) );
		
		return $hashOut;
	}
	
	/**
	 * Used to output debug messages.
	 */
	private function trace( $str ) {
		if( $this->debug ) {
			echo $str;
			echo "\n";
		}
	}
	
	/**
	 * Handles failed requests.  If the error is from ESU, the response body
	 * should contain an XML packet we can parse for the error code and message.
	 * Otherwise, throw a generic error.
	 */
	private function handleError( $response ) {
		$this->trace( "Response body: " . $response->getBody() );
		if( $response->getBody() != null ) {
			// Note that this is the newer php5 DOM and not the older
			// domxml extension.  If you're running XAMPP, you might have
			// to disable the domxml extension in php.ini.
			$dom = new DOMDocument( );
			$parseOk = false;
			
			try {
				$parseOk = $dom->loadXML( $response->getBody() );
			} catch( Exception $e ) {
				$this->trace( "Parse error message failed: " . $e );
				// Can't parse body.  Throw HTTP code
				throw new EsuException( 'Request failed: ' . $response->getReasonPhrase(), 
					$response->getStatus() );
				
			}
			if( $parseOk === true ) {	
				$code = $dom->getElementsByTagName( "Code" );
				$msg = $dom->getElementsByTagName( "Message" );
				if( $code->length > 0 && $msg->length > 0 ) {
					throw new EsuException( $msg->item(0)->nodeValue, $code->item(0)->nodeValue );
				}
			}
		}

		throw new EsuException( 'Request failed with error ' . 
			$response->getStatus() . ': ' . $response->getReasonPhrase() . 
			$response->getBody(), $response->getStatus() );

	}
	
	
	/**
	 * Processes metadata and creates header entries
	 */
	private function processMetadata( $metadata, &$headers ) {
		$listable="";
		$nonListable = "";
		
		$this->trace( "Processing " . $metadata->count() . " metadata entries" );
		
		for( $i=0; $i<$metadata->count(); $i++ ) {
			$meta = $metadata->getMetadata( $i );
			if( $meta->isListable() ) {
				if( strlen( $listable ) > 0 ) {
					$listable .= ",";
				}
				$listable .= $this->formatTag( $meta );
			} else {
				if( strlen( $nonListable ) > 0 ) {
					$nonListable .= ",";
				}
				$nonListable .= $this->formatTag( $meta );
			}
		}
		
		if( strlen( $listable ) > 0 ) {
			$headers["x-emc-listable-meta"] = $listable;
		}
		if( strlen( $nonListable ) > 0 ) {
			$headers["x-emc-meta"] = $nonListable;
		}
	}
	
	/**
	 * Formats a tag value for passing in the header.
	 */
	private function formatTag( $meta ) {
		// strip commas and newlines for now.
		$fixed = str_replace( ",", "", $meta->getValue() );
		$fixed = str_replace( "\n", "", $fixed );
		return $meta->getName() . "=" . $fixed;
	}
	
	/**
	 * Parse a metadata response header and add Metadata objects to the
	 * given MetadataList
	 * @param MetadataList $meta a reference to the MetadataList to append
	 * @param string $header the response header to parse
	 * @param boolean $listable whether to mark the Metadata objects as
	 * listable or not
	 */
	private function readMetadata( &$meta, $header, $listable ) {
		if( $header == null ) {
			return;
		}

		$attrs = split( ", ", $header );
		foreach( $attrs as $attr ) {
			$nvpair = split( "=", $attr, 2 );
			$name = $nvpair[0];
			$value = $nvpair[1];
			
			if( strpos( $name, " " ) === 0 ) {
				$name = substr( $name, 1 );
			}
			
			$m = new Metadata( $name, $value, $listable );
			$this->trace( "Meta: " . $m );
			$meta->addMetadata( $m );
		}
	}
	
	/**
	 * Parses the value of an ACL response header and builds an ACL
	 * @param Acl $acl a reference to the ACL to append to
	 * @param string $header the acl response header
	 * @param string $type the type of Grantees in the header (user or group)
	 */
	private function readAcl( &$acl, $header, $type ) {
		$this->trace( "readAcl: " . $header );
		$grants = split( ",", $header );
		foreach( $grants as $grant ) {
			$nvpair = split( "=", $grant, 2 );
			$grantee = $nvpair[0];
			$permission = $nvpair[1];
			
			if( strpos( $grantee, " " ) === 0 ) {
				$grantee = substr( $grantee, 1 );
			}
			
			// Currently, the server returns "FULL" instead of "FULL_CONTROL".
			// For consistency, change this to value use in the request
			if( $permission == "FULL" ) {
				$permission = Permission::FULL_CONTROL;
			}
			
			
			$this->trace( "grant: " . $grantee . "->" . $permission . " (" . $type . ")" );
			
			$ge = new Grantee( $grantee, $type );
			$gr = new Grant( $ge, $permission );
			$this->trace( "Grant: " . $gr );
			$acl->addGrant( $gr );
		}
		
	}
	
    /**
	 * Processes metadata tags and creates header entry
	 */
	private function processTags( $tags, &$headers ) {
		$taglist = "";
		
		$this->trace( "Processing " . $tags->count() . " metadata tag entries" );
		
		for( $i=0; $i<$tags->count(); $i++ ) {
			$tag = $tags->getTag( $i );
			if( strlen( $taglist ) > 0 ) {
				$taglist .= ",";
			}
			$taglist .= $tag->getName();
		}

		if( strlen( $taglist ) > 0 ) {
			$headers["x-emc-tags"] = $taglist;
		}
	}
	
	/**
	 * Parses an XML list containing ObjectID elements.
	 */
	private function parseObjectList( $xml, &$objList ) {
		$this->trace( "Response body: " . $xml );
		if( $xml != null ) {
			// Note that this is the newer php5 DOM and not the older
			// domxml extension.  If you're running XAMPP, you might have
			// to disable the domxml extension in php.ini.
			$dom = new DOMDocument( );
			if( $dom->loadXML( $xml ) === true ) {
				// Just return all the ObjectID elements.
				$objs = $dom->getElementsByTagName( "ObjectID" );
				$this->trace( "found " . $objs->length . " ids" );
				for( $i=0; $i<$objs->length; $i++ ) {
					$idstr = $objs->item($i)->nodeValue;
					$this->trace( "found ID: " . $idstr );
					$objList[] = new ObjectId( $idstr );
				}
			}
		}
		
	}
	
	/**
	 * Parses an XML list containing ObjectID elements.
	 */
	private function parseVersionList( $xml, &$objList ) {
		$this->trace( "Response body: " . $xml );
		if( $xml != null ) {
			// Note that this is the newer php5 DOM and not the older
			// domxml extension.  If you're running XAMPP, you might have
			// to disable the domxml extension in php.ini.
			$dom = new DOMDocument( );
			if( $dom->loadXML( $xml ) === true ) {
				// Just return all the ObjectID elements.
				$objs = $dom->getElementsByTagName( "OID" );
				$this->trace( "found " . $objs->length . " ids" );
				for( $i=0; $i<$objs->length; $i++ ) {
					$idstr = $objs->item($i)->nodeValue;
					$this->trace( "found ID: " . $idstr );
					$objList[] = new ObjectId( $idstr );
				}
			}
		}
		
	}
	
	/**
	 * Parses an XML list containing ObjectResult elements.
	 */
	private function parseObjectListWithMetadata( $xml, &$objList ) {
		$this->trace( "Response body: " . $xml );
		if( $xml != null ) {
			// Note that this is the newer php5 DOM and not the older
			// domxml extension.  If you're running XAMPP, you might have
			// to disable the domxml extension in php.ini.
			$dom = new DOMDocument( );
			if( $dom->loadXML( $xml ) === true ) {
				// Just return all the ObjectID elements.
				$objs = $dom->getElementsByTagName( "Object" );
				$this->trace( "found " . $objs->length . " objects" );
				for( $i=0; $i<$objs->length; $i++ ) {
					$result = new ObjectResult();
					$mList = new MetadataList();
					$result->setMetadata( $mList );
					$child = $objs->item($i);
					$gc = $child->childNodes;
					for( $j=0; $j<$gc->length; $j++ ) {
						$tag = $gc->item($j);
						if (! empty($tag->tagName)) {
							$this->trace( "found " . $tag->tagName );
							if( $tag->tagName == "ObjectID" ) {
								$result->setId( new ObjectId( $tag->nodeValue ) );
							} else if( $tag->tagName == "SystemMetadataList" ) {
								$meta = $tag->getElementsByTagName( "Metadata" );
								$this->parseMetadata( $meta, $mList );
							} else if( $tag->tagName == "UserMetadataList" ) {
								$meta = $tag->getElementsByTagName( "Metadata" );
								$this->parseMetadata( $meta, $mList );						
							}
						}
					}
					$this->trace( "found " . $mList->count() . " metadata " );
					$objList[] = $result;
				}
			}
		}
		
	}
	
	/**
	 * Parses metadata for parseObjectListWithMetadata
	 */
	private function parseMetadata( $children, &$mList ) {
		$this->trace( $children->length . " metadata entries" );
		for($i=0;$i<$children->length; $i++ ) {
			$child = $children->item($i);
			$name = "";
			$value = "";
			$listable = false;
			$gc = $child->childNodes;
			for( $j=0; $j<$gc->length; $j++ ) {
				$tag = $gc->item($j);
				$xval = $tag->nodeValue;
				if (! empty($tag->tagName)) {
					if( $tag->tagName == "Name" ) {
						$name = $xval;
					} else if( $tag->tagName == "Value" ) {
						$value = $xval;
					} else if( $tag->tagName == "Listable" ) {
						if( "true" == $xval ) {
							$listable = true;
						}
					}
				}
			}
			
			$this->trace( "addMetadata: " . $name . " " . $listable . " " . $value );
			$mList->addMetadata( new Metadata( $name, $value, $listable ) );
		}
		
	}
	
	/**
	 * Parses a metadata tag response header and appends to the given
	 * MetadataTags object.
	 * @param MetadataTags $tags the MetadataTags to append to
	 * @param string $header the response header to parse
	 * @param boolean $listable the value of the listable flag on the created
	 * tags
	 */
	private function readTags( &$tags, $header, $listable ) {
		if( $header == null ) {
			return;
		}
		
		$attrs = split( ",", $header );
		foreach( $attrs as $attr ) {
			if( strpos( $attr, " " ) === 0 ) {
				$attr = substr( $attr, 1 );
			}
			
			$tags->addTag( new MetadataTag( $attr, $listable ) );
			$this->trace( "Created tag: >" . $attr . "<" );
		}
	}
	
	/**
     * Gets the appropriate resource path depending on identifier
     * type.
     */
    private function getResourcePath( $ctx, $id ) {
		if( is_a( $id, "ObjectId" ) ) {
			return $ctx . "/objects/" . $id;
		} else if( is_a( $id, "ObjectPath" ) ) {
			$str = $ctx . "/namespace" . $id;
			return $str;
		} else {
			throw new EsuException( 'invalid identifier type ' . get_class( $id ) );
		}
	}
	
	private function parseServiceInformation( $xml ) {
		$this->trace( "Response body: " . $xml );
		if( $xml != null ) {
			// Note that this is the newer php5 DOM and not the older
			// domxml extension.  If you're running XAMPP, you might have
			// to disable the domxml extension in php.ini.
			$dom = new DOMDocument( );
			if( $dom->loadXML( $xml ) === true ) {
				// Just return all the ObjectID elements.
				$objs = $dom->getElementsByTagName( "Atmos" );
				
				$si = new ServiceInformation();
				
				$si->setAtmosVersion( $objs->item(0)->nodeValue );
				
				return $si;
			} else {
				throw new EsuException( "Could not parse XML" );
			}
		} else {
			throw new EsuException( "Null data passed to parseServiceInformation" );
		}
	}
	
	private function parseObjectInfo( $xml ) {
		$this->trace( "Response body: " . $xml );
		$info = new ObjectInfo();
		$info->rawXml = $xml;
		if( $xml != null ) {
			// Note that this is the newer php5 DOM and not the older
			// domxml extension.  If you're running XAMPP, you might have
			// to disable the domxml extension in php.ini.
			$dom = new DOMDocument( );
			if( $dom->loadXML( $xml ) === true ) {
				$info->objectId = new ObjectId( $dom->getElementsByTagName( "objectId")->item(0)->nodeValue );
				$info->selection = $dom->getElementsByTagName( "selection")->item(0)->nodeValue;
				$info->retention = $this->parseRetention( $dom->getElementsByTagName( "retention")->item(0) );
				$info->expiration = $this->parseExpiration( $dom->getElementsByTagName( "expiration")->item(0) );
				$this->parseReplicas( $dom->getElementsByTagName( "replica"), &$info->replicas );
			} else {
				throw new EsuException( "Could not parse XML" );
			}
		} else {
			throw new EsuException( "Null data passed to parseObjectInfo" );
		}
		return $info;
	}
	
	/**
	 * Parse retention information from a node
	 * @param DOMElement $node
	 */
	private function parseRetention( $node ) {
		$retention = new ObjectRetention();
		
		$retention->enabled = $node->getElementsByTagName( "enabled" )->item(0)->nodeValue == "true";
		if( $retention->enabled ) {
			$dateStr = $node->getElementsByTagName( "endAt" )->item(0)->nodeValue;
			$dateStr = str_replace( "Z", "+0000", $dateStr );
			$retention->endAt = new DateTime( $dateStr, new DateTimeZone("UTC") );
		}
		
		return $retention;
	}
	
	/**
	 * Parse retention information from a node
	 * @param DOMElement $node
	 */
	private function parseExpiration( $node ) {
		$expiration = new ObjectExpiration();
		
		$expiration->enabled = $node->getElementsByTagName( "enabled" )->item(0)->nodeValue == "true";
		if( $expiration->enabled ) {
			$dateStr = $node->getElementsByTagName( "endAt" )->item(0)->nodeValue;
			$dateStr = str_replace( "Z", "+0000", $dateStr );
			$expiration->endAt = new DateTime( $dateStr, new DateTimeZone("UTC") );
		}
		
		return $expiration;
	}
	
	/**
	 * Parses a replica list
	 * @param DOMNodeList $replicaXml
	 * @param Array $replicaArray
	 */
	private function parseReplicas( $replicaXml, &$replicaArray ) {
		for( $i=0; $i<$replicaXml->length; $i++ ) {
			$replicaArray[] = $this->parseReplica( $replicaXml->item($i));
		}
	}
	
	/**
	 * Parses a replica record
	 * @param DOMElement $replicaElement
	 */
	private function parseReplica( $replicaElement ) {
		$replica = new ObjectReplica();
		
		$replica->id = $replicaElement->getElementsByTagName( "id" )->item(0)->nodeValue;
		$replica->location = $replicaElement->getElementsByTagName( "location" )->item(0)->nodeValue;
		$replica->replicaType = $replicaElement->getElementsByTagName( "type" )->item(0)->nodeValue;
		$replica->storageType = $replicaElement->getElementsByTagName( "storageType" )->item(0)->nodeValue;
		$replica->current = $replicaElement->getElementsByTagName( "current" )->item(0)->nodeValue == "true";
		
		return $replica;
	}
	
}
?>
