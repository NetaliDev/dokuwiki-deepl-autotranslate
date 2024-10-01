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
    private $langs = array(
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
        'uk' => 'UK',
        'zh' => 'ZH'
    );

    /**
     * Register its handlers with the DokuWiki's event controller
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('ACTION_ACT_PREPROCESS','BEFORE', $this, 'preprocess');
        $controller->register_hook('COMMON_PAGETPL_LOAD','AFTER', $this, 'pagetpl_load');
        $controller->register_hook('COMMON_WIKIPAGE_SAVE','AFTER', $this, 'update_glossary');
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'add_menu_button');
    }

    public function add_menu_button(Doku_Event $event): void {
        global $ID;
        global $ACT;

        if ($ACT != 'show') return;

        if ($event->data['view'] != 'page') return;

        if (!$this->getConf('show_button')) return;

        // no translations for the glossary namespace
        if ($this->check_in_glossary_ns()) return;

        $split_id = explode(':', $ID);
        $lang_ns = array_shift($split_id);
        // check if we are in a language namespace
        if (array_key_exists($lang_ns, $this->langs)) {
            if($this->getConf('default_lang_in_ns') and $lang_ns === $this->get_default_lang()) {
                // if the default lang is in a namespace and we are in that namespace --> check for push translation
                if (!$this->check_do_push_translate()) return;
            } else {
                // in language namespace --> check if we should translate
                if (!$this->check_do_translation(true)) return;
            }
        } else {
            // do not show the button if we are not in a language namespace and the default language is in a namespace
            if($this->getConf('default_lang_in_ns')) return;
            // not in language namespace and default language is not in a namespace --> check if we should show the push translate button
            if (!$this->check_do_push_translate()) return;
        }

        array_splice($event->data['items'], -1, 0, [new MenuItem()]);
    }

    public function preprocess(Doku_Event $event, $param): void {
        global $ID;

        // check if action is show or translate
        if ($event->data != 'show' and $event->data != 'translate') return;

        // redirect to glossary ns start if glossary ns is called
        if ($this->check_in_glossary_ns() and $event->data == 'show' and $ID == $this->get_glossary_ns()) {
            send_redirect(wl($this->get_glossary_ns() . ':start'));
        }

        $split_id = explode(':', $ID);
        $lang_ns = array_shift($split_id);
        // check if we are in a language namespace
        if (array_key_exists($lang_ns, $this->langs)) {
            if($this->getConf('default_lang_in_ns') and $lang_ns === $this->get_default_lang()) {
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

    public function pagetpl_load(Doku_Event $event, $param): void {
        // handle glossary namespace init when we are in it
        if ($this->check_in_glossary_ns()) {
            $this->handle_glossary_init($event);
            return;
        }

        $this->autotrans_editor($event);
    }

    public function update_glossary(Doku_Event $event, $param): void {
        global $ID;
        // this also checks if the glossary feature is enabled
        if (!$this->check_in_glossary_ns()) return;

        $glossary_ns = $this->get_glossary_ns();

        // check if we are in a glossary definition
        if(preg_match('/^' . $glossary_ns . ':(\w{2})_(\w{2})$/', $ID, $id_match)) {
            $old_glossary_id = $this->get_glossary_id($id_match[1], $id_match[2]);
            if ($event->data['changeType'] == DOKU_CHANGE_TYPE_DELETE) {
                // page deleted --> delete glossary
                if ($old_glossary_id) {
                    $result = $this->delete_glossary($old_glossary_id);
                    if ($result) {
                        msg($this->getLang('msg_glossary_delete_success'), 1);
                        $this->unset_glossary_id($id_match[1], $id_match[2]);
                    }
                }
                return;
            }

            $entries = '';

            // grep entries from definition table
            preg_match_all('/[ \t]*\|(.*?)\|(.*?)\|/', $event->data['newContent'], $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $src = trim($match[1]);
                $target = trim($match[2]);
                if ($src == '' or $target == '') {
                    msg($this->getLang('msg_glossary_empty_key'), -1);
                    return;
                }
                $entries .=  $src . "\t" . $target . "\n";
            }

            if (empty($matches)) {
                // no matches --> delete glossary
                if ($old_glossary_id) {
                    $result = $this->delete_glossary($old_glossary_id);
                    if ($result) {
                        msg($this->getLang('msg_glossary_delete_success'), 1);
                        $this->unset_glossary_id($id_match[1], $id_match[2]);
                    }
                }
                return;
            }

            $new_glossary_id = $this->create_glossary($id_match[1], $id_match[2], $entries);

            if ($new_glossary_id) {
                msg($this->getLang('msg_glossary_create_success'), 1);
                $this->set_glossary_id($id_match[1], $id_match[2], $new_glossary_id);
            } else {
                return;
            }

            if ($old_glossary_id) $this->delete_glossary($old_glossary_id);
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
            return;
        }

        $org_page_info = $this->get_org_page_info();
        $translated_text = $this->deepl_translate($org_page_info["text"], $this->get_target_lang(), $org_page_info["ns"]);

        if ($translated_text === '') {
            return;
        }

        saveWikiText($ID, $translated_text, 'Automatic translation');

        msg($this->getLang('msg_translation_success'), 1);

        // reload the page after translation
        send_redirect(wl($ID));
    }

    private function autotrans_editor(Doku_Event $event): void {
        if ($this->get_mode() != 'editor') return;

        if (!$this->check_do_translation()) return;

        $org_page_info = $this->get_org_page_info();

        $event->data['tpl'] = $this->deepl_translate($org_page_info["text"], $this->get_target_lang(), $org_page_info["ns"]);
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
            $perm = auth_quickaclcheck($lang_id);
            $exists = page_exists($lang_id);
            if (($exists and $perm < AUTH_EDIT) or (!$exists and $perm < AUTH_CREATE)) {
                msg($this->getLang('msg_translation_fail_no_permissions') . $lang_id, -1);
                continue;
            }

            $translated_text = $this->deepl_translate($org_page_text, $lang, getNS($ID));
            saveWikiText($lang_id, $translated_text, 'Automatic push translation');
        }

        msg($this->getLang('msg_translation_success'), 1);

        // reload the page after translation to clear the action
        send_redirect(wl($ID));
    }

    private function handle_glossary_init(Doku_Event $event): void {
        global $ID;

        $glossary_ns = $this->get_glossary_ns();

        // create glossary landing page
        if ($ID == $glossary_ns . ':start') {
            $landing_page_text = '====== ' . $this->getLang('glossary_landing_heading') . ' ======' . "\n";
            $landing_page_text .= $this->getLang('glossary_landing_info_msg') . "\n";

            $src_lang = substr($this->get_default_lang(), 0, 2);

            $available_glossaries = $this->get_available_glossaries();
            foreach ($available_glossaries as $glossary) {
                if ($glossary['source_lang'] != $src_lang) continue;
                // generate links to the available glossary pages
                $landing_page_text .= '  * [[.:' . $glossary['source_lang'] . '_' . $glossary['target_lang'] . '|' . strtoupper($glossary['source_lang']) . ' -> ' . strtoupper($glossary['target_lang']) . ']]' . "\n";
            }
            $event->data['tpl'] = $landing_page_text;
            return;
        }

        if (preg_match('/^' . $glossary_ns . ':(\w{2})_(\w{2})$/', $ID, $match)) {
            // check if glossaries are supported for this language pair
            if (!$this->check_glossary_supported($match[1], $match[2])) {
                msg($this->getLang('msg_glossary_unsupported'), -1);
                return;
            }

            $page_text = '====== ' . $this->getLang('glossary_definition_heading') . ': ' . strtoupper($match[1]) . ' -> ' . strtoupper($match[2]) . ' ======' . "\n";
            $page_text .= $this->getLang('glossary_definition_help') . "\n\n";
            $page_text .= '^ ' . strtoupper($match[1]) . ' ^ ' . strtoupper($match[2]) . ' ^' . "\n";

            $event->data['tpl'] = $page_text;
            return;
        }
    }

    private function get_glossary_ns(): string {
        return trim(strtolower($this->getConf('glossary_ns')));
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

    private function get_default_lang(): string {
        global $conf;

        if (empty($conf['lang_before_translation'])) {
            $default_lang = $conf['lang'];
        } else {
            $default_lang = $conf['lang_before_translation'];
        }

        return $default_lang;
    }

    private function get_org_page_info(): array {
        global $ID;

        $split_id = explode(':', $ID);
        array_shift($split_id);
        $org_id = implode(':', $split_id);

        // if default lang is in ns: add default ns in front of org id
        if ($this->getConf('default_lang_in_ns')) {
            $org_id = $this->get_default_lang() . ':' . $org_id;
        }

        return array("ns" => getNS($org_id), "text" => rawWiki($org_id));
    }

    private function get_available_glossaries(): array {
        if (!trim($this->getConf('api_key'))) {
            msg($this->getLang('msg_bad_key'), -1);
            return array();
        }

        if ($this->getConf('api') == 'free') {
            $url = 'https://api-free.deepl.com/v2/glossary-language-pairs';
        } else {
            $url = 'https://api.deepl.com/v2/glossary-language-pairs';
        }

        $http = new DokuHTTPClient();

        $http->headers = array('Authorization' => 'DeepL-Auth-Key ' . $this->getConf('api_key'));

        $raw_response = $http->get($url);

        if ($http->status >= 400) {
            // add error messages
            switch ($http->status) {
                case 403:
                    msg($this->getLang('msg_bad_key'), -1);
                    break;
                default:
                    msg($this->getLang('msg_glossary_fetch_fail'), -1);
                    break;
            }

            // if any error occurred return an empty array
            return array();
        }

        $json_response = json_decode($raw_response, true);

        return $json_response['supported_languages'];
    }

    private function get_glossary_id($src, $target): string {
        if (!file_exists(DOKU_CONF . 'deepl-glossaries.json')) return '';

        $key = $src . "_" . $target;

        $raw_json = file_get_contents(DOKU_CONF . 'deepl-glossaries.json');
        $content = json_decode($raw_json, true);

        if (array_key_exists($key, $content)) {
            return $content[$key];
        } else {
            return '';
        }
    }

    private function set_glossary_id($src, $target, $glossary_id): void {
        if (file_exists(DOKU_CONF . 'deepl-glossaries.json')) {
            $raw_json = file_get_contents(DOKU_CONF . 'deepl-glossaries.json');
            $content = json_decode($raw_json, true);
        } else {
            $content = array();
        }

        $key = $src . "_" . $target;

        $content[$key] = $glossary_id;

        $raw_json = json_encode($content);
        file_put_contents(DOKU_CONF . 'deepl-glossaries.json', $raw_json);
    }

    private function unset_glossary_id($src, $target): void {
        if (file_exists(DOKU_CONF . 'deepl-glossaries.json')) {
            $raw_json = file_get_contents(DOKU_CONF . 'deepl-glossaries.json');
            $content = json_decode($raw_json, true);
        } else {
            return;
        }

        $key = $src . "_" . $target;

        unset($content[$key]);

        $raw_json = json_encode($content);
        file_put_contents(DOKU_CONF . 'deepl-glossaries.json', $raw_json);
    }

    private function check_in_glossary_ns(): bool {
        global $ID;

        $glossary_ns = $this->get_glossary_ns();

        // check if the glossary namespace is defined
        if (!$glossary_ns) return false;

        // check if we are in the glossary namespace
        if (substr($ID, 0, strlen($glossary_ns)) == $glossary_ns) {
            return true;
        } else {
            return false;
        }
    }

    private function check_glossary_supported($src, $target): bool {
        if(strlen($src) != 2 or strlen($target) != 2) return false;
        $available_glossaries = $this->get_available_glossaries();
        foreach ($available_glossaries as $glossary) {
            if ($src == $glossary['source_lang'] and $target == $glossary['target_lang']) return true;
        }
        return false;
    }

    private function check_do_translation($allow_existing = false): bool {
        global $INFO;
        global $ID;

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
            $org_id = $this->get_default_lang() . ':' . $org_id;
        }

        // no translations for the glossary namespace
        $glossary_ns = $this->get_glossary_ns();
        if ($glossary_ns and substr($org_id, 0, strlen($glossary_ns)) == $glossary_ns) return false;

        // check if the original page exists
        if (!page_exists($org_id)) return false;

        return true;
    }

    private function check_do_push_translate(): bool {
        global $ID;
        global $INFO;

        if (!$INFO['exists']) return false;

        // only allow push translation if the user can edit this page
        $perm = auth_quickaclcheck($ID);
        if ($perm < AUTH_EDIT) return false;

        // if default language is in namespace: only allow push translation from that namespace
        if($this->getConf('default_lang_in_ns')) {
            $split_id = explode(':', $ID);
            $lang_ns = array_shift($split_id);

            if ($lang_ns !== $this->get_default_lang()) return false;
        }

        // no translations for the glossary namespace
        if ($this->check_in_glossary_ns()) return false;

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

    private function create_glossary($src, $target, $entries): string {
        if (!trim($this->getConf('api_key'))) {
            msg($this->getLang('msg_bad_key'), -1);
            return '';
        }

        if ($this->getConf('api') == 'free') {
            $url = 'https://api-free.deepl.com/v2/glossaries';
        } else {
            $url = 'https://api.deepl.com/v2/glossaries';
        }

        $data = array(
            'name' => 'DokuWiki-Autotranslate-' . $src . '_' . $target,
            'source_lang' => $src,
            'target_lang' => $target,
            'entries' => $entries,
            'entries_format' => 'tsv'
        );

        $http = new DokuHTTPClient();

        $http->headers = array('Authorization' => 'DeepL-Auth-Key ' . $this->getConf('api_key'));

        $raw_response = $http->post($url, $data);

        if ($http->status >= 400) {
            // add error messages
            switch ($http->status) {
                case 403:
                    msg($this->getLang('msg_bad_key'), -1);
                    break;
                case 400:
                    msg($this->getLang('msg_glossary_content_invalid'), -1);
                    break;
                default:
                    msg($this->getLang('msg_glossary_create_fail'), -1);
                    break;
            }

            // if any error occurred return an empty string
            return '';
        }

        $json_response = json_decode($raw_response, true);

        return $json_response['glossary_id'];
    }

    private function delete_glossary($glossary_id): bool {
        if (!trim($this->getConf('api_key'))) {
            msg($this->getLang('msg_bad_key'), -1);
            return false;
        }

        if ($this->getConf('api') == 'free') {
            $url = 'https://api-free.deepl.com/v2/glossaries';
        } else {
            $url = 'https://api.deepl.com/v2/glossaries';
        }

        $url .= '/' . $glossary_id;

        $http = new DokuHTTPClient();

        $http->headers = array('Authorization' => 'DeepL-Auth-Key ' . $this->getConf('api_key'));

        $http->sendRequest($url, '', 'DELETE');

        if ($http->status >= 400) {
            // add error messages
            switch ($http->status) {
                case 403:
                    msg($this->getLang('msg_bad_key'), -1);
                    break;
                default:
                    msg($this->getLang('msg_glossary_delete_fail'), -1);
                    break;
            }

            // if any error occurred return false
            return false;
        }

        return true;
    }

    private function deepl_translate($text, $target_lang, $org_ns): string {
        if (!trim($this->getConf('api_key'))) {
            msg($this->getLang('msg_translation_fail_bad_key'), -1);
            return '';
        }

        $text = $this->patch_links($text, $target_lang, $org_ns);

        $text = $this->insert_ignore_tags($text);

        $data = array(
            'source_lang' => strtoupper(substr($this->get_default_lang(), 0, 2)), // cut of things like "-informal"
            'target_lang' => $this->langs[$target_lang],
            'tag_handling' => 'xml',
            'ignore_tags' => 'ignore',
            'text' => $text
        );

        // check if glossaries are enabled
        if ($this->get_glossary_ns()) {
            $src = substr($this->get_default_lang(), 0, 2);
            $target = substr($target_lang, 0, 2);
            $glossary_id = $this->get_glossary_id($src, $target);
            if ($glossary_id) {
                // use glossary if it is defined
                $data['glossary_id'] = $glossary_id;
            }
        }

        if ($this->getConf('api') == 'free') {
            $url = 'https://api-free.deepl.com/v2/translate';
        } else {
            $url = 'https://api.deepl.com/v2/translate';
        }

        $http = new DokuHTTPClient();

        $http->headers = array('Authorization' => 'DeepL-Auth-Key ' . $this->getConf('api_key'));

        $raw_response = $http->post($url, $data);

        if ($http->status >= 400) {
            // add error messages
            switch ($http->status) {
                case 403:
                    msg($this->getLang('msg_translation_fail_bad_key'), -1);
                    break;
                case 404:
                    msg($this->getLang('msg_translation_fail_invalid_glossary'), -1);
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

    /**
     * Is the given ID a relative path?
     *
     * Always returns false if keep_relative is disabled.
     *
     * @param string $id
     * @return bool
     */
    private function isRelativeLink($id)
    {
        if (!$this->getConf('keep_relative')) return false;
        if ($id === '') return false;
        if (strpos($id, ':') === false) return true;
        if ($id[0] === '.') return true;
        if ($id[0] === '~') return true;
        return false;
    }

    private function patch_links($text, $target_lang, $ns): string {
        /*
         * 1. Find links in [[ aa:bb ]] or [[ aa:bb | cc ]]
         * 2. Extract aa:bb
         * 3. Check if lang:aa:bb exists
         * 3.1. --> Yes --> replace
         * 3.2. --> No --> leave it as it is
         */


        /*
         * LINKS
         */

        preg_match_all('/\[\[([\s\S]*?)(#[\s\S]*?)?((\|)([\s\S]*?))?]]/', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {

            // external link --> skip
            if (strpos($match[1], '://') !== false) continue;

            // skip interwiki links
            if (strpos($match[1], '>') !== false) continue;

            // skip mail addresses
            if (strpos($match[1], '@') !== false) continue;

            // skip windows share links
            if (strpos($match[1], '\\\\') !== false) continue;

            $resolved_id = trim($match[1]);
            if($this->isRelativeLink($resolved_id)) continue;

            resolve_pageid($ns, $resolved_id, $exists);

            $resolved_id_full = $resolved_id;

            // if the link already points to a target in a language namespace drop it and add the new language namespace
            $split_id = explode(':', $resolved_id);
            $lang_ns = array_shift($split_id);
            if (array_key_exists($lang_ns, $this->langs)) {
                $resolved_id = implode(':', $split_id);
            }

            $lang_id = $target_lang . ':' . $resolved_id;

            if (!page_exists($lang_id)) {
                // Page in target lang does not exist --> replace with absolute ID in case it was a relative ID
                $new_link = '[[' . $resolved_id_full . $match[2] . $match[3] . ']]';
            } else {
                // Page in target lang exists --> replace link
                $new_link = '[[' . $lang_id . $match[2] . $match[3] . ']]';
            }

            $text = str_replace($match[0], $new_link, $text);

        }

        /*
         * MEDIA
         */

        preg_match_all('/\{\{(([\s\S]*?)(\?[\s\S]*?)?)(\|([\s\S]*?))?}}/', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {

            // external image --> skip
            if (strpos($match[1], '://') !== false) continue;

            // skip things like {{tag>...}}
            if (strpos($match[1], '>') !== false) continue;

            // keep alignment
            $align_left = "";
            $align_right = "";

            // align left --> space in front of ID
            if (substr($match[1], 0, 1) == " ") $align_left = " ";
            // align right --> space behind id
            if (substr($match[1], -1) == " ") $align_right = " ";

            $resolved_id = trim($match[2]);
            $params = trim($match[3]);

            if($this->isRelativeLink($resolved_id)) continue;

            resolve_mediaid($ns, $resolved_id, $exists);

            $resolved_id_full = $resolved_id;

            // if the link already points to a target in a language namespace drop it and add the new language namespace
            $split_id = explode(':', $resolved_id);
            $lang_ns = array_shift($split_id);
            if (array_key_exists($lang_ns, $this->langs)) {
                $resolved_id = implode(':', $split_id);
            }

            $lang_id = $target_lang . ':' . $resolved_id;

            $lang_id_fn = mediaFN($lang_id);

            if (!file_exists($lang_id_fn)) {
                // media in target lang does not exist --> replace with absolute ID in case it was a relative ID
                $new_link = '{{' . $align_left . $resolved_id_full . $params . $align_right . $match[4] . '}}';
            } else {
                // media in target lang exists --> replace it
                $new_link = '{{' . $align_left . $lang_id . $params . $align_right . $match[4] . '}}';
            }

            $text = str_replace($match[0], $new_link, $text);

        }

        return $text;
    }

    private function insert_ignore_tags($text): string {
        // ignore every other xml-like tags (the tags themselves, not their content), otherwise deepl would break the formatting
        $text = preg_replace('/<[\s\S]+?>/', '<ignore>${0}</ignore>', $text);

        // prevent deepl from breaking headings
        $text = preg_replace('/={1,6}/', '<ignore>${0}</ignore>', $text);

        // prevent deepl from messing with nocache-instructions
        $text = str_replace("~~NOCACHE~~", "<ignore>~~NOCACHE~~</ignore>", $text);

        // fix for plugins like tag or template
        $text = preg_replace('/\{\{[\s\w]+?>[\s\S]*?}}/', '<ignore>${0}</ignore>', $text);

        // ignore links in wikitext (outside of dokuwiki-links)
        $text = preg_replace('/\S+:\/\/\S+/', '<ignore>${0}</ignore>', $text);

        // ignore link/media ids but translate the text (if existing)
        $text = preg_replace('/\[\[([\s\S]*?)(#[\s\S]*?)?((\|)([\s\S]*?))?]]/', '<ignore>[[${1}${2}${4}</ignore>${5}<ignore>]]</ignore>', $text);
        $text = preg_replace('/\{\{([\s\S]*?)(\?[\s\S]*?)?((\|)([\s\S]*?))?}}/', '<ignore>{{${1}${2}${4}</ignore>${5}<ignore>}}</ignore>', $text);

        // prevent deepl from messing with tables
        $text = str_replace("  ^  ", "<ignore>  ^  </ignore>", $text);
        $text = str_replace("  ^ ", "<ignore>  ^ </ignore>", $text);
        $text = str_replace(" ^  ", "<ignore> ^  </ignore>", $text);
        $text = str_replace("^  ", "<ignore>^  </ignore>", $text);
        $text = str_replace("  ^", "<ignore>  ^</ignore>", $text);
        $text = str_replace("^", "<ignore>^</ignore>", $text);
        $text = str_replace("  |  ", "<ignore>  |  </ignore>", $text);
        $text = str_replace("  | ", "<ignore>  | </ignore>", $text);
        $text = str_replace(" |  ", "<ignore> |  </ignore>", $text);
        $text = str_replace("|  ", "<ignore>|  </ignore>", $text);
        $text = str_replace("  |", "<ignore>  |</ignore>", $text);
        $text = str_replace("|", "<ignore>|</ignore>", $text);

        // prevent deepl from doing strange things with dokuwiki syntax
        // if a full line is formatted, we have to double-ignore for some reason
        $text = str_replace("''", "<ignore><ignore>''</ignore></ignore>", $text);
        $text = str_replace("//", "<ignore><ignore>//</ignore></ignore>", $text);
        $text = str_replace("**", "<ignore><ignore>**</ignore></ignore>", $text);
        $text = str_replace("__", "<ignore><ignore>__</ignore></ignore>", $text);
        $text = str_replace("\\\\", "<ignore><ignore>\\\\</ignore></ignore>", $text);

        // prevent deepl from messing with smileys
        $smileys = array_keys(getSmileys());
        foreach ($smileys as $smiley) {
            $text = str_replace($smiley, "<ignore>" . $smiley . "</ignore>", $text);
        }

        // ignore code tags
        $text = preg_replace('/(<php[\s\S]*?>[\s\S]*?<\/php>)/', '<ignore>${1}</ignore>', $text);
        $text = preg_replace('/(<file[\s\S]*?>[\s\S]*?<\/file>)/', '<ignore>${1}</ignore>', $text);
        $text = preg_replace('/(<code[\s\S]*?>[\s\S]*?<\/code>)/', '<ignore>${1}</ignore>', $text);

        // ignore the expressions from the ignore list
        $ignored_expressions = explode(':', $this->getConf('ignored_expressions'));

        foreach ($ignored_expressions as $expression) {
            $text = str_replace($expression, '<ignore>' . $expression . '</ignore>', $text);
        }

        return $text;
    }

    private function remove_ignore_tags($text): string {
        $ignored_expressions = explode(':', $this->getConf('ignored_expressions'));

        foreach ($ignored_expressions as $expression) {
            $text = str_replace('<ignore>' . $expression . '</ignore>', $expression, $text);
        }

        // prevent deepl from messing with nocache-instructions
        $text = str_replace("<ignore>~~NOCACHE~~</ignore>", "~~NOCACHE~~", $text);

        // prevent deepl from messing with tables
        $text = str_replace("<ignore>^</ignore>", "^", $text);
        $text = str_replace("<ignore>^  </ignore>", "^  ", $text);
        $text = str_replace("<ignore>  ^</ignore>", "  ^", $text);
        $text = str_replace("<ignore> ^  </ignore>", " ^  ", $text);
        $text = str_replace("<ignore>  ^ </ignore>", "  ^ ", $text);
        $text = str_replace("<ignore>  ^  </ignore>", "  ^  ", $text);
        $text = str_replace("<ignore>|</ignore>", "|", $text);
        $text = str_replace("<ignore>|  </ignore>", "|  ", $text);
        $text = str_replace("<ignore>  |</ignore>", "  |", $text);
        $text = str_replace("<ignore> |  </ignore>", " |  ", $text);
        $text = str_replace("<ignore>  | </ignore>", "  | ", $text);
        $text = str_replace("<ignore>  |  </ignore>", "  |  ", $text);

        $text = str_replace("<ignore><ignore>''</ignore></ignore>", "''", $text);
        $text = str_replace("<ignore><ignore>//</ignore></ignore>", "//", $text);
        $text = str_replace("<ignore><ignore>**</ignore></ignore>", "**", $text);
        $text = str_replace("<ignore><ignore>__</ignore></ignore>", "__", $text);
        $text = str_replace("<ignore><ignore>\\\\</ignore></ignore>", "\\\\", $text);

        // ignore links in wikitext (outside of dokuwiki-links)
        $text = preg_replace('/<ignore>(\S+:\/\/\S+)<\/ignore>/', '${1}', $text);

        $text = preg_replace('/<ignore>\[\[([\s\S]*?)(\|)?(<\/ignore>)([\s\S]*?)?<ignore>]]<\/ignore>/', '[[${1}${2}${4}]]', $text);
        $text = preg_replace('/<ignore>\{\{([\s\S]*?)(\|)?(<\/ignore>)([\s\S]*?)?<ignore>}}<\/ignore>/', '{{${1}${2}${4}}}', $text);

        // prevent deepl from messing with smileys
        $smileys = array_keys(getSmileys());
        foreach ($smileys as $smiley) {
            $text = str_replace("<ignore>" . $smiley . "</ignore>", $smiley, $text);
        }

        $text = preg_replace('/<ignore>(<php[\s\S]*?>[\s\S]*?<\/php>)<\/ignore>/', '${1}', $text);
        $text = preg_replace('/<ignore>(<file[\s\S]*?>[\s\S]*?<\/file>)<\/ignore>/', '${1}', $text);
        $text = preg_replace('/<ignore>(<code[\s\S]*?>[\s\S]*?<\/code>)<\/ignore>/', '${1}', $text);

        // fix for plugins like tag or template
        $text = preg_replace('/<ignore>(\{\{[\s\w]+?>[\s\S]*?}})<\/ignore>/', '${1}', $text);

        // prevent deepl from breaking headings
        $text = preg_replace('/<ignore>(={1,6})<\/ignore>/','${1}', $text);

        // ignore every other xml-like tags (the tags themselves, not their content), otherwise deepl would break the formatting
        $text = preg_replace('/<ignore>(<[\s\S]+?>)<\/ignore>/', '${1}', $text);

        // restore < and > for example from arrows (-->) in wikitext
        $text = str_replace('&gt;', '>', $text);
        $text = str_replace('&lt;', '<', $text);

        // restore & in wikitext
        $text = str_replace('&amp;', '&', $text);

        return $text;
    }
}

