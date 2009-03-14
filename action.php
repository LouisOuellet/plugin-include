<?php
/**
 * Include Plugin:  Display a wiki page within another wiki page
 *
 * Action plugin component, for cache validity determination
 * 
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Christopher Smith <chris@jalakai.co.uk>  
 * @author     Michael Klier <chi@chimeric.de>
 */
if(!defined('DOKU_INC')) die();  // no Dokuwiki, no go
 
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'action.php');
 
/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class action_plugin_include extends DokuWiki_Action_Plugin {
 
    var $supportedModes = array('xhtml');
    var $helper = null;

    function action_plugin_include() {
        $this->helper = plugin_load('helper', 'include');
    }
 
    /**
     * return some info
     */
    function getInfo() {
      return array(
        'author' => 'Gina Häußge, Michael Klier, Christopher Smith',
        'email'  => 'dokuwiki@chimeric.de',
        'date'   => @file_get_contents(DOKU_PLUGIN . 'blog/VERSION'),
        'name'   => 'Include Plugin',
        'desc'   => 'Improved cache handling for included pages and redirect-handling',
        'url'    => 'http://wiki.splitbrain.org/plugin:include',
      );
    }
    
    /**
     * plugin should use this method to register its handlers with the dokuwiki's event controller
     */
    function register(&$controller) {
      $controller->register_hook('PARSER_CACHE_USE','BEFORE', $this, '_cache_prepare');
//      $controller->register_hook('PARSER_CACHE_USE','AFTER', $this, '_cache_result');    // debugging only
      $controller->register_hook('HTML_EDITFORM_OUTPUT', 'BEFORE', $this, 'handle_form');
      $controller->register_hook('HTML_CONFLICTFORM_OUTPUT', 'BEFORE', $this, 'handle_form');
      $controller->register_hook('HTML_DRAFTFORM_OUTPUT', 'BEFORE', $this, 'handle_form');
      $controller->register_hook('ACTION_SHOW_REDIRECT', 'BEFORE', $this, 'handle_redirect');
      $controller->register_hook('PARSER_HANDLER_DONE', 'AFTER', $this, 'handle_parser');
      $controller->register_hook('TPL_TOC_RENDER', 'BEFORE', $this, 'handle_toc');
    }

    /**
     * Handles toc generation
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function handle_toc(&$event, $param) {
        $event->data = $this->helper->toc;
    }

    /**
     * Supplies the current section level to the include syntax plugin
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function handle_parser(&$event, $param) {
        global $ID;

        if(!isset($this->helper->toplevel_id)) $this->helper->toplevel_id = $ID;

        $ins =& $event->data->calls;
        $num = count($ins);

        $toc = array();
        $lvl = 1;
        for($i=0; $i<$num; $i++) {
            if($ins[$i][0] == 'header' && ($ID == $this->helper->toplevel_id)) {
                array_push($toc, array($ins[$i][1][0], $ins[$i][1][1]));
            }
            if($ins[$i][0] == 'section_open') {
                $lvl = $ins[$i][1][0];
            }
            if($ins[$i][0] == 'plugin' && $ins[$i][1][0] == 'include_include' ) {
                $ins[$i][1][1][4] = $lvl;
                $ins[$i][1][1][5] = $toc;
                $toc = array();
            }
        }
    }

    /**
     * add a hidden input to the form to preserve the redirect_id
     */
    function handle_form(&$event, $param) {
      if (array_key_exists('redirect_id', $_REQUEST)) {
        $event->data->addHidden('redirect_id', cleanID($_REQUEST['redirect_id']));
      }
    }

    /**
     * modify the data for the redirect when there is a redirect_id set
     */
    function handle_redirect(&$event, $param) {
      if (array_key_exists('redirect_id', $_REQUEST)) {
        $event->data['id'] = cleanID($_REQUEST['redirect_id']);
        $event->data['title'] = '';
      }
    }

    /**
     * prepare the cache object for default _useCache action
     */
    function _cache_prepare(&$event, $param) {
      $cache =& $event->data;
 
      // we're only interested in wiki pages and supported render modes
      if (!isset($cache->page)) return;
      if (!isset($cache->mode) || !in_array($cache->mode, $this->supportedModes)) return;
 
      $key = '';
      $depends = array();    
      $expire = $this->_inclusion_check($cache->page, $key, $depends);
 
//      global $debug;
//      $debug[] = compact('key','expire','depends','cache');
 
      // empty $key implies no includes, so nothing to do
      if (empty($key)) return;
 
      // mark the cache as being modified by the include plugin
      $cache->include = true;
 
      // set new cache key & cache name - now also dependent on included page ids and their ACL_READ status
      $cache->key .= $key;
      $cache->cache = getCacheName($cache->key, $cache->ext);
 
      // inclusion check was able to determine the cache must be invalid
      if ($expire) {
        $event->preventDefault();
        $event->stopPropagation();
        $event->result = false;
        return;
      }
 
      // update depends['files'] array to include all included files
      $cache->depends['files'] = !empty($cache->depends['files']) ? array_merge($cache->depends['files'], $depends) : $depends;
    }
 
    /**
     * carry out included page checks:
     * - to establish proper cache name, its dependent on the read status of included pages
     * - to establish file dependencies, the included raw wiki pages
     *
     * @param   string    $id         wiki page name
     * @param   string    $key        (reference) cache key
     * @param   array     $depends    array of include file dependencies
     *
     * @return  bool                  expire the cache
     */
    function _inclusion_check($id, &$key, &$depends) {
      $hasPart = p_get_metadata($id, 'relation haspart');
      if (empty($hasPart)) return false;
 
      $expire = false;
      foreach ($hasPart as $page => $exists) {
        // ensure its a wiki page
        if (strpos($page,'/') ||  cleanID($page) != $page) continue;
 
        // recursive includes aren't allowed and there is no need to do the same page twice
        $file = wikiFN($page);
        if (in_array($file, $depends)) continue;
 
        // file existence state is different from state recorded in metadata
        if (@file_exists($file) != $exists) {
 
          if (($acl = $this->_acl_read_check($page)) != 'NONE') { $expire = true;  }
 
        } else if ($exists) {
 
          // carry out an inclusion check on the included page, that will update $key & $depends
          if ($this->_inclusion_check($page, $key, $depends)) { $expire = true; }
          if (($acl = $this->_acl_read_check($page)) != 'NONE') { $depends[] = $file;  }          
 
        } else {
          $acl = 'NONE';
        }
        
        // add this page and acl status to the key
        $key .= '#'.$page.'|'.$acl;
      }
      
      return $expire;
    }
 
    function _acl_read_check($id) {
      return (AUTH_READ <= auth_quickaclcheck($id)) ? 'READ' : 'NONE';
    }
 
    function _cache_result(&$event, $param) {
      $cache =& $event->data;
      if (empty($cache->include)) return;
 
//      global $debug;
//      $debug['cache_result'][] = $event->result ? 'true' : 'false';
    }
 
}
//vim:ts=4:sw=4:et:enc=utf-8: 
