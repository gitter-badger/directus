<?php

namespace Directus\Files;

use Directus\Bootstrap;
use Directus\Filesystem\Filesystem;
use Directus\Filesystem\FilesystemFactory;
use Directus\Hook\Hook;
use Directus\Db\TableGateway\DirectusSettingsTableGateway;
use Directus\Util\Formatting;
use Directus\Files\Thumbnail;
use League\Flysystem\Config as FlysystemConfig;
use League\Flysystem\FileNotFoundException;

class Files
{
    private $config = [];
    private $filesSettings = [];
    private $filesystem = null;
    private $defaults = [
        'caption'   =>  '',
        'tags'      =>  '',
        'location'  =>  ''
    ];

    public function __construct()
    {
        $acl = Bootstrap::get('acl');
        $adapter = Bootstrap::get('ZendDb');
        $this->filesystem = Bootstrap::get('filesystem');
        $config = Bootstrap::get('config');
        $this->config = $config['filesystem'] ?: [];

        // Fetch files settings
        $Settings = new DirectusSettingsTableGateway($acl, $adapter);
        $this->filesSettings = $Settings->fetchCollection('files', array(
            'thumbnail_size', 'thumbnail_quality', 'thumbnail_crop_enabled'
        ));
    }

    // @TODO: remove exists() and rename() method
    // and move it to Directus\Filesystem Wraper
    public function exists($path)
    {
        return $this->filesystem->getAdapter()->has($path);
    }

    public function rename($path, $newPath)
    {
        return $this->filesystem->getAdapter()->rename($path, $newPath);
    }

    public function delete($file)
    {
        if ($this->exists($file['name'])) {
            Hook::run('files.deleting', array($file));
            $this->filesystem->getAdapter()->delete($file['name']);
            Hook::run('files.deleting:after', array($file));
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if ($ext) {
            $thumbPath = 'thumbs/'.$file['id'].'.'.$ext;
            if ($this->exists($thumbPath)) {
                Hook::run('files.thumbnail.deleting', array($file));
                $this->filesystem->getAdapter()->delete($thumbPath);
                Hook::run('files.thumbnail.deleting:after', array($file));
            }
        }
    }

    /**
     * Copy $_FILES data into directus media
     *
     * @param Array $_FILES data
     *
     * @return Array directus file info data
     */
    public function upload(Array $file)
    {
        $filePath = $file['tmp_name'];
        $fileName = $file['name'];

        try {
            $fileData = array_merge($this->defaults, $this->processUpload($filePath, $fileName));
            $this->createThumbnails($fileData['name']);
        } catch (FileNotFoundException $e) {
            echo $e->getMessage();
            exit;
        } catch (\Exception $e) {
            echo $e->getMessage();
            exit;
        }

        return [
            'type' => $fileData['type'],
            'name' => $fileData['name'],
            'title' => $fileData['title'],
            'tags' => $fileData['tags'],
            'caption' => $fileData['caption'],
            'location' => $fileData['location'],
            'charset' => $fileData['charset'],
            'size' => $fileData['size'],
            'width' => $fileData['width'],
            'height' => $fileData['height'],
            //    @TODO: Returns date in ISO 8601 Ex: 2016-06-06T17:18:20Z
            //    see: https://en.wikipedia.org/wiki/ISO_8601
            'date_uploaded' => $fileData['date_uploaded'],// . ' UTC',
            'storage_adapter' => $fileData['storage_adapter']
        ];
    }

    /**
     * Get URL info
     *
     * @param string $url
     *
     * @return Array
     */
    public function getLink($link)
    {
        $settings = $this->filesSettings;
        $fileData = array();

        if (strpos($link,'youtube.com') !== false) {
          // Get ID from URL
          parse_str(parse_url($link, PHP_URL_QUERY), $array_of_vars);
          $video_id = $array_of_vars['v'];

          // Can't find the video ID
          if($video_id === FALSE){
            die(__t('x_video_id_not_detected', ['service' => 'YouTube']));
          }

          $fileData['url'] = $video_id;
          $fileData['type'] = 'embed/youtube';
          $fileData['height'] = 340;
          $fileData['width'] = 560;

          $fileData['name'] = "youtube_" . $video_id . ".jpg";
          $fileData['date_uploaded'] = gmdate('Y-m-d H:i:s');
          $fileData['storage_adapter'] = $this->getConfig('adapter');
          $fileData['charset'] = '';

          //If Youtube API Key set, hit up youtube API
          if(array_key_exists('youtube_api_key', $settings) && !empty($settings['youtube_api_key'])) {
            // Get Data
            $youtubeFormatUrlString = "https://www.googleapis.com/youtube/v3/videos?id=%s&key=%s&part=snippet,contentDetails";
            $url = sprintf($youtubeFormatUrlString, $video_id, $settings['youtube_api_key']);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_URL,$url);
            $content=curl_exec($ch);

            $dataRetrieveErrored = false;
            if ($content !== false) {
                $content = json_decode($content);
                if(!is_null($content) && property_exists($content, 'items') && sizeof($content->items) > 0) {
                    $videoDataSnippet = $content->items[0]->snippet;
                    $fileData['title'] = $videoDataSnippet->title;
                    $fileData['caption'] = $videoDataSnippet->description;
                    $fileData['tags'] = implode(',', $videoDataSnippet->tags);

                    $videoContentDetails = $content->items[0]->contentDetails;
                    $videoStart = new \DateTime('@0'); // Unix epoch
                    $videoStart->add(new \DateInterval($videoContentDetails->duration));
                    $fileData['size'] = $videoStart->format('U');
                } else if(property_exists($content, 'error')) {
                    throw new \Exception(__t('bad_x_api_key', ['service' => 'YouTube']));
                } else {
                    $dataRetrieveErrored = true;
                }
            } else {
                $dataRetrieveErrored = true;
            }

            // an error happened
            if($dataRetrieveErrored) {
                $fileData['title'] = __t('unable_to_retrieve_x_title', ['service' => 'YouTube']);
                $fileData['size'] = 0;
            }
          } else {
              //No API Key is set, use generic title
              $fileData['title'] = __t('x_video', ['service' => 'YouTube']).": " . $video_id;
              $fileData['size'] = 0;
          }

          $linkContent = file_get_contents('http://img.youtube.com/vi/' . $video_id . '/0.jpg');
          $fileData['data'] = 'data:image/jpeg;base64,' . base64_encode($linkContent);
        } else if(strpos($link,'vimeo.com') !== false) {
        // Get ID from URL
          preg_match('/vimeo\.com\/([0-9]{1,10})/', $link, $matches);
          $video_id = $matches[1];

          // Can't find the video ID
          if($video_id === FALSE){
            die(__t('x_video_id_not_detected', ['service' => 'Vimeo']));
          }

          $fileData['url'] = $video_id;
          $fileData['type'] = 'embed/vimeo';

          $fileData['name'] = "vimeo_" . $video_id . ".jpg";
          $fileData['date_uploaded'] = gmdate('Y-m-d H:i:s');
          $fileData['storage_adapter'] = $this->getConfig('adapter');
          $fileData['charset'] = '';

          // Get Data
          $url = 'http://vimeo.com/api/v2/video/' . $video_id . '.php';
          $ch = curl_init($url);
          curl_setopt ($ch, CURLOPT_RETURNTRANSFER, true);
          curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, 0);
          $content = curl_exec($ch);
          curl_close($ch);
          $array = unserialize(trim($content));

          if($content !== false) {
            $fileData['title'] = $array[0]['title'];
            $fileData['caption'] = strip_tags($array[0]['description']);
            $fileData['size'] = $array[0]['duration'];
            $fileData['height'] = $array[0]['height'];
            $fileData['width'] = $array[0]['width'];
            $fileData['tags'] = $array[0]['tags'];
            $vimeo_thumb = $array[0]['thumbnail_large'];

            // $img = Thumbnail::generateThumbnail($vimeo_thumb, 'jpeg', $settings['thumbnail_size'], $settings['thumbnail_crop_enabled']);
            // $thumbnailTempName = tempnam(sys_get_temp_dir(), 'DirectusThumbnail');
            // Thumbnail::writeImage('jpg', $thumbnailTempName, $img, $settings['thumbnail_quality']);
            // if(!is_null($thumbnailTempName)) {
            //   $this->ThumbnailStorage->acceptFile($thumbnailTempName, 'THUMB_'.$fileData['name'], $filesAdapter['destination']);
            // }
            $linkContent = file_get_contents($vimeo_thumb);
            $fileData['data'] = 'data:image/jpeg;base64,' . base64_encode($linkContent);
          } else {
            // Unable to get Vimeo details
            $fileData['title'] = __t('unable_to_retrieve_x_title', ['service' => 'Vimeo']);
            $fileData['height'] = 340;
            $fileData['width'] = 560;
          }
        } else {
          //Arnt youtube or voimeo so try to curl photo and use uploadfile
          // $content = file_get_contents($link);
          // $tmpFile = tempnam(sys_get_temp_dir(), 'DirectusFile');
          // file_put_contents($tmpFile, $content);
          // $stripped_url = preg_replace('/\\?.*/', '', $link);
          // $realfilename = basename($stripped_url);
          // return self::acceptFile($tmpFile, $realfilename);
          // return self::acceptFile();
          try {
            $fileData = $this->getLinkInfo($link);
          } catch (\Exception $e) {
            $fileData = false;
          }
        }

        return $fileData;
    }

    /**
     * Copy base64 data into Directus Media
     *
     * @param string $fileData - base64 data
     * @param string $fileName - name of the file
     *
     * @return bool
     */
    public function saveData($fileData, $fileName)
    {
        if (strpos($fileData, 'data:') === 0) {
            $fileData = base64_decode(explode(',', $fileData)[1]);
        }

        // @TODO: merge with upload()
        $fileName = $this->getFileName($fileName);
        $filePath = $this->getConfig('root') . '/' . $fileName;

        Hook::run('files.saving', ['name' => $fileName, 'size' => strlen($fileData)]);
        $this->filesystem->getAdapter()->write($fileName, $fileData);//, new FlysystemConfig());}
        Hook::run('files.saving:after', ['name' => $fileName, 'size' => strlen($fileData)]);

        $this->createThumbnails($fileName);

        $fileData = $this->getFileInfo($fileName);
        $fileData['title'] = Formatting::fileNameToFileTitle($fileName);
        $fileData['name'] = basename($filePath);
        $fileData['date_uploaded'] = gmdate('Y-m-d H:i:s');
        $fileData['storage_adapter'] = $this->config['adapter'];

        $fileData = array_merge($this->defaults, $fileData);

        return [
            'type' => $fileData['type'],
            'name' => $fileData['name'],
            'title' => $fileData['title'],
            'tags' => $fileData['tags'],
            'caption' => $fileData['caption'],
            'location' => $fileData['location'],
            'charset' => $fileData['charset'],
            'size' => $fileData['size'],
            'width' => $fileData['width'],
            'height' => $fileData['height'],
            //    @TODO: Returns date in ISO 8601 Ex: 2016-06-06T17:18:20Z
            //    see: https://en.wikipedia.org/wiki/ISO_8601
            'date_uploaded' => $fileData['date_uploaded'],// . ' UTC',
            'storage_adapter' => $fileData['storage_adapter']
        ];
    }

    /**
     * Save embed url into Directus Media
     *
     * @param string $fileData - File Data/Info
     * @param string $fileName - name of the file
     *
     * @return Array - file info
     */
    public function saveEmbedData($fileData)
    {
        if (!array_key_exists('type', $fileData) || strpos($fileData['type'], 'embed/') !== 0) {
            return false;
        }

        $fileName = isset($fileData['name']) ? $fileData['name'] : md5(time());
        $imageData = $this->saveData($fileData['data'], $fileName);

        $keys = ['date_uploaded', 'storage_adapter'];
        foreach($keys as $key) {
            if (array_key_exists($key, $imageData)) {
                $fileData[$key] = $imageData[$key];
            }
        }

        return $fileData;
    }

    /**
     * Get file info
     *
     * @param string $path
     * @param bool if the $path is outside of the adapter root path.
     *
     * @return Array file info
     */
    public function getFileInfo($filePath, $outside = false)
    {
        $finfo = new \finfo(FILEINFO_MIME);
        // $type = explode('; charset=', $finfo->file($filePath));
        if ($outside === true) {
            $buffer = file_get_contents($filePath);
        } else {
            $buffer = $this->filesystem->getAdapter()->read($filePath);
        }
        $type = explode('; charset=', $finfo->buffer($buffer));
        $info = array('type' => $type[0], 'charset' => $type[1]);
        $typeTokens = explode('/', $info['type']);
        $info['format'] = $typeTokens[1]; // was: $this->format
        $info['size'] = strlen($buffer);//filesize($filePath);
        $info['width'] = null;
        $info['height'] = null;

        if($typeTokens[0] == 'image') {
            $meta = array();
            //$size = getimagesize($filePath, $meta);
            $size = [];
            $image = imagecreatefromstring($buffer);
            $size[] = imagesx($image);
            $size[] = imagesy($image);

            $info['width'] = $size[0];
            $info['height'] = $size[1];
            if (isset($meta["APP13"])) {
                $iptc = iptcparse($meta["APP13"]);

                if (isset($iptc['2#120'])) {
                    $info['caption'] = $iptc['2#120'][0];
                }
                if (isset($iptc['2#005']) && $iptc['2#005'][0] != '') {
                    $info['title'] = $iptc['2#005'][0];
                }
                if (isset($iptc['2#025'])) {
                    $info['tags'] = implode(',', $iptc['2#025']);
                }
                $location = array();
                if(isset($iptc['2#090']) && $iptc['2#090'][0] != '') {
                  $location[] = $iptc['2#090'][0];
                }
                if(isset($iptc["2#095"][0]) && $iptc['2#095'][0] != '') {
                  $location[] = $iptc['2#095'][0];
                }
                if(isset($iptc["2#101"]) && $iptc['2#101'][0] != '') {
                  $location[] = $iptc['2#101'][0];
                }
                $info['location'] = implode(', ', $location);
            }
        }
        return $info;
    }

    /**
     * Get file settings
     *
     * @param string $key - Optional setting key name
     *
     * @return mixed
     */
    public function getSettings($key = '')
    {
        if (!$key) {
            return $this->filesSettings;
        } else if (array_key_exists($key, $this->filesSettings)) {
            return $this->filesSettings[$key];
        }

        return false;
    }

    /**
     * Get filesystem config
     *
     * @param string $key - Optional config key name
     *
     * @return mixed
     */
    public function getConfig($key = '')
    {
        if (!$key) {
            return $this->config;
        } else if (array_key_exists($key, $this->config)) {
            return $this->config[$key];
        }

        return false;
    }

    /**
     * Create a thumbnail
     *
     * @param string $imageName - the name of the image. it must exists on files.
     *
     * @return void
     */
     // @TODO: it should return thumbnail info.
    private function createThumbnails($imageName)
    {
        $targetFileName = $this->getConfig('root') . '/' . $imageName;
        $info = pathinfo($targetFileName);

        if (in_array($info['extension'], array('jpg','jpeg','png','gif','tif', 'tiff', 'psd', 'pdf'))) {
            $targetContent = $this->filesystem->getAdapter()->read($imageName);
            $img = Thumbnail::generateThumbnail($targetContent, $info['extension'], $this->getSettings('thumbnail_size'), $this->getSettings('thumbnail_crop_enabled'));
            if($img) {
                //   $thumbnailTempName = $this->getConfig('root') . '/thumbs/THUMB_' . $imageName;
                $thumbnailTempName = 'thumbs/THUMB_' . $imageName;
                $thumbImg = Thumbnail::writeImage($info['extension'], $thumbnailTempName, $img, $this->getSettings('thumbnail_quality'));
                Hook::run('files.thumbnail.saving', array('name' => $imageName, 'size' => strlen($thumbImg)));
                $this->filesystem->getAdapter()->write($thumbnailTempName, $thumbImg);//, new FlysystemConfig());
                Hook::run('files.thumbnail.saving:after', array('name' => $imageName, 'size' => strlen($thumbImg)));
            }
        }
    }

    /**
     * Creates a new file for Directus Media
     *
     * @param string $filePath
     * @param string $targetName
     *
     * @return Array file info
     */
    private function processUpload($filePath, $targetName)
    {
        // set true as $filePath it's outside adapter path
        // $filePath is on a temporary php directory
        $fileData = $this->getFileInfo($filePath, true);
        $mediaPath = $this->filesystem->getPath();

        $fileData['title'] = Formatting::fileNameToFileTitle($targetName);

        $targetName = $this->getFileName($targetName);
        $finalPath = rtrim($mediaPath, '/').'/'.$targetName;
        $data = file_get_contents($filePath);

        Hook::run('files.saving', array('name' => $targetName, 'size' => strlen($data)));
        $this->filesystem->getAdapter()->write($targetName, $data);
        Hook::run('files.saving:after', array('name' => $targetName, 'size' => strlen($data)));

        $fileData['name'] = basename($finalPath);
        $fileData['date_uploaded'] = gmdate('Y-m-d H:i:s');
        $fileData['storage_adapter'] = $this->config['adapter'];

        return $fileData;
    }

    /**
     * Sanitize title name from file name
     *
     * @param string $fileName
     *
     * @return string
     */
    private function sanitizeName($fileName)
    {
        // do not start with dot
        $fileName = preg_replace('/^\./', 'dot-', $fileName);
        $fileName = str_replace(' ', '_', $fileName);

        return $fileName;
    }

    /**
     * Add suffix number to file name if already exists.
     *
     * @param string $fileName
     * @param string $targetPath
     * @param int    $attempt - Optional
     *
     * @return bool
     */
    private function uniqueName($fileName, $targetPath, $attempt = 0)
    {
        $info = pathinfo($fileName);
        $ext = $info['extension'];
        $name = basename($fileName, ".$ext");

        $name = $this->sanitizeName($name);

        $fileName = "$name.$ext";
        if($this->filesystem->exists($fileName)) {
            $matches = array();
            $trailingDigit = '/\-(\d)\.('.$ext.')$/';
            if(preg_match($trailingDigit, $fileName, $matches)) {
                // Convert "fname-1.jpg" to "fname-2.jpg"
                $attempt = 1 + (int) $matches[1];
                $newName = preg_replace($trailingDigit, "-{$attempt}.$ext", $fileName);
                $fileName = basename($newName);
            } else {
                if ($attempt) {
                    $name = rtrim($name, $attempt);
                    $name = rtrim($name, '-');
                }
                $attempt++;
                $fileName = $name . '-' . $attempt . '.' . $ext;
            }
            return $this->uniqueName($fileName, $targetPath, $attempt);
        }

        return $fileName;
    }

    /**
     * Get file name based on file naming setting
     *
     * @param string $fileName
     *
     * @return string
     */
    private function getFileName($fileName)
    {
        switch($this->getSettings('file_naming')) {
            case 'file_hash':
                $fileName = $this->hashFileName($fileName);
                break;
        }

        return $this->uniqueName($fileName, $this->filesystem->getPath());
    }

    /**
     * Hash file name
     *
     * @param string $fileName
     *
     * @return string
     */
    private function hashFileName($fileName)
    {
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $fileHashName = md5(microtime() . $fileName);
        return $fileHashName.'.'.$ext;
    }

    /**
     * Get string between two string
     *
     * @param string $string
     * @param string $start
     * @param string $end
     *
     * @return string
     */
    private function get_string_between($string, $start, $end)
    {
      $string = " ".$string;
      $ini = strpos($string,$start);
      if ($ini == 0) return "";
      $ini += strlen($start);
      $len = strpos($string,$end,$ini) - $ini;
      return substr($string,$ini,$len);
    }

    /**
     * Get URL info
     *
     * @param string $link
     *
     * @return Array
     */
    public function getLinkInfo($link)
    {
        $fileData = array();

        $urlHeaders = get_headers($link, 1);
        $urlInfo = pathinfo($link);

        // if(in_array($urlInfo['extension'], array('jpg','jpeg','png','gif','tif','tiff'))) {
        if (strpos($urlHeaders['Content-Type'], 'image/') === 0) {
            list($width, $height) = getimagesize($link);
        }

        $linkContent = file_get_contents($link);
        $url = 'data:' . $urlHeaders['Content-Type'] . ';base64,' . base64_encode($linkContent);

        $fileData = array_merge($fileData, array(
            'type' => $urlHeaders['Content-Type'],
            'name' => $urlInfo['basename'],
            'title' => $urlInfo['filename'],
            'charset' => 'binary',
            'size' => isset($urlHeaders['Content-Length']) ? $urlHeaders['Content-Length'] : 0,
            'width' => $width,
            'height' => $height,
            'data' => $url,
            'url' => ($width) ? $url : ''
        ));

        return $fileData;
    }
}
