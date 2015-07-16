<?php
class S3FileUploadField_ItemHandler extends UploadField_ItemHandler {

	public function getItem() {
		return DataObject::get_by_id('S3File', $this->itemID);
	}

}
