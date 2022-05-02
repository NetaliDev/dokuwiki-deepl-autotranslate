<?php

namespace dokuwiki\plugin\deeplautotranslate;

use dokuwiki\Menu\Item\AbstractItem;

/**
 * Class MenuItem
 *
 * Implements the translate button for DokuWiki's menu system
 *
 * @package dokuwiki\plugin\deeplautotranslate
 */
class MenuItem extends AbstractItem {
    /** @var string do action for this plugin */
    protected $type = 'translate';

    /** @var string icon file */
    protected $svg = __DIR__ . '/img/translate.svg';

    /**
     * Get label from plugin language file
     *
     * @return string
     */
    public function getLabel() {
        $hlp = plugin_load('action', 'deeplautotranslate');
        return $hlp->getLang('btn_translate');
    }
}