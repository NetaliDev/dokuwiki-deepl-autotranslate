<?php

use dokuwiki\Extension\RemotePlugin;
use dokuwiki\Remote\AccessDeniedException;

/**
 * DokuWiki Plugin deeplautotranslate (Remote Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author Andreas Gohr <gohr@cosmocode.de>
 */
class remote_plugin_deeplautotranslate extends RemotePlugin
{
    /**
     * Create a new translation of the given page in the given language
     *
     * @param string $id The page ID
     * @param string $lang The target language
     * @return string The page ID of the newly translated page
     */
    public function pushTranslation($id, $lang)
    {
        global $ID;
        global $INFO;
        $ID = $id;
        $INFO['exists'] = page_exists($id);


        /** @var action_plugin_deeplautotranslate $action */
        $action = plugin_load('action', 'deeplautotranslate');

        $text = rawWiki($id);

        return $action->push_translate($id, $text, $lang);
    }
}
