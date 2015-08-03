<?php

/**
 * RequestHandler for actions on a single item (S3File) of the S3FileUploadField
 *
 * This is a slight tweak to the standard UploadField_ItemHandler to allow it to
 * handle S3File DataObject instead of File.
 *
 * @author Maxime Rainville <max@firebrand.nz>
 * @package s3fileupload
 */
class S3FileUploadField_ItemHandler extends UploadField_ItemHandler {

	/**
	 * @return S3File
	 */
	public function getItem() {
		return DataObject::get_by_id('S3File', $this->itemID);
	}

}
