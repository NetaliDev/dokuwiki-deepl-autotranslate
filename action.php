<?php
/**
 * Deepl Autotranslate Plugin
 *
 * @author     Jennifer Graul <me@netali.de>
 */

if(!defined('DOKU_INC')) die();

use \dokuwiki\HTTP\DokuHTTPClient;
use \dokuwiki\plugin\deeplautotranslate\MenuItem;

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
        $controller->register_hook('ACTION_ACT_PREPROCESS','BEFORE', $this, 'preprocess');
        $controller->register_hook('COMMON_PAGETPL_LOAD','AFTER', $this, 'autotrans_editor');
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'add_menu_button');
    }

    public function add_menu_button(Doku_Event $event) {
        global $ID;
        global $ACT;
        global $conf;

        if ($ACT != 'show') return;

        if ($event->data['view'] != 'page') return;

        if (!$this->getConf('show_button')) return;

        $split_id = explode(':', $ID);
        $lang_ns = array_shift($split_id);
        // check if we are in a language namespace
        if (array_key_exists($lang_ns, $this->langs)) {
            if($this->getConf('default_lang_in_ns') and $lang_ns === $conf['lang']) {
                // if the default lang is in a namespace and we are in that namespace --> check for push translation
                if (!$this->check_do_push_translate()) return;
            } else {
                // in language namespace --> check if we should translate
                if (!$this->check_do_translation(true)) return;
            }
        } else {
            // do not show the button if we are not in a language namespace and the default language is in a namespace
            if($this->getConf('default_lang_in_ns')) return;
            // not in language namespace and default language is npt in a namespace --> check if we should show the push translate button
            if (!$this->check_do_push_translate()) return;
        }

        array_splice($event->data['items'], -1, 0, [new MenuItem()]);
    }

    public function preprocess(Doku_Event  $event, $param): void {
        global $ID;
        global $conf;

        // check if action is show or translate
        if ($event->data != 'show' and $event->data != 'translate') return;

        $split_id = explode(':', $ID);
        $lang_ns = array_shift($split_id);
        // check if we are in a language namespace
        if (array_key_exists($lang_ns, $this->langs)) {
            if($this->getConf('default_lang_in_ns') and $lang_ns === $conf['lang']) {
                // if the default lang is in a namespace and we are in that namespace --> push translate
                $this->push_translate($event);
            } else {
                // in language namespace --> autotrans direct
                $this->autotrans_direct($event);
            }
        } else {
            // not in language namespace --> push translate
            $this->push_translate($event);
        }
    }

    private function autotrans_direct(Doku_Event $event): void {
        global $ID;

        // abort if action is translate and the translate button is disabled
        if ($event->data == 'translate' and !$this->getConf('show_button')) return;

        // do nothing on show action when mode is not direct
        if ($event->data == 'show' and $this->get_mode() != 'direct') return;

        // allow translation of existing pages is we are in the translate action
        $allow_existing = ($event->data == 'translate');

        // reset action to show
        $event->data = 'show';

        if (!$this->check_do_translation($allow_existing)) {
            send_redirect(wl($ID));
            return;
        }

        $org_page_text = $this->get_org_page_text();
        $translated_text = $this->deepl_translate($org_page_text, $this->langs[$this->get_target_lang()]);

        if ($translated_text === '') {
            send_redirect(wl($ID));
            return;
        }

        saveWikiText($ID, $translated_text, 'Automatic translation');

        msg($this->getLang('msg_translation_success'), 1);

        // reload the page after translation
        send_redirect(wl($ID));
    }

    public function autotrans_editor(Doku_Event $event, $param): void {
        if ($this->get_mode() != 'editor') return;

        if (!$this->check_do_translation()) return;

        $org_page_text = $this->get_org_page_text();

        $event->data['tpl'] = $this->deepl_translate($org_page_text, $this->langs[$this->get_target_lang()]);
    }

    private function push_translate(Doku_Event $event): void {
        global $ID;

        // check if action is translate
        if ($event->data != 'translate') return;

        // check if button is enabled
        if (!$this->getConf('show_button')) {
            send_redirect(wl($ID));
            return;
        }

        if (!$this->check_do_push_translate()) {
            send_redirect(wl($ID));
            return;
        }

        // push translate
        $push_langs = $this->get_push_langs();
        $org_page_text = rawWiki($ID);
        foreach ($push_langs as $lang) {
            // skip invalid languages
            if (!array_key_exists($lang, $this->langs)) {
                msg($this->getLang('msg_translation_fail_invalid_lang') . $lang, -1);
                continue;
            }

            if ($this->getConf('default_lang_in_ns')) {
                // if default lang is in ns: replace language namespace in ID
                $split_id = explode(':', $ID);
                array_shift($split_id);
                $lang_id = implode(':', $split_id);
                $lang_id = $lang . ':' . $lang_id;
            } else {
                // if default lang is not in ns: add language namespace to ID
                $lang_id = $lang . ':' . $ID;
            }

            // check permissions
            $perm = auth_quickaclcheck($ID);
            $exists = page_exists($lang_id);
            if (($exists and $perm < AUTH_EDIT) or (!$exists and $perm < AUTH_CREATE)) {
                msg($this->getLang('msg_translation_fail_no_permissions') . $lang_id, -1);
                continue;
            }

            $translated_text = $this->deepl_translate($org_page_text, $this->langs[$lang]);
            saveWikiText($lang_id, $translated_text, 'Automatic push translation');
        }

        msg($this->getLang('msg_translation_success'), 1);

        // reload the page after translation to clear the action
        send_redirect(wl($ID));
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
        global $conf;

        $split_id = explode(':', $ID);
        array_shift($split_id);
        $org_id = implode(':', $split_id);

        // if default lang is in ns: add default ns in front of org id
        if ($this->getConf('default_lang_in_ns')) {
            $org_id = $conf['lang'] . ':' . $org_id;
        }

        return rawWiki($org_id);
    }

    private function check_do_translation($allow_existing = false): bool {
        global $INFO;
        global $ID;
        global $conf;

        // only translate if the current page does not exist
        if ($INFO['exists'] and !$allow_existing) return false;

        // permission check
        $perm = auth_quickaclcheck($ID);
        if (($INFO['exists'] and $perm < AUTH_EDIT) or (!$INFO['exists'] and $perm < AUTH_CREATE)) return false;

        // skip blacklisted namespaces and pages
        if ($this->getConf('blacklist_regex')) {
            if (preg_match('/' . $this->getConf('blacklist_regex') . '/', $ID) === 1) return false;
        }

        $split_id = explode(':', $ID);
        $lang_ns = array_shift($split_id);
        // only translate if the current page is in a language namespace
        if (!array_key_exists($lang_ns, $this->langs)) return false;

        $org_id = implode(':', $split_id);

        // if default lang is in ns: add default ns in front of org id
        if ($this->getConf('default_lang_in_ns')) {
            $org_id = $conf['lang'] . ':' . $org_id;
        }

        // check if the original page exists
        if (!page_exists($org_id)) return false;

        return true;
    }

    private function check_do_push_translate(): bool {
        global $ID;
        global $INFO;
        global $conf;

        if (!$INFO['exists']) return false;

        // if default language is in namespace: only allow push translation from that namespace
        if($this->getConf('default_lang_in_ns')) {
            $split_id = explode(':', $ID);
            $lang_ns = array_shift($split_id);

            if ($lang_ns !== $conf['lang']) return false;
        }

        $push_langs = $this->get_push_langs();
        // push_langs empty --> push_translate disabled --> abort
        if (empty($push_langs)) return false;

        // skip blacklisted namespaces and pages
        if ($this->getConf('blacklist_regex')) {
            // blacklist regex match --> abort
            if (preg_match('/' . $this->getConf('blacklist_regex') . '/', $ID) === 1) return false;
        }

        return true;
    }

    private function deepl_translate($text, $target_lang): string {
        if (!trim($this->getConf('api_key'))) return '';

        $text = $this->insert_ignore_tags($text);

        $data = [
            'auth_key' => $this->getConf('api_key'),
            'target_lang' => $target_lang,
            'tag_handling' => 'xml',
            'ignore_tags' => 'ignore,php',
            'text' => $text
        ];

        if ($this->getConf('api') == 'free') {
            $url = 'https://api-free.deepl.com/v2/translate';
        } else {
            $url = 'https://api.deepl.com/v2/translate';
        }

        $http = new DokuHTTPClient();
        $raw_response = $http->post($url, $data);

        if ($http->status >= 400) {
            // add error messages
            switch ($http->status) {
                case 403:
                    msg($this->getLang('msg_translation_fail_bad_key'), -1);
                    break;
                case 456:
                    msg($this->getLang('msg_translation_fail_quota_exceeded'), -1);
                    break;
                default:
                    msg($this->getLang('msg_translation_fail'), -1);
                    break;
            }

            // if any error occurred return an empty string
            return '';
        }

        $json_response = json_decode($raw_response, true);
        $translated_text = $json_response['translations'][0]['text'];

        $translated_text = $this->remove_ignore_tags($translated_text);

        return $translated_text;
    }

    private function get_push_langs(): array {
        $push_langs = trim($this->getConf('push_langs'));

        if ($push_langs === '') return array();

        return explode(' ', $push_langs);
    }

    private function insert_ignore_tags($text): string {
        $text = str_replace('[[', '<ignore>[[', $text);
        $text = str_replace('{{', '<ignore>{{', $text);
        $text = str_replace(']]', ']]</ignore>', $text);
        $text = str_replace('}}', '}}</ignore>', $text);
        $text = str_replace("''", "<ignore>''</ignore>", $text);

        $text = preg_replace('/(<file[\s\S]*?>[\s\S]*?<\/file>)/', '<ignore>${1}</ignore>', $text);
        $text = preg_replace('/(<code[\s\S]*?>[\s\S]*?<\/code>)/', '<ignore>${1}</ignore>', $text);

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
        $text = str_replace("<ignore>''</ignore>", "''", $text);

        $text = preg_replace('/<ignore>(<file[\s\S]*?>[\s\S]*?<\/file>)<\/ignore>/', '${1}', $text);
        $text = preg_replace('/<ignore>(<code[\s\S]*?>[\s\S]*?<\/code>)<\/ignore>/', '${1}', $text);

        // restore < and > for example from arrows (-->) in wikitext
        $text = str_replace('&gt;', '>', $text);
        $text = str_replace('&lt;', '<', $text);

        $ignored_expressions = explode(':', $this->getConf('ignored_expressions'));

        foreach ($ignored_expressions as $expression) {
            $text = str_replace('<ignore>' . $expression . '</ignore>', $expression, $text);
        }

        return $text;
    }
}

