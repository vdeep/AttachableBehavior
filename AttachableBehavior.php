<?php

// The behavior uses the Folder and File Utility classes
App::uses('Folder', 'Utility');
App::uses('File', 'Utility');

class AttachableBehavior extends ModelBehavior {

	private $_defaults = array(
		'attachments' => array(
			'file' => array(
				'dir' => 'files',						// location of the folder in webroot folder, can specify subfolders also
				'types' => '*',							// use '*' for all file types, or put an array of mime types
				'extensions' => '*',
				'maxSize' => 5242880,					// 5 MB (in bytes)
				'physicalName' => '{ID}-{FILENAME}',	// name for the file to save, filename will be converted to lowercase and any special characters and spaces will be removed
				'errorMessages' => array(
					'DIRECTORY_NOT_WRITABLE' => 'Directory not writable.',
					'INVALID_FILE_TYPE' => 'This file type is not supported.',
					'INVALID_FILE_SIZE' => 'The file is too large to upload.',
				),
				'createDir' => true,
			),
		),
		'baseDir' => 'uploads',
		'dir' => 'files',
		'types' => '*',
		'extensions' => '*',
		'maxSize' => 5242880,
		'physicalName' => '{ID}-{FILENAME}',
		'errorMessages' => array(
			'DIRECTORY_NOT_WRITABLE' => 'Directory not writable.',
			'DIRECTORY_DOES_NOT_EXIST' => "The target directory doesn't exist",
			'INVALID_FILE_TYPE' => 'This file type is not supported.',
			'INVALID_FILE_EXTENSION' => 'This file type is not supported.',
			'INVALID_FILE_SIZE' => 'The file is too large to upload.',
			'ERROR_UPLOADING_FILE' => 'There was an error uploading the file.',
			'FILE_NOT_UPLOADED' => 'The file was not properly uploaded.',
			'PARENT_DIRECTORY_NOT_WRITABLE' => 'The parent directory is now writable.',
		),
		'createDir' => true,
	);

	public function setup(Model $Model, $options = array()) {
		$this->settings[$Model->alias] = array_merge($this->_defaults, $options);
	}

	public function beforeSave(Model $Model) {
		$attachments = $this->settings[$Model->alias]['attachments'];
		foreach ($attachments as $label => $options) {
			if (isset($Model->data[$Model->alias][$label])) {
				$attachment = $Model->data[$Model->alias][$label];
				if (!empty($attachment['tmp_name']) && empty($Model->validationErrors)) {
					$this->uploadAttachment($Model, $label, $attachment, $options);
				} else {
					unset($Model->data[$Model->alias][$label]);
				}
			}
		}
		if (!empty($Model->validationErrors)) {
			$this->removeUploadedImages($Model, $attachments);
			return false;
		}
		return true;
	}

	public function beforeDelete(Model $Model, $cascade) {
		// remove the existing files for the record
		$attachments = $this->settings[$Model->alias]['attachments'];
		
		$data = $Model->findById($Model->id);

		foreach ($attachments as $label => $options) {
			$attachment = $data[$Model->alias][$label];
			if (!empty($attachment)) {
				// get the real path for the attachment
				$targetDirectoryPath = $this->getTargetDirectoryPath($Model, $label);
				$filePath = $targetDirectoryPath . DS . $attachment;
				$file = new File($filePath);
				$file->delete();
			}
		}
		return true;
	}

	private function uploadAttachment(Model $Model, $label = "", $attachment = array(), $options = array()) {
		$createDir = $this->getSetting($Model, $label, 'createDir');

		if ( !$this->isValidFileType($Model, $label, $attachment) ) return;
		if ( !$this->isValidFileExtension($Model, $label, $attachment) ) return;
		if ( !$this->isValidFileSize($Model, $label, $attachment) ) return;
		if ( !$this->isFileUploaded($Model, $label, $attachment) ) return;
		if ( !($createDir || $this->isTargetDirectoryAvailable($Model, $label) ) ) return;
		if ( !( $this->isTargetDirectoryWritable($Model, $label) || ( $createDir && $this->isTargetParentDirectoryWritable($Model, $label) ) ) ) return;

		if ($this->uploadFile($Model, $label, $attachment, $options)) {
			$this->removePreviousImage($Model, $label);
		}
	}

	private function removePreviousImage(Model $Model, $label = "") {
		// check if the record is being edited
		if (property_exists($Model, 'id')) {
			$data = $Model->findById($Model->id);
			$fileName = $data[$Model->alias][$label];
			$currentFileName = $Model->data[$Model->alias][$label];
			// check if the uploaded file is of same name as previous file
			if ($currentFileName !== $fileName && !empty($fileName)) {
				$targetDirectoryPath = $this->getTargetDirectoryPath($Model, $label);
				$targetFilePath = $targetDirectoryPath . DS . $fileName;
				//debug($targetFilePath);
				$file = new File($targetFilePath);
				$file->delete();
			}
		}
	}

	/**
	 * Removes the uploaded images in case of error in any of the field
	 */

	private function removeUploadedImages(Model $Model, $attachments) {
		foreach ($attachments as $label => $options) {
			if (isset($Model->data[$Model->alias][$label])) {
				$attachment = $Model->data[$Model->alias][$label];
				if ( ( !is_array($attachment) && !empty($attachment) ) || ( is_array($attachment) && !empty($attachment['tmp_name']) ) ) {
					if (is_array($attachment)) {
						$physicalFileName = $this->getPhysicalFileName($Model, $label, $attachment);
					} else {
						$physicalFileName = $attachment;
					}
					$targetDirectoryPath = $this->getTargetDirectoryPath($Model, $label);
					$targetFileFullPath = $targetDirectoryPath . DS . $physicalFileName;
					$file = new File($targetFileFullPath);
					$file->delete();
				}
			}
		}
	}

	private function isFileUploaded(Model $Model, $label = "", $attachment = array()) {
		$result = false;
		if (!empty($attachment['tmp_name'])) {
			$result = is_uploaded_file($attachment['tmp_name']);
		}
		if (!$result) {
			$Model->validationErrors[$label] = $this->getSetting($Model, $label, 'errorMessages.FILE_NOT_UPLOADED');
		}
		return $result;
	}

	private function isValidFileType(Model $Model, $label = "", $attachment = array()) {
		$validFileTypes = $this->getSetting($Model, $label, 'types');
		if (is_array($validFileTypes)) {
			if (in_array(strtolower($attachment['type']), $validFileTypes) || in_array('*', $validFileTypes)) {
				return true;
			}
		} else if ($validFileTypes = '*') {
			return true;
		}
		$Model->validationErrors[$label] = $this->getSetting($Model, $label, 'errorMessages.INVALID_FILE_TYPE');
		return false;
	}

	private function isValidFileExtension(Model $Model, $label = "", $attachment = array()) {
		$validFileExtensions = $this->getSetting($Model, $label, 'extensions');
		if (is_array($validFileExtensions)) {
			$extension = $this->getFileExtension($attachment);
			if (in_array(strtolower($extension), $validFileExtensions) || in_array('*', $validFileExtensions)) {
				return true;
			}
		} else if ($validFileTypes = '*') {
			return true;
		}
		$Model->validationErrors[$label] = $this->getSetting($Model, $label, 'errorMessages.INVALID_FILE_EXTENSION');
		return false;
	}

	private function isValidFileSize(Model $Model, $label = "", $attachment = array()) {
		$validFileSize = $this->getSetting($Model, $label, 'maxSize');
		if ($validFileSize > $attachment['size'] || $validFileSize == 0) {
			return true;
		}
		$Model->validationErrors[$label] = $this->getSetting($Model, $label, 'errorMessages.INVALID_FILE_SIZE');
		return false;
	}

	private function isTargetDirectoryAvailable(Model $Model, $label = "") {
		$targetDirectoryPath = $this->getTargetDirectoryPath($Model, $label);
		if ($this->folderExists($targetDirectoryPath)) {
			return true;
		}
		$Model->validationErrors[$label] = $this->getSetting($Model, $label, 'errorMessages.DIRECTORY_DOES_NOT_EXIST');
		return false;
	}

	private function isTargetDirectoryWritable(Model $Model, $label = "") {
		$targetDirectory = $this->getTargetDirectoryPath($Model, $label);
		if (is_dir($targetDirectory) && is_writable($targetDirectory)) {
			return true;
		}
		$Model->validationErrors[$label] = $this->getSetting($Model, $label, 'errorMessages.DIRECTORY_NOT_WRITABLE');
		return false;
	}

	private function isTargetParentDirectoryWritable(Model $Model, $label = "") {
		$targetDirectory = $this->getTargetDirectoryPath($Model, $label);
		$targetParent = dirname($targetDirectory);
		if (is_dir($targetParent) && is_writable($targetParent)) {
			return true;
		}
		$Model->validationErrors[$label] = $this->getSetting($Model, $label, 'errorMessages.PARENT_DIRECTORY_NOT_WRITABLE');
		return false;
	}

	private function getPhysicalFileName(Model $Model, $label = "", $attachment = array()) {
		$fileNameFormat = $this->getSetting($Model, $label, 'physicalName');

		$id = null;
        if(isset($Model->data[$Model->alias]['id']) && !empty($Model->data[$Model->alias]['id'])){
            $id = $Model->data[$Model->name]['id'];
        } else {
            $query = $Model->query("SHOW TABLE STATUS LIKE '" . $Model->table . "'");
            $id = $query[0]['TABLES']['Auto_increment'];
        }
        
        $replacement_array = array(
            '{ID}' => $id,
            '{FILENAME}' => Inflector::slug($this->getFileName($attachment)),
            '{TIMESTAMP}' => time(),
        );

        $physicalNameFormat = $this->getSetting($Model, $label, 'physicalName');

        return strtolower(strtr($physicalNameFormat, $replacement_array) . '.' .$this->getFileExtension($attachment));
	}

	private function getSetting(Model $Model, $label, $name) {
		$names = explode('.', $name);
		$key = $names[0];
		unset($names[0]);
		
		$modelSettings = $this->settings[$Model->alias];

		if (isset($modelSettings['attachments'][$label][$key])) {
			$returnValue = $this->getKeyValue($modelSettings['attachments'][$label][$key], $names);
			if ($returnValue !== null) {
				return $returnValue;
			}
		}

		if (isset($modelSettings[$key])) {
			$returnValue = $this->getKeyValue($modelSettings[$key], $names);
			if ($returnValue !== null) {
				return $returnValue;
			}
		}

		return null;
	}

	private function getKeyValue($array = null, $keys = null) {
		if (!empty($array) && !empty($keys)) {
			foreach ($keys as $key) {
				if (isset($array[$key])) {
					$array = $array[$key];
				} else {
					$array = null;
				}
			}
		}
		return $array;
	}

	/**
	 * Get the normalized name of the file
	 */
	
	private function getFileName($attachment = null) {
		if ($attachment) {
			// get the filename and return
			$file = new File($attachment['name']);
			return $file->name();
		}
		return null;
	}

	/**
	 * Get the file extension
	 */

	private function getFileExtension($attachment = null) {
		if ($attachment) {
			$file = new File($attachment['name']);
			return $file->ext();
		}
		return null;
	}

	/**
	 * Returns the destination path of the file to be uploaded
	 */

	private function getTargetDirectoryPath(Model $Model, $label = "") {
		$dir = $this->getSetting($Model, $label, 'dir');
		$baseDir = $this->getSetting($Model, $label, 'baseDir');

		$path = WWW_ROOT . $baseDir . DS . $dir;
		return $path;
	}

	/**
	 * Create the directory
	 */

	private function createTargetDirectory($targetDirectory = "") {
		if ($targetDirectory) {
			if (!$this->folderExists($targetDirectory)) {
				$folder = new Folder($targetDirectory, true, 0755);
				return $folder->path;
			} else {
				return $targetDirectory;
			}
		}
		return false;
	}

	private function folderExists($folder = "") {
		if ($folder) {
			if (is_dir($folder)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Uploads the file and returns the uploaded file name
	 */

	private function uploadFile(Model $Model, $label = "", $attachment = array()) {
		$physicalFileName = $this->getPhysicalFileName($Model, $label, $attachment);
		
		$targetDirectoryPath = $this->getTargetDirectoryPath($Model, $label);
		
		$createDir = $this->getSetting($Model, $label, 'createDir');
		
		if ( $this->createTargetDirectory($targetDirectoryPath) ) {
			$targetFileFullPath = $targetDirectoryPath . DS . $physicalFileName;
			if (move_uploaded_file($attachment['tmp_name'], $targetFileFullPath)) {
				$Model->data[$Model->alias][$label] = $physicalFileName;
				return true;
			}
		}
		$Model->validationErrors[$label] = $this->getSetting($Model, $label, 'errorMessages.ERROR_UPLOADING_FILE');
		return false;
	}

}