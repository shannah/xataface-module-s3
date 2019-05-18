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
        require_once dirname(__FILE__).'/vendor/autoload.php';
        
        $app = Dataface_Application::getInstance();
        $app->registerEventListener('fileUpload', array(&$this, 'handleFileUpload'));
        $app->registerEventListener('handleGetBlob', array(&$this, 'handleGetBlob'));
        $app->registerEventListener('afterTableInit', array(&$this, 'afterTableInit'));
        $app->registerEventListener('HistoryTool.logField', array(&$this, 'logField'));
        $app->registerEventListener('delete_file', array(&$this, 'delete_file'));
        $app->registerEventListener('Dataface_Record.getMimetype', array(&$this, 'getMimetype'));
        $app->registerEventListener('afterDelete', array(&$this, 'afterDelete'));
    }

    public function afterDelete($params) {
        $record =& $params[0];
        //print_r($record);
        //echo "in afterDelete";
        if ($record) {
            $fields =& $record->_table->_fields;
            $s3 = null;
            foreach (array_keys($fields) as $fkey) {
                $field =& $fields[$fkey];
                if (@$field['Type'] == 'container' and @$field['s3.bucket']) {
                    //echo "Found s3 container";
                    $val = $record->val($field['name']);
                    if ($val) {
                        list($key) = explode(' ', $val);
                        if (!$s3) {
                            $s3 = $this->s3();

                        }
                        //echo "Deleting key $key";
                        $s3->deleteObject([
                            'Bucket' => $field['s3.bucket'],
                            'Key'    => $key
                        ]);

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
            $event->out = $mimetype;
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
        list($key, $mime, $fname) = explode(' ', $val);
        $s3 = $this->s3();
        $s3->deleteObject([
            'Bucket' => $field['s3.bucket'],
            'Key'    => $key
        ]);
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

    public function handleGetBlob($event) {
        $table = $event->table;
        $field = $event->field;
        $record = $event->record;
        if (!@$field['s3.bucket'] or $event->consumed) {
            return;
        }
        
        $event->consumed = true;
        $s3Client = $this->s3();
        $val = $record->val($field['name']);
        list($key, $mimetype, $fname) = explode(' ', $val);
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
        $url = (string)$request->getUri();
        //echo $url;exit;
        header('Location: '.$url);
        
    }

    public function handleFileUpload($event) {

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
        $event->consumed = true;
        $del = $table->getDelegate();

        $keyPrefix = '';
        if (@$field['s3.key.prefix']) {
            $keyPrefix = $field['s3.key.prefix'];
        }
        $key = df_uuid();

        $result = $this->upload_to_s3($tmpPath, $fileName, $field['s3.bucket'], $key);
        
        $mimetype = $event->mimetype;
        if (function_exists('mime_content_type')) {
            $tmp = mime_content_type($tmpPath);
            if ($tmp) {
                $mimetype = $tmp;
            }
        }
        
        $event->out = $key.' '.$mimetype.' '.$fileName;
        //echo $event->out;exit;

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
    

    private function s3() {
        $config = $this->getConfig();
        return new Aws\S3\S3Client([
            'region'  => $config['region'],
            'version' => 'latest',
            'credentials' => [
                'key'    => $config['key'],
                'secret' => $config['secret']
            ]
        ]);
    }
    
    public function upload_to_s3($file, $filename, $bucket, $key) {

        
        $s3 = $this->s3();

        // Send a PutObject request and get the result object.
        //$key = '-- your filename --';

        $result = $s3->putObject([
            'Bucket' => $bucket,
            'Key'    => $key,
            //'Body'   => 'this is the body!',
            'SourceFile' => $file
        ]);

        // Print the body of the result by indexing into the result object.
        return $result['ObjectURL'];
    }


}
