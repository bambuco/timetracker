<?php
/* Copyright (c) Anuko International Ltd. https://www.anuko.com
License: See license.txt */

/**
 * Helper for ALTCHA challenge creation and solution verification (login, password reset).
 *
 * Pattern mirrors Moodle theme_bambuco (open-source, session HMAC, embedded challengejson).
 */
class ttAltchaHelper {

  /** @var string Form field name submitted by the widget. */
  const FIELD_NAME = 'altcha';

  /** @var int Default maxNumber (low difficulty). */
  const DEFAULT_MAX_NUMBER = 100000;

  /** @var string Default challenge validity (DateInterval time part). */
  const DEFAULT_VALID_TIME = '1M';

  /**
   * Whether ALTCHA is enabled in config.
   *
   * @return bool
   */
  static function isEnabled() {
    return isTrue('USE_ALTCHA');
  }

  /**
   * Load the vendored altcha-org/altcha library.
   *
   * @return void
   */
  static function requireLibrary() {
    require_once(LIBRARY_DIR.'/altcha/vendor/autoload.php');
  }

  /**
   * Ensure a per-target HMAC key exists in the session and return it.
   *
   * @param string $target Target identifier (e.g. login).
   * @return string
   */
  static function getHmacKey($target) {
    if (!isset($_SESSION['tt_altcha']) || !is_array($_SESSION['tt_altcha'])) {
      $_SESSION['tt_altcha'] = [];
    }
    if (!isset($_SESSION['tt_altcha'][$target])) {
      $_SESSION['tt_altcha'][$target] = openssl_random_pseudo_bytes(32);
    }
    return $_SESSION['tt_altcha'][$target];
  }

  /**
   * Clear the HMAC key for a target after successful verification.
   *
   * @param string $target Target identifier.
   * @return void
   */
  static function clearHmacKey($target) {
    if (isset($_SESSION['tt_altcha'][$target])) {
      unset($_SESSION['tt_altcha'][$target]);
    }
  }

  /**
   * Max number for the proof-of-work challenge.
   *
   * @return int
   */
  static function getMaxNumber() {
    if (defined('ALTCHA_MAX_NUMBER') && (int) ALTCHA_MAX_NUMBER > 0) {
      return (int) ALTCHA_MAX_NUMBER;
    }
    return self::DEFAULT_MAX_NUMBER;
  }

  /**
   * Challenge validity interval suffix (e.g. 1M, 10S) for DateInterval PT*.
   *
   * @return string
   */
  static function getValidTime() {
    if (defined('ALTCHA_VALID_TIME') && ALTCHA_VALID_TIME !== '') {
      return ALTCHA_VALID_TIME;
    }
    return self::DEFAULT_VALID_TIME;
  }

  /**
   * Build widget parameters for a form template.
   *
   * @param string $target Target identifier (e.g. login, password_reset).
   * @return array|null
   */
  static function getWidgetParams($target = 'login') {
    global $i18n;

    if (!self::isEnabled()) {
      return null;
    }

    self::requireLibrary();

    $maxNumber = self::getMaxNumber();
    $validTime = self::getValidTime();
    $altcha = new \AltchaOrg\Altcha\Altcha(self::getHmacKey($target));

    $options = new \AltchaOrg\Altcha\ChallengeOptions(
      maxNumber: $maxNumber,
      expires: (new \DateTimeImmutable())->add(new \DateInterval('PT'.$validTime)),
    );

    $strings = [
      'ariaLinkLabel' => $i18n->get('altcha.aria_link_label'),
      'error' => $i18n->get('altcha.error'),
      'expired' => $i18n->get('altcha.expired'),
      'footer' => $i18n->get('altcha.footer'),
      'label' => $i18n->get('altcha.label'),
      'verified' => $i18n->get('altcha.verified'),
      'verifying' => $i18n->get('altcha.verifying'),
      'waitAlert' => $i18n->get('altcha.wait_alert'),
    ];

    $challenge = $altcha->createChallenge($options);

    return [
      'name' => self::FIELD_NAME,
      'maxnumber' => $maxNumber,
      'challengejson' => json_encode($challenge),
      'strings' => json_encode($strings),
    ];
  }

  /**
   * Validate the ALTCHA solution from the current request.
   *
   * @param string $target Target identifier.
   * @return bool
   */
  static function validateSolution($target = 'login') {
    global $request;

    if (!isset($_SESSION['tt_altcha']) || !isset($_SESSION['tt_altcha'][$target])) {
      return false;
    }

    self::requireLibrary();

    $payloadRaw = $request->getParameter(self::FIELD_NAME);
    if ($payloadRaw === null || $payloadRaw === '') {
      return false;
    }

    $payload = (array) @json_decode(base64_decode($payloadRaw), true);
    if (empty($payload)) {
      return false;
    }

    $altcha = new \AltchaOrg\Altcha\Altcha($_SESSION['tt_altcha'][$target]);
    $ok = $altcha->verifySolution($payload, true);

    if ($ok) {
      self::clearHmacKey($target);
    }

    return $ok;
  }
}
