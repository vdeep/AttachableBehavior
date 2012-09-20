## AttachableBehavior
This is a behavior that helps manage the uploading of files. It'll upload the files to the specified directories. It will also delete the files in case of record being deleted as well as when the file is being re-uploaded for the same record.
#####Usage and configuration
Copy the file `AttachableBehavior.php` to your `Model/Behavior` folder. The minimum configuration you'll need to specify in your model will be:
```php
public $actsAs = array(
	'Attachable' => array(
		'attachments' => array(
			'file' => array(
				'dir' => 'files',
			),
		),
		'baseDir' => 'img/uploads',
		'physicalName' => '{ID}-{FILENAME}',
		'types' => '*',
		'extensions' => '*',
		'maxSize' => 1048576,
	),
);
```
The above configuration specifies that the field `file` is an attachment field in the current model, which will be uploaded to `baseDir + dir` (`img/uploads/files` in our case). You can specify any directory name, that directory will be created in the `webroot` folder in the `App`.
The `physicalName` field specifies the target name of the file. 

There are three placeholders available currently:

1. `{ID}` - ID of the record for which the file is being uploaded.
2. `{FILENAME}` - Actual name of the file, sanitized.
3. `{TIMESTAMP}` - Timestamp of the time on the server.

The `types` is an array containing uploadable 'mime-types'. The `extensions` is an array containing the file extensions. Only the files with allowed extensions can be uploaded. The `maxSize` specifies the maximum uploadable size of the file.

For more configuration options, you can check the `$defaults` array in the behavior.