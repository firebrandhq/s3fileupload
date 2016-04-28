<?php
/**
 * DataObject reprensetation of a file stored in AWS S3.
 *
 * Allows you to retrieve the file once its been uplaoded to S3. You can link
 * other data object to this class to define relationship to your S3 file.
 *
 * @author Maxime Rainville <max@firebrand.nz>
 * @package s3fileupload
 */
class S3File extends File
{

    /**
     * Database property of an S3 File
     * @var array
     */
    private static $db = array(
        'Title' => 'Varchar(255)', // Propert title for the file
        'Location' => 'Varchar(255)', // Public URL of the file
        'Region' => 'Varchar(255)', // AWS region of the bucket
        'Bucket' => 'Varchar(255)', // AWS Bucket name
        'Key' => 'Varchar(255)', // Filename under which the file is stored in S3
        'ETag' => 'Varchar(255)', // ETag value returned by S3, usually an MD5 Hash
        'LastModified' => 'Datetime', // Date the file was last modified as reported by the browser
        'Name' => 'Varchar(255)', // Original name at upload
        'Size' => 'Int', // Site of the file in bytes
        'Type' => 'Varchar(255)' // File type as reported by the browser at upload
    );

    /**
     * Custimize the form fields to edit this S3 File
     * @return FieldList Form fields to edit this S3 File in the CMS
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        // Hide those fields because those values are not useful for standard users.
        $fields->removeByName('Location');
        $fields->removeByName('Region');
        $fields->removeByName('Bucket');
        $fields->removeByName('Key');
        $fields->removeByName('ETag');
        $fields->removeByName('Size');

        // Add a human friendly readonly size field
        $fields->addFieldToTab('Root.Main',
            ReadonlyField::create('FakeSize', 'Size', $this->SizeForHuman));

        // Display a few usefull bits of data in readonly mod.
        $fields->addFieldToTab('Root.Main',
            ReadonlyField::create('Type', 'Type'));
        $fields->addFieldToTab('Root.Main',
            DatetimeField_Readonly::create('LastModified', 'Last Modified'));


        return $fields;
    }

    /**
     * If not title is provided, guess a title from the file name before
     * writting the S3File info to the DB
     *
     * @return null
     */
    protected function onBeforeWrite()
    {
        // If no title provided, reformat filename
        if (!$this->Title) {
            // Strip the extension
            $this->Title = preg_replace('#\.[[:alnum:]]*$#', '', $this->Name);
            // Replace all punctuation with space
            $this->Title =  preg_replace('#[[:punct:]]#', ' ', $this->Title);
            // Remove unecessary spaces
            $this->Title =  preg_replace('#[[:blank:]]+#', ' ', $this->Title);
        }
        return parent::onBeforeWrite();
    }

    /**
     * The UploadField template expect a filename attribute, so it can retrieve
     * a thumbnail. Since we don't need this we just return an empty string.
     *
     * This function is  meant to mimic the equivalent for File.
     *
     * @return string blank
     */
    public function getFilename()
    {
        return '';
    }

    /**
     * Return the extension of the file based on its name.
     *
     * This function is  meant to mimic the equivalent for File.
     *
     * @return string File extension
     */
    public function getExtension()
    {
        return File::get_file_extension($this->getField('Name'));
    }

    /**
     * URL to an appropriate 32x32 pixel icon.
     *
     * This function is  meant to mimic the equivalent for File.
     *
     * @return string Icon URL
     */
    public function Icon()
    {
        $ext = strtolower($this->getExtension());
        if (!Director::fileExists(FRAMEWORK_DIR . "/images/app_icons/{$ext}_32.gif")) {
            $ext = $this->appCategory();
        }

        if (!Director::fileExists(FRAMEWORK_DIR . "/images/app_icons/{$ext}_32.gif")) {
            $ext = "generic";
        }

        return FRAMEWORK_DIR . "/images/app_icons/{$ext}_32.gif";
    }

    /**
     * Returns a category based on the file extension.
     *
     * This function is  meant to mimic the equivalent for File.
     *
     * @return String
     */
    public function appCategory()
    {
        return File::get_app_category($this->getExtension());
    }

    /**
     * Generate a presigned URL to download the file. This will allow users to
     * download the file without making the file public for everyone to see or
     * download.
     *
     * The URL will be valid for only 60 minutes.
     *
     * @return string URL to download file
     */
    public function getTemporaryDownloadLink()
    {
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


    /**
     * Generate a human friendly reprensetation of the size of this S3file
     * @return string
     */
    public function getSizeForHuman()
    {
        return File::format_size($this->Size);
    }

    /**
     * AWS SDK S3 client
     * @var Aws\S3\S3Client
     */
    private $s3 = null;

    /**
     * Get a AWS SDK S3 Client to prepare and execute S3 request against your bucket
     * @return Aws\S3\S3Client
     */
    protected function getS3Client()
    {
        // If we haven't call this function already
        if (!$this->s3) {
            // Generate a brand new instance and keep a reference to it.
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

    /**
     * Delete the file from the S3 bucket when the S3File DataObject gets
     * deleted.
     * @return
     */
    protected function onAfterDelete()
    {
        $s3 = $this->getS3Client();

        $cmd = $s3->getCommand('DeleteObject', [
            'Bucket' => $this->Bucket,
            'Key'    => $this->Key
        ]);

        $s3->execute($cmd);

        return parent::onAfterDelete();
    }

    /**
     * Display a reprensetation of this S3File in a SS template/
     * @return SSViewable
     */
    public function forTemplate()
    {
        return $this->renderWith($this->defaultTemplate());
    }

    /**
     * List of templates to render this S3File
     * @return array
     */
    protected function defaultTemplate()
    {
        return array('S3File');
    }
}
