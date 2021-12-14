<?php
/**
 * Deepl Autotranslate Plugin
 *
 * @author     Jennifer Graul <me@netali.de>
 */

if(!defined('DOKU_INC')) die();

use \dokuwiki\HTTP\DokuHTTPClient;

class action_plugin_deeplautotranslate extends DokuWiki_Action_Plugin {

    // manual mapping of ISO-languages to DeepL-languages to fix inconsistent naming
    private $langs = [
        'bg' => 'BG',
        'cs' => 'CS',
        'da' => 'DA',
        'de' => 'DE',
        'de-informal' => 'DE',
        'el' => 'EL',
        'en' => 'EN-GB',
        'es' => 'ES',
        'et' => 'ET',
        'fi' => 'FI',
        'fr' => 'FR',
        'hu' => 'HU',
        'hu-formal' => 'HU',
        'it' => 'IT',
        'ja' => 'JA',
        'lt' => 'LT',
        'lv' => 'LV',
        'nl' => 'NL',
        'pl' => 'PL',
        'pt' => 'PT-PT',
        'ro' => 'RO',
        'ru' => 'RU',
        'sk' => 'SK',
        'sl' => 'SL',
        'sv' => 'SV',
        'zh' => 'ZH'
    ];

    /**
     * Register its handlers with the DokuWiki's event controller
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('ACTION_ACT_PREPROCESS','BEFORE', $this, 'autotrans_direct');
        $controller->register_hook('COMMON_PAGETPL_LOAD','AFTER', $this, 'autotrans_editor');
    }

    public function autotrans_direct(Doku_Event $event, $param) {
        if ($this->get_mode() != 'direct') return;
        if ($event->data != 'show') return;

        if (!$this->check_do_translation()) return;

        global $ID;
        global $INFO;

        $org_page_text = $this->get_org_page_text();
        $translated_text = $this->deepl_translate($org_page_text, $this->langs[$this->get_target_lang()]);

        if ($translated_text === '') {
            return;
        }

        saveWikiText($ID, $translated_text, 'Automatic translation');

        $INFO = pageinfo();
    }

    public function autotrans_editor(Doku_Event $event, $param) {
        if ($this->get_mode() != 'editor') return;

        if (!$this->check_do_translation()) return;

        $org_page_text = $this->get_org_page_text();

        $event->data['tpl'] = $this->deepl_translate($org_page_text, $this->langs[$this->get_target_lang()]);
    }

    private function get_mode(): string {
        global $ID;
        if ($this->getConf('editor_regex')) {
            if (preg_match('/' . $this->getConf('editor_regex') . '/', $ID) === 1) return 'editor';
        }
        if ($this->getConf('direct_regex')) {
            if (preg_match('/' . $this->getConf('direct_regex') . '/', $ID) === 1) return 'direct';
        }
        return $this->getConf('mode');
    }

    private function get_target_lang(): string {
        global $ID;
        $split_id = explode(':', $ID);
        return array_shift($split_id);
    }

    private function get_org_page_text(): string {
        global $ID;

        $split_id = explode(':', $ID);
        array_shift($split_id);
        $org_id = implode(':', $split_id);

        return rawWiki($org_id);
    }

    private function check_do_translation(): bool {
        global $INFO;
        // only translate if the current page does not exist
        if ($INFO['exists']) return false;

        global $ID;

        // skip blacklisted namespaces and pages
        if ($this->getConf('blacklist_regex')) {
            if (preg_match('/' . $this->getConf('blacklist_regex') . '/', $ID) === 1) return false;
        }

        $split_id = explode(':', $ID);
        $lang_ns = array_shift($split_id);
        // only translate if the current page is in a language namespace
        if (!array_key_exists($lang_ns, $this->langs)) return false;

        $org_id = implode(':', $split_id);
        // check if the original page exists
        if (!page_exists($org_id)) return false;

        return true;
    }

    private function deepl_translate($text, $target_lang): string {
        if (!$this->getConf('api_key')) return '';

        $text = $this->insert_ignore_tags($text);

        $data = [
            'auth_key' => $this->getConf('api_key'),
            'target_lang' => $target_lang,
            'tag_handling' => 'xml',
            'ignore_tags' => 'ignore',
            'text' => $text
        ];

        if ($this->getConf('api') == 'free') {
            $url = 'https://api-free.deepl.com/v2/translate';
        } else {
            $url = 'https://api.deepl.com/v2/translate';
        }

        $http = new DokuHTTPClient();
        $raw_response = $http->post($url, $data);

        // if any error occurred return an empty string
        if ($http->status >= 400) return '';

        $json_response = json_decode($raw_response, true);
        $translated_text = $json_response['translations'][0]['text'];

        $translated_text = $this->remove_ignore_tags($translated_text);

        return $translated_text;
    }

    private function insert_ignore_tags($text): string {
        $text = str_replace('[[', '<ignore>[[', $text);
        $text = str_replace('{{', '<ignore>{{', $text);
        $text = str_replace(']]', ']]</ignore>', $text);
        $text = str_replace('}}', '}}</ignore>', $text);

        $ignored_expressions = explode(':', $this->getConf('ignored_expressions'));

        foreach ($ignored_expressions as $expression) {
            $text = str_replace($expression, '<ignore>' . $expression . '</ignore>', $text);
        }

        return $text;
    }

    private function remove_ignore_tags($text): string {
        $text = str_replace('<ignore>[[', '[[', $text);
        $text = str_replace('<ignore>{{', '{{', $text);
        $text = str_replace(']]</ignore>', ']]', $text);
        $text = str_replace('}}</ignore>', '}}', $text);

        $ignored_expressions = explode(':', $this->getConf('ignored_expressions'));

        foreach ($ignored_expressions as $expression) {
            $text = str_replace('<ignore>' . $expression . '</ignore>', $expression, $text);
        }

        return $text;
    }
}

