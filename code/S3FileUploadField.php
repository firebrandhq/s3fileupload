<?php

class S3FileUploadField extends UploadField {

	protected $templateFileButtons = 'S3UploadField_FileButtons';

	protected $bucket = false;
	protected $region = false;

	private static $allowed_actions = array(
		'upload',
		/*'attach',
		'handleItem',
		'handleSelect',
		'fileexists'*/
	);

	public function __construct($name, $title = null, SS_List $items = null) {
		parent::__construct($name, $title, $items);
		$this->removeExtraClass('ss-upload'); // Remove the parent's JS hook.
		$this->addExtraClass('s3-upload'); // Add our own JS hook.
		$this->getValidator()->setAllowedMaxFileSize(File::ini2bytes('2G'));
	}

	public function Field($properties = array()) {
		$this->ufConfig['url'] = self::getBucketUrl();
		$this->ufConfig['canAttachExisting'] = false;
		$this->ufConfig['urlFileExists'] = '';
		$this->ufConfig['overwriteWarning'] = false;
		$this->ufConfig['overwriteWarning'] = false;
		$this->ufConfig['downloadTemplateName'] = 'ss-s3uploadfield-downloadtemplate';

		$this->ufConfig['FormData'] = self::getFormData();

		$this->ufConfig['uploadCallbackUrl'] = $this->Link('upload');

		$return = parent::Field($properties);

		Requirements::javascript(S3_FILE_UPLOAD_DIR . '/js/S3UploadField_downloadtemplate.js');
		Requirements::javascript(S3_FILE_UPLOAD_DIR . '/js/S3UploadField.js');

		Requirements::css(S3_FILE_UPLOAD_DIR . '/css/S3UploadField.css');

		return $return;
	}

	public function getBucket() {
		return ($this->bucket) ? $this->bucket : S3File::config()->Bucket;
	}

	public function setBucket($value) {
		$this->bucket = $value;
		return $this;
	}

	public function getRegion() {
		return ($this->region) ? $this->region : S3File::config()->Region;
	}

	public function setRegion($value) {
		$this->region = $value;
		return $this;
	}

	public function getFormData() {
		$bucket = $this->getBucket();
		$key = S3File::config()->AccessId;
		$secret = S3File::config()->Secret;
		$region = $this->getRegion();
		$acl = 'private';


		$algorithm = "AWS4-HMAC-SHA256";
		$service = "s3";
		$date = gmdate('Ymd\THis\Z');
		$shortDate = gmdate('Ymd');
		$requestType = "aws4_request";
		$expires = "" . 60*60; // 1 Hour
		$successStatus = '201';

		$scope = [
			$key,
			$shortDate,
			$region,
			$service,
			$requestType
		];
		$credentials = implode('/', $scope);

		$policy = [
			'expiration' => gmdate('Y-m-d\TG:i:s\Z', strtotime('+1 hours')),
			'conditions' => [
				['bucket' => $bucket],
				['acl' => $acl],
				['starts-with', '$key', ''],
				['starts-with', '$Content-Type', ''],
				['success_action_status' => $successStatus],
				['x-amz-credential' => $credentials],
				['x-amz-algorithm' => $algorithm],
				['x-amz-date' => $date],
				['x-amz-expires' => $expires],
			]
		];

		$base64Policy = base64_encode(json_encode($policy));

		// Signing Keys
		$dateKey = hash_hmac('sha256', $shortDate, 'AWS4' . $secret, true);

		$dateRegionKey = hash_hmac('sha256', $region, $dateKey, true);

		$dateRegionServiceKey = hash_hmac('sha256', $service, $dateRegionKey, true);

		$signingKey = hash_hmac('sha256', $requestType, $dateRegionServiceKey, true);

		// Signature
		$signature = hash_hmac('sha256', $base64Policy, $signingKey);


		$formData = array(
			array( 'name' => 'key', 'value' => uniqid('', true)),
			array( 'name' => 'Content-Type', 'value' => ''),
			//array( 'name' => 'Signature', 'value' => $signature),
			array( 'name' => 'acl', 'value' => $acl),
			array( 'name' => 'success_action_status', 'value' => $successStatus),
			//array( 'name' => 'AWSAccessKeyId', 'value' => $key),
			array( 'name' => 'policy', 'value' => $base64Policy),
			array( 'name' => 'X-amz-algorithm', 'value' => $algorithm),
			array( 'name' => 'X-amz-credential', 'value' => $credentials),
			array( 'name' => 'X-amz-date', 'value' => $date),
			array( 'name' => 'X-amz-expires', 'value' => $expires),
			array( 'name' => 'X-amz-signature', 'value' => $signature),
		);

		return $formData;
	}

	public function getBucketUrl() {
		$region = $this->getRegion();

		// US general doesn't have it's name in the bucket URL
		if ($region  == 'us-east-1') {
			$region = '';
		} else {
			$region = "-$region";
		}
		$bucket = $this->getBucket();

		return "https://$bucket.s3$region.amazonaws.com/";
	}

	public function upload(SS_HTTPRequest $request) {
		if($this->isDisabled() || $this->isReadonly() || !$this->canUpload()) {
			return $this->httpError(403);
		}

		// Protect against CSRF on destructive action
		$token = $this->getForm()->getSecurityToken();
		if(!$token->checkRequest($request)) return $this->httpError(400);

		// Get form details
		$postVars = $request->postVars();
		$postVars['LastModified'] = date("Y-m-d H:i:s", $postVars['LastModified']);
		$postVars['ETag'] = str_replace('"', '',$postVars['ETag']);
		$postVars['Region'] = $this->getRegion();


		//var_dump($postVars); die();

		$s3File = new S3File($postVars);
		$s3File->write();

		$s3File->customise(array(
			'UploadFieldDeleteLink' => $this->getItemHandler($s3File->ID)->DeleteLink()
		));

		// Format response with json
		$response = new SS_HTTPResponse(Convert::raw2json(array(array(
			'bucket' => $s3File->Bucket,
			'etag' => $s3File->ETag,
			'id' => $s3File->ID,
			'key' => $s3File->Key,
			'last_modified' => $s3File->LastModified,
			'location' => $s3File->Location,
			'name' => $s3File->Name,
			'size' => $s3File->Size,
			'type' => $s3File->Type,
			'fieldname' => $this->getName(),
			'buttons' => (string)$s3File->renderWith($this->getTemplateFileButtons()),
			'edit_url' => $this->getItemHandler($s3File->ID)->EditLink(),
			'thumbnail_url' => $s3File->Icon(),
		))));
		$response->addHeader('Content-Type', 'application/json');
		if (!empty($return['error'])) $response->setStatusCode(403);
		return $response;
	}

	public function getItemHandler($itemID) {
		return S3FileUploadField_ItemHandler::create($this, $itemID);
	}

}
