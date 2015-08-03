<?php
/**
 * Extends the SilverStripe UploadField to allow it to upload file to an S3
 * Bucket instead of the local storage.
 *
 * @author Maxime Rainville <max@firebrand.nz>
 * @package s3fileupload
 */
class S3FileUploadField extends UploadField {

	/**
	 * We'll use a different template to render the buttons on the upload field
	 * because our actions are slightly different.
	 * @var string
	 */
	protected $templateFileButtons = 'S3UploadField_FileButtons';

	/**
	 * Name of the bucket where the file will be uploaded.
	 * @var string
	 */
	protected $bucket = false;

	/**
	 * Name of the AWS region where the bucket is lcoated.
	 * @var string
	 */
	protected $region = false;

	/**
	 * Possible actions on this fields
	 * @var array
	 */
	private static $allowed_actions = array(
		'upload',
		/*'attach',
		'handleItem',
		'handleSelect',
		'fileexists'*/
	);

	/**
	 * Override the standard UploadField constructor so we can set a few things.
	 * @param string $name
	 * @param string $title
	 * @param SS_List $items
	 */
	public function __construct($name, $title = null, SS_List $items = null) {
		parent::__construct($name, $title, $items);

		// Remove the parent's JS hook.
		$this->removeExtraClass('ss-upload');

		// Add our own JS hook.
		$this->addExtraClass('s3-upload');

		// Set a bigger initial limit for our uploads. Otherwise this will
		// default to what's the Max File Size limit in our php.ini
		$this->getValidator()->setAllowedMaxFileSize(File::ini2bytes('2G'));
	}

	/**
	 * Return our field for display in SS template
	 * @param array $properties
	 * @return SS_viewable
	 */
	public function Field($properties = array()) {
		// Highjack some values in the UploadField config
		$this->ufConfig['url'] = self::getBucketUrl();
		$this->ufConfig['canAttachExisting'] = false;
		$this->ufConfig['urlFileExists'] = '';
		$this->ufConfig['overwriteWarning'] = false;
		$this->ufConfig['overwriteWarning'] = false;
		$this->ufConfig['downloadTemplateName'] = 'ss-s3uploadfield-downloadtemplate';

		// Attach a signed S3 Fileupload request
		$this->ufConfig['FormData'] = self::getFormData();
		$this->ufConfig['uploadCallbackUrl'] = $this->Link('upload');

		// Call the parent function but don't return it right away
		$return = parent::Field($properties);

		// Require some custom JS
		Requirements::javascript(S3_FILE_UPLOAD_DIR . '/js/S3UploadField_downloadtemplate.js');
		Requirements::javascript(S3_FILE_UPLOAD_DIR . '/js/S3UploadField.js');

		// We want this loaded after the default CSS rules to make sure it
		// overrides the parents
		Requirements::css(S3_FILE_UPLOAD_DIR . '/css/S3UploadField.css');

		return $return;
	}

	/**
	 * Get the S3 bucket name where the file will be uplaoded.
	 * @return string
	 */
	public function getBucket() {
		// If no bucket has been explicitly define, return the default
		// S3File bucket
		return ($this->bucket) ? $this->bucket : S3File::config()->Bucket;
	}

	/**
	 * Explicitly define the bucket to which the file will be uploaded.
	 * @param string $value
	 * @return S3FileUploadField
	 */
	public function setBucket($value) {
		$this->bucket = $value;
		return $this;
	}

	/**
	 * Get the AWS Region where the bucket is lcoated
	 * @return string
	 */
	public function getRegion() {
		// If no region has been explicitly define, return the default
		// S3File region
		return ($this->region) ? $this->region : S3File::config()->Region;
	}

	/**
	 * Explicitly define the region in which the bucket is located
	 * @param string $value
	 * @return S3FileUploadField
	 */
	public function setRegion($value) {
		$this->region = $value;
		return $this;
	}

	/**
	 * Generate the Form Data that will be passed along our upload request to
	 * AWS S3. This data will include signature based on our AccessID and
	 * secret. This will confirm to AWS that this upload request is legit.
	 *
	 * This function is based off an article by Edd Turtle
	 *
	 * @link https://www.designedbyaturtle.co.uk/2015/direct-upload-to-s3-using-aws-signature-v4-php/ Detailed explanation
	 * @return array
	 */
	protected function getFormData() {

		// Retrieve some basic information we'll be needing
		$bucket = $this->getBucket();
		$key = S3File::config()->AccessId;
		$secret = S3File::config()->Secret;
		$region = $this->getRegion();
		$acl = 'private';

		// Set som defaults
		$algorithm = "AWS4-HMAC-SHA256";
		$service = "s3";
		$date = gmdate('Ymd\THis\Z');
		$shortDate = gmdate('Ymd');
		$requestType = "aws4_request";
		$expires = "" . 60*60; // This request will be valid for an hour
		$successStatus = '201';

		$scope = [
			$key,
			$shortDate,
			$region,
			$service,
			$requestType
		];
		$credentials = implode('/', $scope);

		// Tells AWS under which condition this request will be valid
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

		// Get all our form data together
		$formData = array(
			array( 'name' => 'key', 'value' => uniqid('', true)), // This will be the name of our file in the S3 bucket
			array( 'name' => 'Content-Type', 'value' => ''),
			array( 'name' => 'acl', 'value' => $acl),
			array( 'name' => 'success_action_status', 'value' => $successStatus),
			array( 'name' => 'policy', 'value' => $base64Policy),
			array( 'name' => 'X-amz-algorithm', 'value' => $algorithm),
			array( 'name' => 'X-amz-credential', 'value' => $credentials),
			array( 'name' => 'X-amz-date', 'value' => $date),
			array( 'name' => 'X-amz-expires', 'value' => $expires),
			array( 'name' => 'X-amz-signature', 'value' => $signature),
		);

		return $formData;
	}

	/**
	 * Get the URL of our bucket based off the bucket name and its region.
	 * @return string URL
	 */
	public function getBucketUrl() {
		$region = $this->getRegion();

		// US general doesn't have its name in the bucket URL
		if ($region  == 'us-east-1') {
			$region = '';
		} else {
			$region = "-$region";
		}
		$bucket = $this->getBucket();

		return "https://$bucket.s3$region.amazonaws.com/";
	}

	/**
	 * Once the file has been uploaded to S3, the CMS will callback this action
	 * and pass along details about the file that we'll use to create an S3File
	 * DataObject.
	 *
	 * Will respond with an some JSON data about the new S3File DataObject so it
	 * can be added to the Form to which our S3FileUploadField is attached.
	 *
	 * Most of this has been adapted from the uplaod action of the UploadField.
	 * @param  SS_HTTPRequest $request
	 * @return SS_HTTPResponse
	 */
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


		// Create our S3File
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

	/**
	 * @param int $itemID
	 * @return S3FileUploadField_ItemHandler
	 */
	public function getItemHandler($itemID) {
		return S3FileUploadField_ItemHandler::create($this, $itemID);
	}

}
