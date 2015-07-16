<?php

class S3File extends DataObject {

	private static $db = array(
		'Title' => 'Varchar(255)',
		'Location' => 'Varchar(255)',
		'Region' => 'Varchar(255)',
		'Bucket' => 'Varchar(255)',
		'Key' => 'Varchar(255)',
		'ETag' => 'Varchar(255)',
		'LastModified' => 'Datetime',
		'Name' => 'Varchar(255)',
		'Size' => 'Int',
		'Type' => 'Varchar(255)'
	);

	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$fields->removeByName('Location');
		$fields->removeByName('Region');
		$fields->removeByName('Bucket');
		$fields->removeByName('Key');
		$fields->removeByName('ETag');
		$fields->removeByName('Size');

		$fields->addFieldToTab('Root.Main',
			ReadonlyField::create('FakeSize', 'Size', $this->SizeForHuman));
		$fields->addFieldToTab('Root.Main',
			ReadonlyField::create('Type', 'Type'));
		$fields->addFieldToTab('Root.Main',
			DatetimeField_Readonly::create('LastModified', 'Last Modified'));


		return $fields;
	}

	protected function onBeforeWrite() {
		// If no title provided, reformat filename
		if (!$this->Title) {
			$this->Title = preg_replace('#\.[[:alnum:]]*$#', '', $this->Name);
			$this->Title =  preg_replace('#[[:punct:]]#', ' ', $this->Title);
			$this->Title =  preg_replace('#[[:blank:]]+#', ' ', $this->Title);
		}
		return parent::onBeforeWrite();
	}

	public function getFilename() {
		return '';
	}

	public function getExtension() {
		return File::get_file_extension($this->getField('Name'));
	}

	public function Icon() {
		$ext = strtolower($this->getExtension());
		if(!Director::fileExists(FRAMEWORK_DIR . "/images/app_icons/{$ext}_32.gif")) {
			$ext = $this->appCategory();
		}

		if(!Director::fileExists(FRAMEWORK_DIR . "/images/app_icons/{$ext}_32.gif")) {
			$ext = "generic";
		}

		return FRAMEWORK_DIR . "/images/app_icons/{$ext}_32.gif";
	}

	public function appCategory() {
		return File::get_app_category($this->getExtension());
	}

	public function getTemporaryDownloadLink() {
		if (!$this->ID) {
			return false;
		}
		$s3 = $this->getS3Client();

		$cmd = $s3->getCommand('GetObject', [
			'Bucket' => $this->Bucket,
			'Key'    => $this->Key,
			'ResponseContentDisposition' => 'attachment; filename="'. $this->Name .'"'
		]);

		$request = $s3->createPresignedRequest($cmd, '+60 minutes');

		return (string) $request->getUri();
	}

	private $s3 = null;

	private function getS3Client() {
		if (!$this->s3) {
			$this->s3 = new Aws\S3\S3Client(array(
				'credentials' => new Aws\Credentials\Credentials(
					self::config()->AccessId,
					self::config()->Secret
				),
				'region' => $this->Region,
				'version' => 'latest',
			));
		}
		return $this->s3;
	}

	public function getSizeForHuman() {
		return File::format_size($this->Size);
	}

	protected function onAfterDelete() {
		$s3 = $this->getS3Client();

		$cmd = $s3->getCommand('DeleteObject', [
			'Bucket' => $this->Bucket,
			'Key'    => $this->Key
		]);

		$s3->execute($cmd);

		return parent::onAfterDelete();
	}

	public function forTemplate() {
		return $this->renderWith($this->defaultTemplate());
	}

	protected function defaultTemplate() {
		return array('S3File');
	}

}
