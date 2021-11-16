<?php
class modules_s3 {

    private $config = null;

	/**
	 * @brief The base URL to the datepicker module.  This will be correct whether it is in the 
	 * application modules directory or the xataface modules directory.
	 *
	 * @see getBaseURL()
	 */
	private $baseURL = null;
	/**
	 * @brief Returns the base URL to this module's directory.  Useful for including
	 * Javascripts and CSS.
	 *
	 */
	public function getBaseURL(){
		if ( !isset($this->baseURL) ){
			$this->baseURL = Dataface_ModuleTool::getInstance()->getModuleURL(__FILE__);
		}
		return $this->baseURL;
	}
	
	
	public function __construct(){
		// Include the SDK using the composer autoloader
		if (file_exists(XFAPPROOT.'vendor/autoload.php')) {
			import(XFAPPROOT.'vendor/autoload.php');
		} else {
			require_once dirname(__FILE__).'/vendor/autoload.php';
		}
        
        
        $app = Dataface_Application::getInstance();
        $app->registerEventListener('fileUpload', array(&$this, 'handleFileUpload'));
        $app->registerEventListener('handleGetBlob', array(&$this, 'handleGetBlob'));
        $app->registerEventListener('afterTableInit', array(&$this, 'afterTableInit'));
        $app->registerEventListener('HistoryTool.logField', array(&$this, 'logField'));
        $app->registerEventListener('delete_file', array(&$this, 'delete_file'));
        $app->registerEventListener('Dataface_Record.getMimetype', array(&$this, 'getMimetype'));
        $app->registerEventListener('afterDelete', array(&$this, 'afterDelete'));
		$app->registerEventListener('Dataface_Record.getThumbnailTypes', array(&$this, 'getThumbnailTypes'));
        $app->registerEventListener('Record::display', array(&$this, 'display'));
    }
	
	public function getThumbnailTypes($event) {
		$table = $event->table;
		$record = $event->record;
		$field =& $event->field;
		if (@$field['Type'] == 'container' and @$field['s3.bucket']) {
			$event->consumed = true;
			$event->out = [];
		} else {
			return;
		}
		
        $val = $record->val($field['name']);
		if ($val) {
	        $parts = explode(' ', $val);
        	$len = count($parts);
			if ($len > 4) {
				for ($i=4; $i<$len; $i++) {
					$event->out[] = $parts[$i];
				}
			}
		}

	}
    
    public function display($event) {
        // Handles the 'Record::display' event which is triggered when trying
        // to get the display value of a field.
        $field =& $event->field;
        
        
        
        if (@$field['s3.public'] and @$field['Type'] == 'container' and @$field['s3.bucket']) {
            //$table = $event->table;
            $record = $event->record;
            $value = $event->value;
            $thumb = null;
            if (!empty($event->thumb)) {
                $thumb = $event->thumb;
            }
            
            // We only override the display for fields that have s3.public
            // Other s3 fields will process the handleGetBlob event.
            
            $fieldValue = $record->val($field['name']);
            if (empty($fieldValue)) return;
            $valArr = $this->extractValue($fieldValue, $thumb);
            $event->value = 'https://' . $field['s3.bucket'] . '.s3.amazonaws.com/'.rawurlencode($valArr['key']);
            
        
        }
    }

    public function afterDelete($params) {
        $record =& $params[0];
        if ($record) {
            $fields =& $record->_table->_fields;
            $s3 = null;
            foreach (array_keys($fields) as $fkey) {
                $field =& $fields[$fkey];
                if (@$field['Type'] == 'container' and @$field['s3.bucket']) {
                    $val = $record->val($field['name']);
                    if ($val) {
                        list($key) = $parts = explode(' ', $val);
						$thumbs = [];
						if (count($parts) > 4) {
							for ($i=4; $i<count($parts); $i++) {
								$thumbs[] = $parts[$i];
							}
						}
                        if (!$s3) {
                            $s3 = $this->s3();

                        }
                        $s3->deleteObject([
                            'Bucket' => $field['s3.bucket'],
                            'Key'    => $key
                        ]);
						foreach ($thumbs as $thumb) {
	                        $s3->deleteObject([
	                            'Bucket' => $field['s3.bucket'],
	                            'Key'    => $key . '_thumb_' . $thumb
	                        ]);
						}

                    }
                }
            }
            
        }
        //exit;
        
    }

    public function getMimetype($event) {
        $field =& $event->field;
        if (!@$field['s3.bucket'] or $event->consumed) {
            return;
        }
        $event->consumed = true;
        $val = $event->record->val($field['name']);
        if ($val) {
            list($key, $mimetype, $fname) = explode(' ', $val);
            $event->out = $mimetype;
        } else {
            $event->out = 'application/octet-stream';
        }
    }

    public function delete_file($event) {
        $field =& $event->field;
        if (!@$field['s3.bucket'] or $event->consumed) {
            return;
        }
        $event->consumed = true;
        $val = $event->record->val($field['name']);
        if (!$val) {
            return;
        }
        list($key, $mime, $fname) = $parts = explode(' ', $val);
		$region = null;
		if (count($parts) > 3) {
			$region = $parts[3];
		}
		$thumbs = [];
		if (count($parts) > 4) {
			for ($i=4; $i<count($parts); $i++) {
				$thumbs[] = $parts[$i];
			}
		}
        $s3 = $this->s3();
        $s3->deleteObject([
            'Bucket' => $field['s3.bucket'],
            'Key'    => $key
        ]);
		foreach ($thumbs as $thumb) {
	        $s3->deleteObject([
	            'Bucket' => $field['s3.bucket'],
	            'Key'    => $key . '_thumb_' . $thumb
	        ]);
		}
    }

    public function logField($event) {

    }

    public function afterTableInit($event) {
        $fields =& $event->table->_fields;
        foreach (array_keys($fields) as $fname) {
            $field =& $fields[$fname];
            if (@$field['Type'] == 'container' and @$field['s3.bucket']) {
                $field['secure'] = 1;
            }
        }
    }
    
    private function extractValue($val, $thumb = null) {
        $parts = explode(' ', $val);
        $key = $parts[0];
        $mimetype = $parts[1];
        $fname = $parts[2];
        if (count($parts) > 3) {
            $region = $parts[3];
        } else {
            $region = null;
        }
		if ($thumb) {
			$foundThumb = false;
			if (count($parts) > 4) {
				for ($i=4; $i<count($parts); $i++) {
					if ($parts[$i] == $thumb) {
						$foundThumb = true;
						break;
					}
				}
			}
			if (!$foundThumb) {
				$thumb = null;
			}
			
		}
		if ($thumb) {
			$key = $key . '_thumb_' . $thumb;
		}
        return [
            'key' => $key,
            'mimetype' => $mimetype,
            'fname' => $fname,
            'region' => $region,
            'thumb' => $thumb
        ];
    }

    public function handleGetBlob($event) {
        
        // Event handler for the handleGetBlob event which is fired by Xataface
        // in the getBlob action.  This should redirect the user to the file.
        // Event structure:
        //  table => Dataface_Table object
        //  field => Field definition reference.  Associative array.
        //  record => Reference to Dataface_Record object.
        //  request => Reference to HTTP request assoc array.
        //  consumed => Input and Output boolean set to true if the event is handled.
        $table = $event->table;
        $field = $event->field;
        $record = $event->record;
		$request = $event->request;
		$thumb = @$request['-thumb'];
		if ($thumb == 'default') {
			$thumb = null;
		}
        if (!@$field['s3.bucket'] or $event->consumed) {
            return;
        }
        
        $event->consumed = true;
        
        $val = $record->val($field['name']);
        $valArr = $this->extractValue($val, $thumb);
		$key = $valArr['key'];
        $thumb = $valArr['thumb'];
        $region = $valArr['region'];
        $fname = $valArr['fname'];
        $mimetype = $valArr['mimetype'];
        $s3Client = $this->s3(['region' => $region]);
        $disposition = 'attachment; filename="'.$fname.'"';
        if ($record->isImage($field['name'])) {
            $disposition = 'inline';
        }
        $cmd = $s3Client->getCommand('GetObject', [
            'Bucket' => $field['s3.bucket'],
            'Key' => $key,
            'ResponseContentType' => $mimetype,
            'ResponseContentDisposition' => $disposition
        ]);
        
        $request = $s3Client->createPresignedRequest($cmd, '+20 minutes');
        $app = Dataface_Application::getInstance();
        $app->setCacheExpiry(19 * 60); // Set the cache expiry to be one minute less than the s3 expiry
        
        $url = (string)$request->getUri();
        $app->redirect($url);
        
    }

    public function handleFileUpload($event) {
        // Handles the 'handleFileUpload' event.
        // Event object structure:
        //  file_path => the local path to the file
        //  file_name => the local file name
        //  table => the Dataface_Table object
        //  field => reference to the field definition associative array
        //  out => Output string specifying the value to save in the 
        //      database.
        //  consumed => Output boolean set to true if the event is handled.
        $tmpPath = $event->file_path;
        $fileName = $event->file_name;
        $record = $event->record;
        $table = $event->table;
        $field =& $event->field;
        

        if (!@$field['s3.bucket'] or $event->consumed) {
            // If no bucket is specified
            // we just do nothing
            return;
        }
        $public = @$field['s3.public'];
        
        $event->consumed = true;
        $del = $table->getDelegate();

        $keyPrefix = '';
        if (@$field['s3.key.prefix']) {
            $keyPrefix = $field['s3.key.prefix'];
        }
        $key = df_uuid();
        $params = [];
        if ($public) {
            $params['ACL'] = 'public-read';
        }
        $result = $this->upload_to_s3($tmpPath, $fileName, $field['s3.bucket'], $key, null, $params);
        
        $mimetype = $event->mimetype;
        if (function_exists('mime_content_type')) {
            $tmp = mime_content_type($tmpPath);
            if ($tmp) {
                $mimetype = $tmp;
            }
        }
        $config = $this->getConfig();
        $event->out = $key.' '.$mimetype.' '.$fileName.' '.$config['region'];
		$event->mimetype = $mimetype;
		

		if (@$field['transform']) {

			$commands = array_map('trim', explode(';', $field['transform']));
			//print_r($commands);
			foreach ($commands as $command) {
				if (!trim($command)) {
					continue;
				}
				list($nameAndOp, $arg) = array_map('trim', explode(':', $command));
				if (!$nameAndOp) {
					throw new Exception("No name/op specified for field transform.");
				}
				if (!$arg) {
					throw new Exception("No argument provided for transform ".$nameAndOp);
				}
				$op = null;
				list($thumbName, $op) = @explode(' ', $nameAndOp);
				if (!$thumbName) {
					throw new Exception("No name provided for transform operation ".$command);

				}

				if (!$op) {
					$op = $thumbName;
					$thumbName = "default";
				}

				$thumbDir = sys_get_temp_dir();
				if (!file_exists($thumbDir)) {
					if (!mkdir($thumbDir)) {
						throw new Exception("Failed to create directory ".$thumbDir);
					}
				}

				$thumbPath = tempnam($thumbDir, 'thumb'.basename($thumbName));
				if (file_exists($thumbPath)) {
					if (!unlink($thumbPath)) {
						throw new Exception("Failed to delete old thumbnail ".$thumbPath);
					}
				}

				import(XFROOT.'xf/image/crop.php');
				$crop = new \xf\image\Crop;

				list($dimensions) = array_map('trim', explode(' ', $arg));
				list($maxWidth, $maxHeight) = array_map('intval', explode('x', $dimensions));

				$cropped = false;
				$thumbKey = $key.'_thumb_'.$thumbName;
				if ($thumbName == 'default') {
					$thumbKey = $key;
				}
				switch ($op) {
					case 'fit' :
						// we fit the image to the given dimensions
						$result = $crop->fit($tmpPath, $thumbPath, $maxWidth, $maxHeight, $mimetype);
						if ($result) {
							$result = $this->upload_to_s3($thumbPath, $fileName, $field['s3.bucket'], $thumbKey, null, $params);
							$cropped = $result;
						}
						
						break;
					case 'fill' :
						// we fill the given dimensions with the image
						$result = $crop->fill($tmpPath, $thumbPath, $maxWidth, $maxHeight, $mimetype);
						//exit;
						if ($result) {
							$result = $this->upload_to_s3($thumbPath, $fileName, $field['s3.bucket'], $thumbKey, null, $params);
							$cropped = $result;
						} else {
							throw new Exception("Failed to fill thumbnail: $thumbPath, $fileName, $thumbKey");
						}
						
						break;

				}
				//$event->metaValues[$thumbFieldName] = $thumbKey.' '.$mimetype.' '.$fileName.' '.$config['region'];
				
				if ($cropped and $thumbName != 'default') {
					$event->out .= ' ' . $thumbName;
				}
				
				@unlink($thumbPath);


			}
		}
		


    }
	

    
    private function getConfig() {
        if (!isset($this->config)) {
            $appConfig =& Dataface_Application::getInstance()->_conf;
            if (!isset($appConfig['modules_s3'])) {
                die("s3 module requires modules_s3 section in conf.ini file.");
            }
            $this->config = $appConfig['modules_s3'];
            if (!isset($this->config['key'])) {
                die("s3 module requires 'key' property in modules_s3 section of conf.ini");
            }
            if (!isset($this->config['secret'])) {
                die("s3 module requires 'secret' property in modules_s3 section of conf.ini");
            }
            if (!isset($this->config['region'])) {
                die("s3 module requireds 'region' property in modules_s3 section of conf.ini");
            }
        }
        return $this->config;
    }
    

    private function s3($options = []) {
        $config = $this->getConfig();
        if ($options and @$options['region']) {
            $config['region'] = $options['region'];
        }
        return new Aws\S3\S3Client([
            'region'  => $config['region'],
            'version' => 'latest',
            'credentials' => [
                'key'    => $config['key'],
                'secret' => $config['secret']
            ]
        ]);
    }
    
    public function upload_to_s3($file, $filename, $bucket, $key, $content=null, $params = []) {

        
        $s3 = $this->s3();

        // Send a PutObject request and get the result object.
        //$key = '-- your filename --';
        if (!empty($file)) {
            //'ACL' => 'public-read'
            $p = array_merge(
                $params, [
                    'Bucket' => $bucket,
                    'Key'    => $key,
                    //'Body'   => 'this is the body!',
                    'SourceFile' => $file
            ]);
            $result = $s3->putObject($p);
        } else {
            $p = array_merge($params, [
                'Bucket' => $bucket,
                'Key'    => $key,
                'Body'   => $content,
            ]) ;
            
            $result = $s3->putObject($p);
        }

        
        // Print the body of the result by indexing into the result object.
        return $result['ObjectURL'];
    }


}
