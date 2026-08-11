<?php

namespace Drupal\e_invoice\Service;

/**
 * Invoice get number to words.
 */
class GetNumberToWords {

  /**
   * Handle invoice.
   *
   * @param int|float|string $number
   *   Số tiền cần đọc thành chữ.
   *
   * @return string
   *   The string words.
   */
  public function handle(int|float|string $number): string {
    if (is_numeric($number)) {
      $amount = (int) round((float) $number);
    }
    else {
      $digits = preg_replace('/[^\d]/', '', (string) $number);
      $amount = $digits === '' || $digits === NULL ? 0 : (int) $digits;
    }

    if ($amount === 0) {
      return 'Không';
    }

    $formatter = new \NumberFormatter('vi', \NumberFormatter::SPELLOUT);
    $result = $formatter->format($amount);

    return $result === FALSE ? 'Không đồng.' : ucfirst($result) . ' đồng.';
  }

}
