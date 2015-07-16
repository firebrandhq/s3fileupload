# Firebrand SilverStripe S3FileUpload

This SilverStripe plugin allows file to be uploaded directly to S3 and track as SilverStripe DataObjects.

## Requirements

 * SilverStripe 3.1
 * AWS SDK for PHP v3

## Installation

Install the module through [composer](http://getcomposer.org):

```bash
composer require firebrandhq/s3fileupload
```

## Setting up your S3 bucket

To be able to do a direct up load to S3, your bucket must have CORS Configuration. To Set up your CORS configuration:
 1. Log into S3 and access your bucket.
 2. Select *Properties* to view your bucket properties.
 3. Under *Permission*, select *Edit CORS Configuration*.
 4. In the pop up, input your CORS configuration in XML format.

### Sample CORS configuration
```xml
<?xml version="1.0" encoding="UTF-8"?>
<CORSConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
    <CORSRule>
        <AllowedOrigin>http://example.com</AllowedOrigin>
        <AllowedMethod>POST</AllowedMethod>
        <MaxAgeSeconds>3000</MaxAgeSeconds>
        <ExposeHeader>Location</ExposeHeader>
        <AllowedHeader>*</AllowedHeader>
    </CORSRule>
    <CORSRule>
        <AllowedOrigin>https://dev.example.com</AllowedOrigin>
        <AllowedMethod>POST</AllowedMethod>
        <MaxAgeSeconds>3000</MaxAgeSeconds>
        <ExposeHeader>Location</ExposeHeader>
        <AllowedHeader>*</AllowedHeader>
    </CORSRule>
    <CORSRule>
        <AllowedOrigin>http://localhost</AllowedOrigin>
        <AllowedMethod>POST</AllowedMethod>
        <MaxAgeSeconds>3000</MaxAgeSeconds>
        <ExposeHeader>Location</ExposeHeader>
        <AllowedHeader>*</AllowedHeader>
    </CORSRule>
</CORSConfiguration>
```

Note that you'll need a separate `CORSRule` entry for each domain from which you plan to upload files.

You'll also need a `CORSRule` entry for each protocol.

## Getting an Access Key and a Secret

You'll need to generate an AWS Access Key ID and Secret Access Key to allow SilverStripe to communicate with S3.

For security reasons, it is **STRONGLY RECOMMENDED** to create a new user with limited access. This will minimise the damage should your Access Key and/or Secret ever be compromised.

### Sample Policy for your AWS user
```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": "s3:ListAllMyBuckets",
            "Resource": "arn:aws:s3:::*"
        },
        {
            "Effect": "Allow",
            "Action": "s3:*",
            "Resource": [
                "arn:aws:s3:::name-of-your-bucket",
                "arn:aws:s3:::name-of-your-bucket/*"
            ]
        }
    ]
}
```

## Configuring SilverStripe
You can use a YML config file to tell SilverStripe how to access your bucket.

Create a `s3.yml` file in `mysite/_config`.

```yml
S3File:
  Bucket: 'name-of-your-bucket'
  Region: 'us-east-1'
  AccessId: 'YOUACCESSKEYID'
  Secret: 'YOURACCESSKEYSECRET'
```

You don't have to provide the Bucket or Region in YML file if you don't want to. Those can be manually set on your S3FileUploadField.

## Adding an S3File to a SilverStripe DataObject/Page

```PHP
<?php
class Page extends SiteTree {

	private static $has_one = array(
        'File' => 'S3File'
	);

    public function getCMSFields() {
		$fields = parent::getCMSFields();

		$s3Field = S3FileUploadField::create('File', 'S3 File')
            ->setAllowedMaxFileNumber(1);

        // You can omit the following 2 lines.
        // It will fallback on the YML configuration.
        $s3Field->setBucket('YourBucketName');
        $s3Field->setRegion('us-east-1');

		$fields->insertBefore(
			S3FileUploadField::create('S3File', 'S3 File')
				->setAllowedMaxFileNumber(1),
			'Description'
		);

		$fields->addFieldToTab('Root.Main',$s3Field);

		return $fields;
	}

}

```

### Reference the file in your SS Template
```HTML

<h2>Simple link</h2>
<p>
$File <br/>
You can customize this by overriding S3File.ss
</p>

<h2>Full file details</h2>
<ul>
    <li><strong>File name:</strong> $File.Name </li>
    <li><strong>Size:</strong> $File.SizeForHuman </li>
    <li><strong>Type:</strong> $File.Extension.UpperCase </li>
    <li><strong>Last modified:</strong> $File.LastModified.Nice </li>
</ul>

<a href="{$File.TemporaryDownloadLink}">Download</a>

```
