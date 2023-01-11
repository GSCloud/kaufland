<?php
/**
 * GSC Tesseract
 * php version 8.2
 *
 * @category Framework
 * @package  Tesseract
 * @author   Fred Brooker <git@gscloud.cz>
 * @license  MIT https://gscloud.cz/LICENSE
 * @link     https://1950.mxd.cz
 */

namespace GSC;

use Cake\Cache\Cache;
use Michelf\MarkdownExtra;

/**
 * Mini Presenter
 * 
 * @category Framework
 * @package  Tesseract
 * @author   Fred Brooker <git@gscloud.cz>
 * @license  MIT https://gscloud.cz/LICENSE
 * @link     https://1950.mxd.cz
 */
class LahvePresenter extends APresenter
{
    /**
     * Main controller
     * 
     * @param mixed $param optional parameter
     *
     * @return self
     */
    public function process($param = null)
    {
        // basic setup
        $data = $this->getData();
        $presenter = $this->getPresenter();
        $view = $this->getView();
        $this->checkRateLimit()->setHeaderHtml()->dataExpander($data);

        // process output
        $output = $this->setData($data)->renderHTML($presenter[$view]["template"]);
        StringFilters::trim_html_comment($output);
        header("X-Cached: false");
        return $this->setData("output", $output);
    }
}
