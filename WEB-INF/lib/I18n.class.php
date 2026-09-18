<?php
// +----------------------------------------------------------------------+
// | Anuko Time Tracker
// +----------------------------------------------------------------------+
// | Copyright (c) Anuko International Ltd. (https://www.anuko.com)
// +----------------------------------------------------------------------+
// | LIBERAL FREEWARE LICENSE: This source code document may be used
// | by anyone for any purpose, and freely redistributed alone or in
// | combination with other software, provided that the license is obeyed.
// |
// | There are only two ways to violate the license:
// |
// | 1. To redistribute this code in source form, with the copyright
// |    notice or license removed or altered. (Distributing in compiled
// |    forms without embedded copyright notices is permitted).
// |
// | 2. To redistribute modified versions of this code in *any* form
// |    that bears insufficient indications that the modifications are
// |    not the work of the original author(s).
// |
// | This license applies to this document only, not any other software
// | that it may be combined with.
// |
// +----------------------------------------------------------------------+
// | Contributors:
// | https://www.anuko.com/time-tracker/credits.htm
// +----------------------------------------------------------------------+

class I18n {
  var $lang = 'en'; // Language for the class.
  var $defaultLang = 'en'; // English is the default language.
  var $monthNames;
  var $weekdayNames;
  var $weekdayShortNames;
  var $keys = array(); // These are our localized strings.

  // get - obtains a localized value from $keys array.
  // Keywords can have separating dots such as in form.login.about, where each
  // part addresses one more level of nesting in $keys. Walk the array to find
  // the value. Note that a key with no dots is simply a one word walk.
  function get($key) {
    $value = $this->keys;
    foreach (explode('.', $key) as $word) {
      if (!is_array($value) || !array_key_exists($word, $value))
        return null; // No such key. keyExists() relies on this being null.
      $value = $value[$word];
    }
    return $value;
  }

  // setKey - assigns a value in the $keys array, creating the nesting levels a
  // dotted key implies. This is the write side of the notation get() reads.
  function setKey($key, $value) {
    $ref = &$this->keys;
    foreach (explode('.', $key) as $word) {
      // A shorter key may already hold a string where a longer one now needs
      // an array, as in "menu" and "menu.login". The longer key wins.
      if (!is_array($ref)) $ref = array();
      if (!array_key_exists($word, $ref)) $ref[$word] = array();
      $ref = &$ref[$word];
    }
    $ref = $value;
    unset($ref); // Break the reference, or the next assignment would follow it.
  }

  // get - keyExists determines if a key exists.
  function keyExists($key) {
    $value = $this->get($key);
    return ($value !== null);
  }

  // unescapeLangValue - removes one level of escaping from a language file value.
  //
  // Values for dotted keys in the language files are escaped twice: once for the
  // PHP parser reading the file, and once more so that the value would survive
  // being embedded, in single quotes, in the PHP source that load() used to
  // build and eval(). Only dotted keys went through that eval(), so only dotted
  // keys carry the second level. See fr, ca, et and it, which between them hold
  // 72 such values; every other language file has no apostrophes to escape.
  //
  // Nothing is eval()ed any more, so the second level is undone here instead.
  // Normalizing the language files themselves would let this go away, but that
  // is a change to translation data and belongs in its own commit.
  function unescapeLangValue($key, $value) {
    if (strpos($key, '.') === false) return $value; // Never went through eval().
    return str_replace("\\'", "'", $value);
  }

  // load - loads localized strings into $keys array by first going through the default file (en.lang.php)
  // and then through the requested language file (which is supplied as parameter),
  // (this means we end up with default English strings when keys are missing in the translation file),
  // and then from a group custom translation field, if available.
  function load($langName) {
    // Load default English keys first.
    $defaultFileName = RESOURCE_DIR . '/' . $this->defaultLang . '.lang.php';
    if (file_exists($defaultFileName)) {
      include($defaultFileName);

      $this->monthNames = $i18n_months;
      $this->weekdayNames = $i18n_weekdays;
      $this->weekdayShortNames = $i18n_weekdays_short;

      foreach ($i18n_key_words as $kword=>$value) {
        $this->setKey($kword, $this->unescapeLangValue($kword, $value));
      }
    }

    // Now load the keys from the requested file.
    // This overwrites already loaded English strings.
    $requestedFileName = strtolower($langName) . '.lang.php';
    $requestedFileName = RESOURCE_DIR . '/' . $requestedFileName;
    if (file_exists($requestedFileName) && ($langName != $this->defaultLang)) {
      require($requestedFileName);

      $this->lang = $langName;
      $this->monthNames = $i18n_months;
      $this->weekdayNames = $i18n_weekdays;
      $this->weekdayShortNames = $i18n_weekdays_short;
      foreach ($i18n_key_words as $kword=>$value) {
        if (!$value) continue;
        $this->setKey($kword, $this->unescapeLangValue($kword, $value));
      }
    }

    // Now load custom translation for group.
    global $user;
    $customTranslation = $user->getCustomTranslation();
    if ($customTranslation != null) {
      $lines =  preg_split("/\r\n|\n|\r/", $customTranslation);
      for ($i = 0; $i < count($lines); $i++) {
        $parts = explode('=', $lines[$i]);
        if (count($parts) != 2) continue;

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        // Note: the addcslashes() call that used to be here escaped quotes and
        // backslashes so that the value would survive being embedded in the PHP
        // source below. Nothing is embedded in PHP source any more, so escaping
        // here would leave the backslashes in the string the user sees.
        $value = htmlspecialchars($value);

        $this->setKey($key, $value);
      }
    }
  }

  // hasLang determines if a file for requested language exists.
  // This is a helper function for getBrowserLanguage below.
  function hasLang($lang)
  {
    $filename = RESOURCE_DIR . '/' . strtolower($lang) . '.lang.php';
    return file_exists($filename);
  }

  // getBrowserLanguage() returns a first supported language from browser settings.
  function getBrowserLanguage()
  {
    $acclang = @$_SERVER['HTTP_ACCEPT_LANGUAGE'];
    if (empty($acclang)) {
      return false;
    }
    $lang_prefs = explode(',', $acclang);
    foreach ($lang_prefs as $lang_pref) {
      $lang_pref_parts = explode(';', trim($lang_pref));
      $lang = $lang_pref_parts[0];
      if ($this->hasLang($lang)) {
        return $lang; // Return full language designation (if available), such as pt-BR.
      }

      if (strlen($lang) <= 2)
        continue; // Do not bother determining main language because we already have it.

      $lang_parts = explode('-', trim($lang));
      $lang_main = $lang_parts[0];
      if ($lang_main != $lang && $this->hasLang($lang_main)) {
        return $lang_main; // Return main language designation, such as pt.
      }
    }
    return false;
  }

  // getLangFileList() returns a list of available language files.
  static function getLangFileList() {
    $fileList = array();
    $d = @opendir(RESOURCE_DIR);
    while (($file = @readdir($d))) {
      if (($file != ".") && ($file != "..")) {
        if (strpos($file, ".lang.php")) {
          $fileList[] = @basename($file);
        }
      }
    }
    @closedir($d);
    return $fileList;
  }

  // getLangFromFilename returns language designation from a file name such as (ru, pt-br, etc.).
  static function getLangFromFilename($filename)
  {
    return substr($filename, 0, strpos($filename, '.'));
  }

  // getWeekDayName returns a localized weekday name.
  function getWeekDayName($id) {
    $id = (int) $id;
    return $this->weekdayNames[$id];
  }
}
