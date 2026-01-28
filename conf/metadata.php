<?php

$meta['api_key'] = array('string');
$meta['api'] = array('multichoice', '_choices' => array('free', 'pro'));
$meta['tag_handling_v1'] = array('onoff');
$meta['api_log_errors'] = array('onoff');
$meta['mode'] = array('multichoice', '_choices' => array('direct', 'editor'));
$meta['show_button'] = array('onoff');
$meta['push_langs'] = array('string');
$meta['glossary_ns'] = array('string');
$meta['blacklist_regex'] = array('regex');
$meta['direct_regex'] = array('regex');
$meta['editor_regex'] = array('regex');
$meta['ignored_expressions'] = array('string');
$meta['default_lang_in_ns'] = array('onoff');
$meta['keep_relative'] = array('onoff');
