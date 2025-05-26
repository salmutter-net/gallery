<?php
/**
 *  @Copyright
 *  @package     GALLERY - Content Image Gallery
 *  @author      Philipp Salmutter
 *  @version     0-1 - 2014-04-03
 *
 *  @license Commercial
 */

use Joomla\CMS\Version;

defined('_JEXEC') or die('Restricted access');

class plgContentGallery extends JPlugin {
    protected $_absolute_path;
    protected $_live_site;
    protected $_rootfolder;
    protected $_images_dir;

    public function __construct(&$subject, $config) {
        $app = JFactory::getApplication();

        if ($app->isClient('admin')) {
            return;
        }

        $version = new Version();

        $joomla_main_version = substr($version->getShortVersion(), 0, strpos($version->getShortVersion(), '.'));

        if ($joomla_main_version < '3') {
            JError::raiseWarning(100, JText::_('Dieses Plugin funktioniert nur mit Joomla 3 und größer!'));
            return;
        }

        if (isset($_SESSION['gallerycount'])) {
            unset($_SESSION['gallerycount']);
        }

        if (isset($_SESSION['gallerycountarticles'])) {
            unset($_SESSION['gallerycountarticles']);
        }

        parent::__construct($subject, $config);

        $this->_absolute_path = JPATH_SITE;
        $this->_live_site = JURI::base();

        if (substr($this->_live_site, -1) == '/') {
            $this->_live_site = substr($this->_live_site, 0, -1);
        }

        $this->_params = array();
    }

    function onContentPrepare($context, &$object) {
        $textToParse = $object->text;
        $type = 'text';
        if (empty($textToParse) && !empty($object->introtext)) {
            $textToParse = $object->introtext;
            $type = 'introtext';
        } elseif (empty($textToParse) && !empty($object->fulltext)) {
            $textToParse = $object->fulltext;
            $type = 'fulltext';
        }
        if (!preg_match('@{gallery}(.*){/gallery}@Us', $textToParse)) {
            return;
        }

        $this->_rootfolder = '/images/';

        if (!isset($_SESSION['gallerycountarticles'])) {
            $_SESSION['gallerycountarticles'] = -1;
        }

        if (preg_match_all('@{gallery}(.*){/gallery}@Us', $textToParse, $matches, PREG_PATTERN_ORDER) > 0) {
            $_SESSION['gallerycountarticles']++;

            if (!isset($_SESSION['gallerycount'])) {
                $_SESSION['gallerycount'] = -1;
            }

            $this->_params['lang'] = JFactory::getLanguage()->getTag();

            foreach($matches[0] as $match) {
                $_SESSION['gallerycount']++;
                $galleryPath = preg_replace('@{.+?}@', '', $match);

                unset($images);
                $imageCount = 0;
                if ($dh = @opendir($this->_absolute_path . $this->_rootfolder . $galleryPath)) {
                    while(($file = readdir($dh)) !== false) {
                        if ( substr(strtolower($file), -3) == 'jpg'
                            OR substr(strtolower($file), -3) == 'JPG'
                            OR substr(strtolower($file), -4) == 'jpeg'
                            OR substr(strtolower($file), -4) == 'JPEG'
                            OR substr(strtolower($file), -3) == 'gif'
                            OR substr(strtolower($file), -3) == 'GIF'
                            OR substr(strtolower($file), -3) == 'png'
                            OR substr(strtolower($file), -3) == 'PNG'
                        ) {
                            $images[$file] = 'images/' . $galleryPath . '/' . $file;
                            $imageCount++;
                        }
                    }
                    if (!empty($images)) {
                        ksort($images);
                    }
                    closedir($dh);
                }

                if ($imageCount) {
                    $this->renderGallery($type, $object, $images, $galleryPath);
                } else {
                    $html = '<strong>'.JText::_('Im angegebenen Ordner befinden sich keine Bilder bzw. können sie nicht ausgelesen werden.').'</strong><br />'.JText::_('Pfad: ').' '.$this->_live_site.$this->_rootfolder.$this->_images_dir;
                }
            }
        }
        unset($textToParse);
    }

    function renderGallery($type, &$object, $images, $galleryPath) {
        $imageTransformer = false;
        if (class_exists('Imagetransformer')) {
            $imageTransformer = 'Imagetransformer';
        }
        if (class_exists('ImgResizeCache')) {
            $imageTransformer = 'ImgResizeCache';
            $resizer = new ImgResizeCache();
        }
        if (empty($type) || empty($object) || empty($images) || empty($galleryPath)) {
            echo '<strong>'.JText::_('Essentielle Variable nicht übergeben!').'</strong>';
            return NULL;
        }
        $html = '';
        if ($_SESSION['gallerycountarticles'] === 0) {
            // Include once the DOM nodes needed for gallery from external file and add the to the $html-array
            ob_start();
            include __DIR__ . '/plugin_gallery/photoSwipe-Dom.php';
            $galleryDomTree = ob_get_clean();

            // Include hte gallery's CSS and JS files once in the head of the page
            $head = array();
            $document = JFactory::getDocument();
            $head[] = '<link rel="stylesheet" href="'.$this->_live_site.'/plugins/content/gallery/plugin_gallery/photoswipe.css" type="text/css" media="screen" />';
            $head[] = '<link rel="stylesheet" href="'.$this->_live_site.'/plugins/content/gallery/plugin_gallery/default-skin/default-skin.css" type="text/css" media="screen" />';
            $head[] = '<script type="text/javascript" src="'.$this->_live_site.'/plugins/content/gallery/plugin_gallery/photoswipe.min.js"></script>';
            $head[] = '<script type="text/javascript" src="'.$this->_live_site.'/plugins/content/gallery/plugin_gallery/photoswipe-ui-default.min.js"></script>';
            $head[] = '<script type="text/javascript" src="'.$this->_live_site.'/plugins/content/gallery/plugin_gallery/gallery.js"></script>';
            $head[] = '
                        <script type="text/javascript">
                            function appendHtml(el, str) {
                              var div = document.createElement(\'div\');
                              div.innerHTML = str;
                              while (div.children.length > 0) {
                                el.appendChild(div.children[0]);
                              }
                            }
                            var html = \'' . preg_replace('/^\s+|\n|\r|\s+$/m', '', $galleryDomTree) . '\';
                            document.addEventListener(\'DOMContentLoaded\', function() {
                                appendHtml(document.body, html);
                            });
                        </script>
            ';
            $head = "\n".implode("\n", $head)."\n";
            $document->addCustomTag($head);
        }

        // Generate the HTML-script-tag with dynamic images
        $html .= ' <script>
                        window.addEventListener("DOMContentLoaded", function() {
                           var pswpElement = document.querySelectorAll(".pswp")[0];
                           var items = [';
            foreach ($images as $key => $image) {
                if ($imageTransformer === 'Imagetransformer') {
                    $largeImgSrc = Imagetransformer::generateUrl($image, [
                        'w' => 1280,
                        'h' => 853,
                        'fit' => 'contain',
                        'q' => 70,
                        'bg' => '#000',
                        'fm' => 'webp'
                    ]);
                } else if ($imageTransformer === 'ImgResizeCache') {
                    $largeImgSrc = htmlspecialchars( $resizer->resize( $image, array( 'w' => 1280, 'h' => 853, 'crop' => false, 'canvas-color' => '#000' ) ) );
                } else {
                    $largeImgSrc = $image;
                }
                $imageSizeArray = getimagesize($largeImgSrc);
                $html .= '
                           {
                               src: "' . $largeImgSrc .'",
                               w: "' . $imageSizeArray[0] . '",
                               h: "' . $imageSizeArray[1] . '"
                           }' . $key === count($images)-1 ? ',':'';
            }
            unset($image, $largeImgSrc);
            $html .= '];
                           var options = {
                               index: 0
                           };

                          var gallery = new PhotoSwipe( pswpElement, PhotoSwipeUI_Default, items, options);
                          //gallery.init();

                          initPhotoSwipeFromDOM(".gallery-wrapper");

                        } );
                  </script>
        ';

        // Generate the image thumbnail-list
        $html .= '<div class="gallery-wrapper" itemscope itemtype="http://schema.org/ImageGallery">';
        foreach ($images as $key => $image) {
            if ($imageTransformer === 'Imagetransformer') {
                $largeImgSrc = Imagetransformer::generateUrl($image, [
                'w' => 1280,
                'h' => 853,
                'fit' => 'contain',
                'q' => 70,
                'bg' => '#000',
                'fm' => 'webp'
                ]);
            } else if ($imageTransformer === 'ImgResizeCache') {
                $largeImgSrc = htmlspecialchars( $resizer->resize( $image, array( 'w' => 1280, 'h' => 853, 'crop' => false, 'canvas-color' => '#000' ) ) );
            } else {
                $largeImgSrc = $image;
            }
            $imageSizeArray = getimagesize($largeImgSrc);
            if ($imageTransformer === 'Imagetransformer') {
                $smallImgSrc = Imagetransformer::generateUrl($image, [
                'w' => 197,
                'h' => 132,
                'fit' => 'contain',
                'q' => 70,
                'bg' => '#fff',
                'fm' => 'webp'
                ]);
            } else if ($imageTransformer === 'ImgResizeCache') {
                $smallImgSrc = htmlspecialchars($resizer->resize($image, array('w' => 197, 'h' => 132, 'crop' => false, 'canvas-color' => '#fff')));
            } else {
                $smallImgSrc = $image;
            }
            $html .= ' <figure class="gallery-thumbnail">';
            $html .= '     <a href="' . $largeImgSrc . '" itemprop="contentUrl" data-size="' . $imageSizeArray[0] . 'x' . $imageSizeArray[1] . '">';
            $html .= '         <img class="gallery-thumbnail-image" src="' . $smallImgSrc .'" />';
            $html .= '     </a>';
            $html .= ' </figure>';
        }
        $html .= '</div>';
        unset($images, $image, $imgSrc);

        if ($type === 'introtext') {
            $object->introtext = preg_replace('@(<p>)?{gallery}'.$galleryPath.'{/gallery}(</p>)?@s', $html, $object->introtext);
        } elseif ($type === 'fulltext') {
            $object->fulltext = preg_replace('@(<p>)?{gallery}'.$galleryPath.'{/gallery}(</p>)?@s', $html, $object->fulltext);
        } else {
            $object->text = preg_replace('@(<p>)?{gallery}'.$galleryPath.'{/gallery}(</p>)?@s', $html, $object->text);
        }
        unset($html, $galleryPath);
    }
}
