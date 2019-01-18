<?php
/**
 * Plugin Bookmarkfile: Displays a bookmark file as linklist
 * Syntax: <BOOKMARKFILE file="..." [separators="hide"] [folder="..."]>
 * e.g. <BOOKMARKFILE file="bookmarks.json">
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 *
 * @author Ekkart Kleinod
 * @author LarsDW223
 */

/**
 * Plugin-Class for Bookmarkfile-Plugin.
 *
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from DokuWiki_Syntax_Plugin
 */
class syntax_plugin_bookmarkfile extends DokuWiki_Syntax_Plugin
{
    /** First line of opera/firefox bookmark files in HTML format. */
    private $netscape_bmf = '<!DOCTYPE NETSCAPE-Bookmark-file-1>';

    /**
     * What kind of syntax are we?
     */
    public function getType()
    {
        return 'substition';
    }

    /**
     * How to handle paragraphs?
     */
    public function getPType()
    {
        return 'block';
    }

    /**
     * Where to sort in?
     */
    public function getSort()
    {
        return 100;
    }

    /**
     * Connect pattern to lexer.
     */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('\<BOOKMARKFILE .*?\>',$mode,'plugin_bookmarkfile');
    }

    /**
     * Handle the match.
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        global $conf;

        preg_match('/ file="(.*?)"/', $match, $matches);
        $filename = $conf['mediadir'].'/'.str_replace(':', '/', $matches[1]);

        preg_match('/ separators="(.*?)"/', $match, $matches);
        $separators = $matches[1];

        preg_match('/ folder="(.*?)"/', $match, $matches);
        $folder = $matches[1];

        // Tries to open the file
        $result = array();
        $result['separators'] = $separators;
        $bookmarkfile = fopen($filename, "r-");
        if ($bookmarkfile) {
            // Detect bookmark browser
            $first_line = trim(fgets($bookmarkfile));

            if (strcasecmp($first_line, $this->netscape_bmf) == 0) {
                $bookmarks = $this->parseNetscapeFile($bookmarkfile);
                fclose($bookmarkfile);
                $result['bookmarks'] = $bookmarks;
            } else {
                if (strpos($first_line, 'x-moz') !== false) {
                    // Close the file
                    fclose($bookmarkfile);

                    $bookmarks = $this->parseFirefoxFile($filename);
                    if ($bookmarks !== null) {
                        $result['bookmarks'] = $bookmarks;
                    } else {
                        $result['message'] = $this->getLang('json_failed');
                    }
                } else {
                    $result['message'] = $this->getLang('err_format');
                }
            }
        } else {
            $result['message'] = $this->getLang('err_nofile');
        }

        if (!empty($folder) && !empty($result['bookmarks'])) {
            $result['bookmarks'] = $this->findBookmarksFolder($result['bookmarks'], $folder);
        }

        return $result;
    }

    /**
     * Create output.
     */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        // At the moment, only xhtml is supported
        if ($mode == 'xhtml') {
            if (is_array($data['bookmarks'])) {
                $this->renderBookmarks($renderer, $data['bookmarks'], $data['separators']);
            } else {
                $renderer->cdata($data['message']);
            }

            return true;
        }
        
        return false;
    }

    /**
     * Process one level of a Firefox bookmark array
     * (decoded from JSON encoded bookmark file).
     */
    private function parseFirefoxJSON(array $json)
    {
        // Skip root node
        if ($json['root'] == 'placesRoot') {
            $pos = $json['children'];
        } else {
            $pos = $json;
        }

        $bookmarks = array();
        foreach ($pos as $json_item) {
            if ($json_item['typeCode'] == 2) {
                // A folder
                switch ($json_item['guid']) {
                    case 'toolbar_____':
                        $title = $this->getLang('firefox_toolbar_folder');
                    break;
                    case 'menu________':
                        $title = $this->getLang('firefox_menu_folder');
                    break;
                    case 'mobile______':
                        $title = $this->getLang('firefox_mobile_folder');
                    break;
                    case 'unfiled_____':
                        $title = $this->getLang('firefox_other_folder');
                    break;
                    default:
                        $title = $json_item['title'];
                    break;
                }
                $item = array();
                $item ['type']  = 'folder';
                $item ['title'] = $title;
                if (is_array($json_item['children'])) {
                    $item ['children'] = $this->parseFirefoxJSON($json_item['children']);
                } else {
                    $item ['children'] = array();
                }
            } else if ($json_item['typeCode'] == 1) {
                // An entry/link
                $item = array();
                $item ['type']  = 'link';
                $item ['title'] = $json_item['title'];
                $item ['uri'] = $json_item['uri'];
            } else if ($json_item['typeCode'] == 3) {
                // A separator
                $item = array();
                $item ['type']  = 'separator';
            }
            $bookmarks [] = $item;
        }

        return $bookmarks;
    }

    /**
     * Parse a Firefox bookmark file (JSON encoded).
     */
    private function parseFirefoxFile($filename)
    {
        $json = file_get_contents($filename);
        
        $json_bookmarks = json_decode($json, true);
        if ($json_bookmarks === null) {
            return null;
        }
        $bookmarks = $this->parseFirefoxJSON($json_bookmarks);
        return $bookmarks;
    }

    /**
     * Process an HTML (Netscape) bookmark file.
     */
    private function parseNetscapeFile($bookmarkfile)
    {
        // read file line by line
        $bookmarks = array();
        while (!feof($bookmarkfile)) {
            $sLine = trim(fgets($bookmarkfile));

            // Ordner
            if (preg_match('/\<H1\>(.*?)\<\/H1\>/', $sLine, $matches) == 1) {
                // Root folder
                $item = array();
                $item ['type']  = 'folder';
                $item ['title'] = $matches[1];
            } else if (preg_match('/\<DT\>\<H3.*?\>(.*?)\<\/H3\>/', $sLine, $matches) == 1) {
                $item = array();
                $item ['type']  = 'folder';
                $item ['title'] = $matches[1];
            } else if (preg_match('/\<DT\>\<A.*?HREF="(.*?)".*?\>(.*?)\<\/A\>/', $sLine, $matches) == 1) {
                $item = array();
                $item ['type']  = 'link';
                $item ['title'] = $matches[2];
                $item ['uri'] = $matches[1];
                $bookmarks [] = $item;
            } else if (preg_match('/\<DL>/', $sLine, $matches) == 1) {
                $item['children'] = $this->parseNetscapeFile($bookmarkfile);
                $bookmarks [] = $item;
            } else if (preg_match('/\<\/DL>/', $sLine, $matches) == 1) {
                return $bookmarks;
            }
        }

        return $bookmarks;
    }

    /**
     * Find a folder in the bookmarks array and return it's children.
     * (or null if the folder doesn't exist).
     */
    private function findBookmarksFolder($bookmarks, $folder)
    {
        foreach ($bookmarks as $item) {
            if ($item['type'] == 'folder') {
                if ($item['title'] == $folder) {
                    return $item['children'];
                }
                $found = $this->findBookmarksFolder($item['children'], $folder);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * Render the bookmarks as a list.
     */
    private function renderBookmarks(Doku_Renderer $renderer, array $bookmarks, $separators, $level=1)
    {
        $renderer->listu_open('bookmarkfile');
        foreach ($bookmarks as $item) {
            switch ($item['type']) {
                case 'link':
                    // An entry/link
                    $renderer->listitem_open($level);
                    $renderer->listcontent_open();
                    $renderer->externallink($item['uri'], $item['title']);
                    $renderer->listcontent_close();
                    $renderer->listitem_close();
                break;
                case 'folder':
                    // A folder
                    $renderer->listitem_open($level);
                    $renderer->listcontent_open();
                    $renderer->cdata($item['title']);
                    $renderer->listcontent_close();
                    $this->renderBookmarks($renderer, $item['children'], $separators, $level+1);
                    $renderer->listitem_close();
                break;
                case 'separator':
                    // A separator
                    if ($separators !== 'hide') {
                        $renderer->doc .= '<li class="level'.$level.' separator">';
                        $renderer->listcontent_open();
                        $renderer->hr();
                        $renderer->listcontent_close();
                        $renderer->doc .= '</li>'.DOKU_LF;
                    }
                break;
            }
        }
        $renderer->listu_close();
    }
}
